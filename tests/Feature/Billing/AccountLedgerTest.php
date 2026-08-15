<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
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
 * ARDETAIL.P1 — the per-account running-balance ledger. These tests prove the FENCE: every
 * ledger figure (amount / paid / balance / running balance) is engine-computed by
 * MetricsService::accountLedger over the reconciled invoice_balances projection, the rows
 * TIE to the account's outstanding (Σ balances === account_outstanding === outstandingBalanceMinor,
 * the final running balance === that total, δ=0), the per-invoice balance IS the projection (not
 * recomputed), and the page only DISPLAYS the engine rows. No existing behaviour test is touched.
 */

function adlUser(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, catalog: TariffCatalog}
 */
function adlFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $actor = adlUser($tenant);
    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $catalog = TariffCatalog::query()->create(['key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1, 'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => []]);

    return compact('tenant', 'actor', 'branch', 'catalog');
}

function adlPatient(array $fx): Patient
{
    return app(PatientService::class)->create(['first_name' => 'Ledger', 'last_name' => 'Account', 'date_of_birth' => '1980-01-01', 'sex' => 'female']);
}

function adlInvoice(array $fx, Patient $patient, int $priceMinor, string $issueDate, string $dueDate): Invoice
{
    static $codeSeq = 70000; // deterministic-unique tariff code
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $fx['catalog']->id, 'code' => (string) (++$codeSeq), 'description' => 'Consultation',
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

function adlCollect(array $fx, Patient $patient, Invoice $invoice, int $amount): void
{
    $payments = app(PaymentService::class);
    $payment = $payments->record($amount, 'bank_transfer', $fx['actor'], $patient, null, 'EUR', now());
    $payments->allocate($payment, $invoice, $amount, $fx['actor']);
}

/**
 * One account with four invoices: fully paid (Feb), partially paid + deep overdue (Mar),
 * unpaid mild overdue (May), unpaid not-yet-due (Jul). Outstanding = 0+20000+8000+5000 = 33000.
 *
 * @return array{patient: Patient, a1: Invoice}
 */
function adlSeed(array $fx): array
{
    $p = adlPatient($fx);
    $a4 = adlInvoice($fx, $p, 6000, '2026-02-01', '2026-02-15');  // fully paid → balance 0
    adlCollect($fx, $p, $a4, 6000);
    $a1 = adlInvoice($fx, $p, 30000, '2026-03-01', '2026-03-15'); // partial → balance 20000
    adlCollect($fx, $p, $a1, 10000);
    adlInvoice($fx, $p, 8000, '2026-05-01', '2026-05-20');        // unpaid → balance 8000
    adlInvoice($fx, $p, 5000, '2026-06-10', '2026-07-10');        // not-yet-due → balance 5000

    return ['patient' => $p, 'a1' => $a1];
}

beforeEach(fn () => Carbon::setTestNow('2026-06-15 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

// ── The ledger rows carry engine figures + a running balance ─────────────────────────────────────

test('accountLedger returns the account invoices with engine amount/paid/balance/age + a running balance', function () {
    $fx = adlFixture();
    $seed = adlSeed($fx);

    $ledger = app(MetricsService::class)->accountLedger($fx['actor'], (string) $seed['patient']->id, now());

    // Ordered by issue date: Feb (paid) · Mar (partial) · May · Jul.
    expect(array_column($ledger['rows'], 'issue_date'))->toBe(['2026-02-01', '2026-03-01', '2026-05-01', '2026-06-10'])
        ->and($ledger['invoice_count'])->toBe(4);

    // Per-row figures (amount / paid / balance) + the cumulative running balance.
    expect($ledger['rows'][0])->toMatchArray(['amount_minor' => 6000, 'paid_minor' => 6000, 'balance_minor' => 0, 'status' => 'paid', 'running_balance_minor' => 0])
        ->and($ledger['rows'][1])->toMatchArray(['amount_minor' => 30000, 'paid_minor' => 10000, 'balance_minor' => 20000, 'status' => 'partially_paid', 'running_balance_minor' => 20000])
        ->and($ledger['rows'][2])->toMatchArray(['amount_minor' => 8000, 'paid_minor' => 0, 'balance_minor' => 8000, 'running_balance_minor' => 28000])
        ->and($ledger['rows'][3])->toMatchArray(['amount_minor' => 5000, 'paid_minor' => 0, 'balance_minor' => 5000, 'days_overdue' => 0, 'running_balance_minor' => 33000]);

    // The not-yet-due July invoice has age 0; the deep March invoice is 92 days overdue.
    expect($ledger['rows'][1]['days_overdue'])->toBe(92)
        ->and($ledger['rows'][3]['days_overdue'])->toBe(0);
});

// ── THE TIE (the crux): Σ rows === account outstanding === outstandingBalanceMinor, δ=0 ───────────

test('the ledger ties to the account outstanding (final running balance === total === outstandingBalanceMinor, delta=0)', function () {
    $fx = adlFixture();
    $seed = adlSeed($fx);
    $svc = app(MetricsService::class);

    $ledger = $svc->accountLedger($fx['actor'], (string) $seed['patient']->id, now());

    $sumRowBalances = array_sum(array_column($ledger['rows'], 'balance_minor'));
    $finalRunning = end($ledger['rows'])['running_balance_minor'];

    expect($ledger['account_outstanding_minor'])->toBe(33000)
        ->and($sumRowBalances)->toBe($ledger['account_outstanding_minor'])            // Σ rows === total
        ->and($finalRunning)->toBe($ledger['account_outstanding_minor'])              // final running === total
        ->and($ledger['ties'])->toBeTrue()
        // single account → the account outstanding IS the global outstandingBalanceMinor (δ=0).
        ->and($ledger['account_outstanding_minor'])->toBe($svc->outstandingBalanceMinor($fx['actor']))
        // amounts/paid also tie: total_amount − total_paid === outstanding.
        ->and($ledger['total_amount_minor'] - $ledger['total_paid_minor'])->toBe($ledger['account_outstanding_minor']);
});

// ── Per-invoice balance IS the reconciled projection (not recomputed) ─────────────────────────────

test('each ledger balance equals the reconciled invoice_balances projection', function () {
    $fx = adlFixture();
    $seed = adlSeed($fx);

    $ledger = app(MetricsService::class)->accountLedger($fx['actor'], (string) $seed['patient']->id, now());

    foreach ($ledger['rows'] as $row) {
        $projected = (int) InvoiceBalance::query()->where('invoice_id', $row['invoice_id'])->value('open_balance_minor');
        expect($row['balance_minor'])->toBe($projected);
    }
});

// ── The page DISPLAYS the engine ledger (no Vue sum) ──────────────────────────────────────────────

test('the AR Account Detail page renders the engine ledger rows and the tie', function () {
    $fx = adlFixture();
    $seed = adlSeed($fx);
    $ledger = app(MetricsService::class)->accountLedger($fx['actor'], (string) $seed['patient']->id, now());

    $this->actingAs($fx['actor'])->get(route('billing.accounts.show', $seed['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/AccountDetail')
            ->where('ledger.account_outstanding_minor', $ledger['account_outstanding_minor'])
            ->where('ledger.ties', true)
            ->where('ledger.invoice_count', 4)
            ->where('ledger.rows.3.running_balance_minor', 33000)   // final running balance === total
            ->where('ledger.rows.1.balance_minor', 20000)
            ->where('ledger.total_amount_minor', $ledger['total_amount_minor']));
});

// ── RBAC billing.view + tenant-scoped ────────────────────────────────────────────────────────────

test('the account ledger is gated on billing.view and is tenant-scoped', function () {
    $fx = adlFixture('alpha');
    $seed = adlSeed($fx);

    // A non-billing role is refused.
    $reception = adlUser($fx['tenant'], 'reception');
    $this->actingAs($reception)->get(route('billing.accounts.show', $seed['patient']->id))->assertForbidden();

    // A second tenant cannot drill into the first tenant's account (fail-closed → 404).
    $beta = adlFixture('beta');
    $this->actingAs($beta['actor'])->get(route('billing.accounts.show', $seed['patient']->id))->assertNotFound();

    // The engine method for a foreign/absent account id yields an empty, tying ledger.
    $empty = app(MetricsService::class)->accountLedger($beta['actor'], (string) $seed['patient']->id, now());
    expect($empty['rows'])->toBe([])
        ->and($empty['account_outstanding_minor'])->toBe(0)
        ->and($empty['ties'])->toBeTrue();
});
