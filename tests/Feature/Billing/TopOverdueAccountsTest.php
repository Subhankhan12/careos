<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\DunningEvent;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PaymentService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Reporting\Services\MetricsService;

uses(RefreshDatabase::class);

/*
 * BILLAR.P7 — the top-overdue table + account rollup + the drill to AR Account Detail.
 * These tests prove the FENCE: the age is engine-computed (due-date vs as-of), the balance
 * is the reconciled projection, the dunning STAGE is the REAL persisted state-machine level
 * (max DunningEvent.level — NOT a page label), the account rollup TIES to its invoices (δ=0)
 * and the grand total ties to overdueBalanceMinor, ordering is most-overdue-first, and each
 * row drills to the account's AR detail route. No existing behaviour test is touched.
 */

function tovUser(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, catalog: TariffCatalog}
 */
function tovFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $actor = tovUser($tenant);
    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $catalog = TariffCatalog::query()->create(['key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1, 'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => []]);

    return compact('tenant', 'actor', 'branch', 'catalog');
}

function tovPatient(array $fx, string $first, string $last): Patient
{
    return app(PatientService::class)->create(['first_name' => $first, 'last_name' => $last, 'date_of_birth' => '1980-01-01', 'sex' => 'female']);
}

/** A collision-free tariff code (a monotonic counter — 4 invoices per test would otherwise clash). */
function tovCode(): string
{
    static $n = 6000;

    return (string) (++$n);
}

function tovInvoice(array $fx, Patient $patient, int $priceMinor, string $issueDate, string $dueDate): Invoice
{
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $fx['catalog']->id, 'code' => tovCode(), 'description' => 'Consultation',
        'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);
    $charge = Charge::query()->create([
        'patient_id' => $patient->id, 'branch_id' => $fx['branch']->id, 'service_date' => $issueDate,
        'tariff_catalog_id' => $fx['catalog']->id, 'tariff_item_id' => $item->id, 'code' => $item->code,
        'description' => $item->description, 'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0,
        'quantity' => 1, 'line_total_minor' => $priceMinor, 'status' => Charge::STATUS_VALIDATED, 'created_by' => $fx['actor']->id,
    ]);
    $service = app(IssueService::class);

    return $service->issue(
        $service->createDraftFromCharges($patient, [$charge], $fx['actor'], Invoice::PAYER_SELF_PAY, null, Carbon::parse($issueDate), Carbon::parse($dueDate)),
        $fx['actor'],
    );
}

function tovCollect(array $fx, Patient $patient, Invoice $invoice, int $amount): void
{
    $payments = app(PaymentService::class);
    $payment = $payments->record($amount, 'bank_transfer', $fx['actor'], $patient, null, 'EUR', now());
    $payments->allocate($payment, $invoice, $amount, $fx['actor']);
}

/** Persist a dunning event (the real state machine's output) at a given level. */
function tovDunning(Invoice $invoice, int $level): void
{
    DunningEvent::query()->create([
        'invoice_id' => $invoice->id,
        'level' => $level,
        'triggered_on' => now(),
        'status' => DunningEvent::STATUS_SENT,
    ]);
}

/**
 * Seed two overdue accounts + one not-yet-due invoice. Returns the two patients.
 *
 * @return array{a: Patient, b: Patient, a1: Invoice}
 */
function tovSeed(array $fx): array
{
    $a = tovPatient($fx, 'Alice', 'Aankia');
    $b = tovPatient($fx, 'Bob', 'Boskovic');

    // Account A: two overdue invoices — one deep (92d, partially paid → 20000, dunning level 2),
    // one mild (26d, 8000, no dunning).
    $a1 = tovInvoice($fx, $a, 30000, '2026-03-01', '2026-03-15');
    tovCollect($fx, $a, $a1, 10000);
    tovDunning($a1, 1);
    tovDunning($a1, 2);
    $a2 = tovInvoice($fx, $a, 8000, '2026-05-01', '2026-05-20');

    // Account B: one overdue invoice (10d, 15000, dunning level 1) + one NOT-yet-due (excluded).
    $b1 = tovInvoice($fx, $b, 15000, '2026-06-01', '2026-06-05');
    tovDunning($b1, 1);
    tovInvoice($fx, $b, 5000, '2026-06-10', '2026-07-01'); // future due date → not overdue

    return ['a' => $a, 'b' => $b, 'a1' => $a1];
}

beforeEach(fn () => Carbon::setTestNow('2026-06-15 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

// ── The engine method: engine age/balance + REAL dunning stage; rollup ties δ=0; ordered ─────────

test('topOverdueAccounts returns engine ages/balances + the real dunning stage, rolled up and tying', function () {
    $fx = tovFixture();
    $seed = tovSeed($fx);
    $svc = app(MetricsService::class);

    $result = $svc->topOverdueAccounts($fx['actor'], now(), 10);

    // Ordered most-overdue first: Account A (92d) before Account B (10d).
    expect(array_column($result['accounts'], 'patient_id'))->toBe([(string) $seed['a']->id, (string) $seed['b']->id]);

    $accA = $result['accounts'][0];
    $accB = $result['accounts'][1];

    // Account A: rollup = 20000 + 8000; max age 92d; REAL max dunning stage = 2 (the persisted events).
    expect($accA)->toMatchArray(['total_overdue_minor' => 28000, 'invoice_count' => 2, 'max_days_overdue' => 92, 'max_stage' => 2, 'ties' => true])
        ->and($accB)->toMatchArray(['total_overdue_minor' => 15000, 'invoice_count' => 1, 'max_days_overdue' => 10, 'max_stage' => 1, 'ties' => true]);

    // The deep A1 invoice carries balance 20000 (the reconciled projection) + its real stage 2.
    $a1Row = collect($accA['invoices'])->firstWhere('open_balance_minor', 20000);
    expect($a1Row)->not->toBeNull()
        ->and($a1Row['stage'])->toBe(2)
        ->and($a1Row['days_overdue'])->toBe(92);

    // THE TIE: Σ each account's invoices === the account rollup; Σ all accounts === grand total
    // === overdueBalanceMinor (δ=0 — the same population + day math as aging).
    expect($result['grand_total_overdue_minor'])->toBe(43000)
        ->and($result['ties'])->toBeTrue()
        ->and($result['grand_total_overdue_minor'])->toBe($svc->overdueBalanceMinor($fx['actor'], now()))
        ->and(array_sum(array_column($result['accounts'], 'total_overdue_minor')))->toBe($result['grand_total_overdue_minor']);
});

// ── The dunning stage is the REAL state-machine stage, not a page label ───────────────────────────

test('the dunning stage equals the persisted DunningEvent max level (the real state machine)', function () {
    $fx = tovFixture();
    $seed = tovSeed($fx);

    $engineStage = app(MetricsService::class)->topOverdueAccounts($fx['actor'], now(), 10)['accounts'][0]['max_stage'];
    $realMax = (int) DunningEvent::query()->where('invoice_id', $seed['a1']->id)->max('level');

    expect($engineStage)->toBe($realMax)->toBe(2);
});

// ── The report grid carries the top-overdue table with names + drill URLs ─────────────────────────

test('the report page includes the top-overdue accounts with resolved names and drill URLs', function () {
    $fx = tovFixture();
    $seed = tovSeed($fx);
    $svc = app(MetricsService::class);
    $engine = $svc->topOverdueAccounts($fx['actor'], now(), 10);

    $this->actingAs($fx['actor'])->get(route('billing.report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Report')
            ->where('topOverdue.grand_total_overdue_minor', $engine['grand_total_overdue_minor'])
            ->where('topOverdue.ties', true)
            ->where('topOverdue.accounts.0.patient_id', (string) $seed['a']->id)
            ->where('topOverdue.accounts.0.patient_name', 'Alice Aankia')
            ->where('topOverdue.accounts.0.total_overdue_minor', 28000)
            ->where('topOverdue.accounts.0.max_stage', 2)
            ->where('topOverdue.accounts.0.detail_url', route('billing.accounts.show', $seed['a']->id)));
});

// ── The drill target renders AR Account Detail with the account's engine overdue figures ──────────

test('the drill route renders AR Account Detail with the account engine figures', function () {
    $fx = tovFixture();
    $seed = tovSeed($fx);

    $this->actingAs($fx['actor'])->get(route('billing.accounts.show', $seed['a']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/AccountDetail')
            ->where('account.id', (string) $seed['a']->id)
            ->where('account.name', 'Alice Aankia')
            ->where('overdue.total_overdue_minor', 28000)
            ->where('overdue.invoice_count', 2)
            ->where('overdue.max_stage', 2)
            ->where('overdue.ties', true));
});

// ── RBAC billing.view + tenant-scoped ────────────────────────────────────────────────────────────

test('the top-overdue table + drill are gated on billing.view and are tenant-scoped', function () {
    $fx = tovFixture('alpha');
    $seed = tovSeed($fx);

    // A non-billing role is refused on the report and the drill.
    $reception = tovUser($fx['tenant'], 'reception');
    $this->actingAs($reception)->get(route('billing.report'))->assertForbidden();
    $this->actingAs($reception)->get(route('billing.accounts.show', $seed['a']->id))->assertForbidden();

    // A second tenant cannot drill into the first tenant's account (fail-closed → 404).
    $beta = tovFixture('beta');
    $this->actingAs($beta['actor'])->get(route('billing.accounts.show', $seed['a']->id))->assertNotFound();

    // …and the second tenant's own report shows no overdue accounts.
    $this->actingAs($beta['actor'])->get(route('billing.report'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('topOverdue.accounts', [])
            ->where('topOverdue.grand_total_overdue_minor', 0)
            ->where('topOverdue.ties', true));
});
