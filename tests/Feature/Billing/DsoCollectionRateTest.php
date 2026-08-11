<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\AdjustmentService;
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
 * BILLAR.P3 — DSO + net collection rate as ENGINE methods computed from real figures (the reconciled
 * projection + the P1 adjustments + the P2 charges/collections definitions), honest definitions, precise
 * arithmetic, zero/edge cases → honest null ("—"). No page-side math. These tests ADD coverage; no
 * existing behaviour test is modified.
 */

function ncrUser(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, catalog: TariffCatalog}
 */
function ncrFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $actor = ncrUser($tenant);
    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $patient = app(PatientService::class)->create(['first_name' => 'Metric', 'last_name' => 'Case', 'date_of_birth' => '1988-01-01', 'sex' => 'female']);
    $catalog = TariffCatalog::query()->create(['key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1, 'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => []]);

    return compact('tenant', 'actor', 'branch', 'patient', 'catalog');
}

function ncrIssuedInvoice(array $fx, int $priceMinor, string $issueDate): Invoice
{
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $fx['catalog']->id, 'code' => '40'.random_int(10, 99), 'description' => 'Consultation',
        'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);
    $charge = Charge::query()->create([
        'patient_id' => $fx['patient']->id, 'branch_id' => $fx['branch']->id, 'service_date' => $issueDate,
        'tariff_catalog_id' => $fx['catalog']->id, 'tariff_item_id' => $item->id, 'code' => $item->code,
        'description' => $item->description, 'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0,
        'quantity' => 1, 'line_total_minor' => $priceMinor, 'status' => Charge::STATUS_VALIDATED, 'created_by' => $fx['actor']->id,
    ]);
    $service = app(IssueService::class);

    return $service->issue(
        $service->createDraftFromCharges($fx['patient'], [$charge], $fx['actor'], Invoice::PAYER_SELF_PAY, null, Carbon::parse($issueDate), Carbon::parse($issueDate)->addDays(14)),
        $fx['actor'],
    );
}

afterEach(fn () => Carbon::setTestNow());

// ── DSO computes from real figures with the stated definition ────────────────────────────────────

test('DSO = (AR / credit sales) x days, from real engine figures', function () {
    Carbon::setTestNow('2026-06-30 12:00:00');
    $fx = ncrFixture();
    $invoice = ncrIssuedInvoice($fx, 40000, '2026-06-05'); // credit sales in June = 40000
    $payments = app(PaymentService::class);
    $payment = $payments->record(10000, 'bank_transfer', $fx['actor'], $fx['patient'], null, 'EUR', now());
    $payments->allocate($payment, $invoice, 10000, $fx['actor']); // AR now = 30000

    $dso = app(MetricsService::class)->daysSalesOutstanding($fx['actor'], '2026-06-01', '2026-06-30');

    // AR 30000 / credit sales 40000 × 30 days = 22.5, from real integer-minor figures.
    expect($dso['ar_minor'])->toBe(30000)
        ->and($dso['credit_sales_minor'])->toBe(40000)
        ->and($dso['days'])->toBe(30)
        ->and($dso['dso'])->toBe(22.5);
});

// ── A zero-sales period has an UNDEFINED DSO (honest null, no divide-by-zero) ─────────────────────

test('a period with no credit sales returns a null DSO (honest, no divide-by-zero)', function () {
    Carbon::setTestNow('2026-06-30 12:00:00');
    $fx = ncrFixture();
    ncrIssuedInvoice($fx, 30000, '2026-05-10'); // issued BEFORE the period → AR but no June sales

    $dso = app(MetricsService::class)->daysSalesOutstanding($fx['actor'], '2026-06-01', '2026-06-30');

    expect($dso['credit_sales_minor'])->toBe(0)
        ->and($dso['ar_minor'])->toBe(30000) // the real components are still shown honestly
        ->and($dso['dso'])->toBeNull();       // "—", never 0 or ∞
});

// ── Net collection rate = collections / (charges − contractual adjustments) ───────────────────────

test('net collection rate = collections / collectible, from real figures', function () {
    Carbon::setTestNow('2026-06-30 12:00:00');
    $fx = ncrFixture();
    $invoice = ncrIssuedInvoice($fx, 30000, '2026-06-05'); // charges = 30000
    $payments = app(PaymentService::class);
    $payment = $payments->record(20000, 'card', $fx['actor'], $fx['patient'], null, 'EUR', now());
    $payments->allocate($payment, $invoice, 20000, $fx['actor']); // collections = 20000
    app(AdjustmentService::class)->contractualAdjustment($invoice, 5000, 'Insurer-agreed rate.', 'AGR-1', $fx['actor']); // contractual = 5000

    $ncr = app(MetricsService::class)->netCollectionRate($fx['actor'], '2026-06-01', '2026-06-30');

    // collectible = 30000 − 5000 = 25000; rate = 20000 / 25000 = 0.8.
    expect($ncr['collections_minor'])->toBe(20000)
        ->and($ncr['charges_minor'])->toBe(30000)
        ->and($ncr['contractual_adjustments_minor'])->toBe(5000)
        ->and($ncr['collectible_minor'])->toBe(25000)
        ->and($ncr['rate'])->toBe(0.8);
});

// ── Collectible is DERIVED (a contractual adjustment reduces it; a write-off does NOT) ────────────

test('collectible is derived from real figures — a contractual adjustment reduces it, a write-off does not', function () {
    Carbon::setTestNow('2026-06-30 12:00:00');
    $fx = ncrFixture();
    $invoice = ncrIssuedInvoice($fx, 30000, '2026-06-05');
    $svc = app(MetricsService::class);

    // No adjustment yet → collectible == charges (30000).
    expect($svc->netCollectionRate($fx['actor'], '2026-06-01', '2026-06-30')['collectible_minor'])->toBe(30000);

    // A contractual adjustment REDUCES collectible by exactly its amount (derived, not fabricated).
    app(AdjustmentService::class)->contractualAdjustment($invoice, 8000, 'Insurer rate.', null, $fx['actor']);
    expect($svc->netCollectionRate($fx['actor'], '2026-06-01', '2026-06-30')['collectible_minor'])->toBe(22000);

    // A WRITE-OFF does NOT change collectible (uncollected-but-was-collectible — the standard definition).
    app(AdjustmentService::class)->writeOff($invoice, 3000, 'Bad debt.', $fx['actor']);
    expect($svc->netCollectionRate($fx['actor'], '2026-06-01', '2026-06-30')['collectible_minor'])->toBe(22000);
});

// ── A zero-collectible period returns a null rate (honest "—") ────────────────────────────────────

test('a period with no collectible returns a null rate (honest, no divide-by-zero)', function () {
    Carbon::setTestNow('2026-06-30 12:00:00');
    $fx = ncrFixture();
    $invoice = ncrIssuedInvoice($fx, 5000, '2026-06-05');
    app(AdjustmentService::class)->contractualAdjustment($invoice, 5000, 'Full insurer write-down.', null, $fx['actor']); // collectible → 0

    $ncr = app(MetricsService::class)->netCollectionRate($fx['actor'], '2026-06-01', '2026-06-30');

    expect($ncr['collectible_minor'])->toBe(0)
        ->and($ncr['rate'])->toBeNull(); // "—", never a fabricated value
});

// ── billing.view + tenant-scoped ─────────────────────────────────────────────────────────────────

test('DSO and the collection rate are gated on billing.view and are tenant-scoped', function () {
    Carbon::setTestNow('2026-06-30 12:00:00');
    $fx = ncrFixture('alpha');
    ncrIssuedInvoice($fx, 40000, '2026-06-05');
    $svc = app(MetricsService::class);

    $reception = ncrUser($fx['tenant'], 'reception'); // no billing.view
    expect(fn () => $svc->daysSalesOutstanding($reception, '2026-06-01', '2026-06-30'))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->netCollectionRate($reception, '2026-06-01', '2026-06-30'))->toThrow(AuthorizationException::class);

    // A second tenant sees none of alpha's figures.
    $beta = ncrFixture('beta');
    expect($svc->daysSalesOutstanding($beta['actor'], '2026-06-01', '2026-06-30')['credit_sales_minor'])->toBe(0);
    expect($svc->daysSalesOutstanding($beta['actor'], '2026-06-01', '2026-06-30')['dso'])->toBeNull();
    expect($svc->netCollectionRate($beta['actor'], '2026-06-01', '2026-06-30')['rate'])->toBeNull();
});
