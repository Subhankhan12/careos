<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\DunningEvent;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\DunningService;
use Modules\Billing\Services\IssueService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Modules\Reporting\Services\MetricsService;

uses(RefreshDatabase::class);

/*
 * ARDETAIL.P2 — the account's dunning timeline. These tests run the REAL dunning state machine
 * (DunningService::evaluate) to produce the events + the captured fee charges, then prove the FENCE:
 * the timeline reads the persisted append-only dunning_events (level/date === the machine's), the
 * stage === max(DunningEvent.level) (NOT an "if age > N" page label), and the dunning fee total ===
 * Σ the REAL captured fee charges (ties). Read-only: no dunning-action route is added this gate. No
 * existing behaviour test is touched.
 */

function adnUser(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, catalog: TariffCatalog}
 */
function adnFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $actor = adnUser($tenant, 'org_admin'); // holds billing.view + billing.manage (to run the machine)
    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $catalog = TariffCatalog::query()->create(['key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1, 'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => []]);

    // The dunning-fee tariff item the policy's level-1 fee_code resolves to.
    TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id, 'code' => 'DUN-FEE', 'description' => 'Dunning fee',
        'unit_price_minor' => 1500, 'vat_rate_bp' => 0, 'unit' => 'item',
        'requires_service_documentation' => false, 'active' => true,
    ]);

    return compact('tenant', 'actor', 'branch', 'catalog');
}

function adnPolicy(): void
{
    app(SettingsService::class)->set(DunningService::SETTINGS_KEY, [
        'channel' => 'email',
        'levels' => [
            ['level' => 1, 'days_past_due' => 14, 'template' => 'Erste Mahnung.', 'fee_code' => 'DUN-FEE'],
            ['level' => 2, 'days_past_due' => 30, 'template' => 'Zweite Mahnung.'], // no fee_code
        ],
    ], 'array');
}

function adnInvoice(array $fx, Patient $patient, int $priceMinor, string $issueDate, string $dueDate): Invoice
{
    static $codeSeq = 71000;
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

/**
 * Run the REAL state machine so an overdue invoice reaches level 1 (fee) + level 2 (no fee).
 *
 * @return array{patient: Patient, invoice: Invoice}
 */
function adnSeed(array $fx): array
{
    adnPolicy();
    $patient = app(PatientService::class)->create(['first_name' => 'Dunning', 'last_name' => 'Account', 'date_of_birth' => '1980-01-01', 'sex' => 'female']);
    $invoice = adnInvoice($fx, $patient, 30000, '2026-04-01', '2026-04-15'); // 61 days overdue at as-of

    // The real machine fires level 1 (+ captures the DUN-FEE charge) and level 2.
    app(DunningService::class)->evaluate($fx['tenant'], '2026-06-15', $fx['actor']);

    return ['patient' => $patient, 'invoice' => $invoice];
}

/** The level ⇒ fee_code map the controller derives from the policy. */
function adnFeeMap(): array
{
    return [1 => 'DUN-FEE'];
}

beforeEach(fn () => Carbon::setTestNow('2026-06-15 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

// ── The timeline reads the REAL append-only dunning_events ────────────────────────────────────────

test('accountDunning reads the real dunning_events (level/date/fee) ordered, with the real stage', function () {
    $fx = adnFixture();
    $seed = adnSeed($fx);

    $dunning = app(MetricsService::class)->accountDunning($fx['actor'], (string) $seed['patient']->id, adnFeeMap(), now());

    // Two real events fired by the machine: level 1 then level 2, both on the run date.
    expect(array_column($dunning['events'], 'level'))->toBe([1, 2])
        ->and(array_column($dunning['events'], 'triggered_on'))->toBe(['2026-06-15', '2026-06-15'])
        ->and($dunning['reminder_count'])->toBe(2);

    // Each event's level/date === the persisted rows (the real state machine, not a page label).
    $persisted = DunningEvent::query()->orderBy('level')->pluck('level')->all();
    expect(array_column($dunning['events'], 'level'))->toBe($persisted);

    // The per-event fee is the REAL captured charge: level 1 = 1500, level 2 (no fee_code) = 0.
    expect($dunning['events'][0]['fee_minor'])->toBe(1500)
        ->and($dunning['events'][1]['fee_minor'])->toBe(0);
});

// ── The stage === max(DunningEvent.level) (the real machine, not an "if age>N" label) ─────────────

test('the current stage equals max(DunningEvent.level), not a page-computed age label', function () {
    $fx = adnFixture();
    $seed = adnSeed($fx);

    $dunning = app(MetricsService::class)->accountDunning($fx['actor'], (string) $seed['patient']->id, adnFeeMap(), now());
    $realMax = (int) DunningEvent::query()->where('invoice_id', $seed['invoice']->id)->max('level');

    expect($dunning['current_stage'])->toBe($realMax)->toBe(2);
});

// ── THE FEE TIE: Σ per-event fees === Σ the real captured dunning-fee charges (δ=0) ───────────────

test('the dunning fee total ties to the real captured fee charges', function () {
    $fx = adnFixture();
    $seed = adnSeed($fx);

    $dunning = app(MetricsService::class)->accountDunning($fx['actor'], (string) $seed['patient']->id, adnFeeMap(), now());

    // The real recorded fees = the account's captured DUN-FEE charges (one level-1 fire → 1500).
    $recorded = (int) Charge::query()
        ->where('patient_id', $seed['patient']->id)
        ->where('code', 'DUN-FEE')
        ->where('status', '!=', Charge::STATUS_CANCELLED)
        ->sum('line_total_minor');

    expect($recorded)->toBe(1500)
        ->and($dunning['fees_minor'])->toBe($recorded)   // Σ per-event fees === recorded fees
        ->and($dunning['fees_tie'])->toBeTrue()
        ->and(array_sum(array_column($dunning['events'], 'fee_minor')))->toBe($dunning['fees_minor']);
});

// ── The page displays the engine timeline; read-only (no dunning-action route added) ─────────────

test('the AR Account Detail page renders the engine dunning timeline and adds no mutation route', function () {
    $fx = adnFixture();
    $seed = adnSeed($fx);
    $dunning = app(MetricsService::class)->accountDunning($fx['actor'], (string) $seed['patient']->id, adnFeeMap(), now());

    $this->actingAs($fx['actor'])->get(route('billing.accounts.show', $seed['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/AccountDetail')
            ->where('dunning.current_stage', 2)
            ->where('dunning.reminder_count', 2)
            ->where('dunning.fees_minor', $dunning['fees_minor'])
            ->where('dunning.fees_tie', true)
            ->where('dunning.events.0.level', 1)
            ->where('dunning.events.0.fee_minor', 1500));

    // THE DUNNING TIMELINE STAYS READ-ONLY. (Contract narrowed at ARDETAIL.P4, which added the page's
    // first — and only — write: record-payment. The invariant this test actually protects is that NO
    // DUNNING action exists on the account page: send-reminder and the Betreibung escalation are later,
    // carefully-gated gates. That still holds, and is now asserted directly rather than via "GET-only".)
    $accountRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'billing/accounts'));
    $writeRoutes = $accountRoutes->filter(fn ($r) => ! in_array('GET', $r->methods(), true) || in_array('POST', $r->methods(), true));

    expect($accountRoutes)->not->toBeEmpty()
        // No dunning/reminder/escalation action anywhere on this page.
        ->and($accountRoutes->contains(fn ($r) => (bool) preg_match('/dunning|reminder|escalat|betreibung/i', $r->uri())))->toBeFalse()
        // The writes are exactly the money/plan operator actions (P4 record-payment, P5 plan
        // lifecycle) — nothing else has appeared on this page.
        ->and($writeRoutes->map(fn ($r) => $r->uri())->sort()->values()->all())->toBe([
            'billing/accounts/{account}/payments',
            'billing/accounts/{account}/plans',
            'billing/accounts/{account}/plans/installments/{installment}/pay',
            'billing/accounts/{account}/plans/{plan}/cancel',
        ]);
});

// ── Empty state + RBAC/tenant scope ──────────────────────────────────────────────────────────────

test('an account with no dunning events shows an honest empty timeline; RBAC + tenant scope hold', function () {
    $fx = adnFixture('alpha');
    adnPolicy();
    // A patient with an invoice but NO dunning run → empty timeline.
    $quiet = app(PatientService::class)->create(['first_name' => 'Quiet', 'last_name' => 'Payer', 'date_of_birth' => '1980-01-01', 'sex' => 'male']);
    adnInvoice($fx, $quiet, 5000, '2026-06-10', '2026-07-10');

    $dunning = app(MetricsService::class)->accountDunning($fx['actor'], (string) $quiet->id, adnFeeMap(), now());
    expect($dunning['events'])->toBe([])
        ->and($dunning['current_stage'])->toBe(0)
        ->and($dunning['reminder_count'])->toBe(0)
        ->and($dunning['fees_minor'])->toBe(0)
        ->and($dunning['fees_tie'])->toBeTrue();

    // billing.view gate + tenant scope on the page.
    $reception = adnUser($fx['tenant'], 'reception');
    $this->actingAs($reception)->get(route('billing.accounts.show', $quiet->id))->assertForbidden();

    $beta = adnFixture('beta');
    $this->actingAs($beta['actor'])->get(route('billing.accounts.show', $quiet->id))->assertNotFound();
});
