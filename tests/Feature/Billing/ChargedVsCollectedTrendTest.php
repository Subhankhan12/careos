<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Billing\Models\Charge;
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
 * BILLAR.P5 — the charged-vs-collected TREND as a MetricsService engine time-series, bucketed over a
 * range and using the SAME shared helpers as the roll-forward/DSO/rate/by-payer. THE PARTITION: the
 * buckets tile the range exactly, so Σ buckets === the range totals (δ=0), and an empty bucket shows 0.
 * These tests ADD coverage; no existing behaviour test is modified.
 */

function cvcUser(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, catalog: TariffCatalog}
 */
function cvcFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $actor = cvcUser($tenant);
    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $patient = app(PatientService::class)->create(['first_name' => 'Trend', 'last_name' => 'Line', 'date_of_birth' => '1988-01-01', 'sex' => 'female']);
    $catalog = TariffCatalog::query()->create(['key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1, 'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => []]);

    return compact('tenant', 'actor', 'branch', 'patient', 'catalog');
}

function cvcInvoice(array $fx, int $priceMinor, string $issueDate): Invoice
{
    static $codeSeq = 43000; // deterministic-unique tariff code (random_int(10,99) collides across a test)
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $fx['catalog']->id, 'code' => (string) (++$codeSeq), 'description' => 'Consultation',
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

/** Allocate `$amount` from a fresh payment to `$invoice`, dated at the frozen "now". */
function cvcCollect(array $fx, Invoice $invoice, int $amount): void
{
    $payments = app(PaymentService::class);
    $payment = $payments->record($amount, 'bank_transfer', $fx['actor'], $fx['patient'], null, 'EUR', now());
    $payments->allocate($payment, $invoice, $amount, $fx['actor']);
}

function cvcBucket(array $result, string $label): ?array
{
    foreach ($result['buckets'] as $bucket) {
        if ($bucket['label'] === $label) {
            return $bucket;
        }
    }

    return null;
}

afterEach(fn () => Carbon::setTestNow());

// ── Ordered monthly buckets; per-bucket charges/collections; an empty bucket shows 0 ─────────────

test('the trend returns ordered monthly buckets with per-bucket charges/collections (empty bucket shows 0)', function () {
    $fx = cvcFixture();

    Carbon::setTestNow('2026-06-15 12:00:00');
    $june = cvcInvoice($fx, 20000, '2026-06-05'); // June charges 20000
    cvcCollect($fx, $june, 5000);                 // June collections 5000
    // July: nothing at all → an EMPTY bucket.
    Carbon::setTestNow('2026-08-15 12:00:00');
    $aug = cvcInvoice($fx, 30000, '2026-08-10');  // August charges 30000
    cvcCollect($fx, $aug, 8000);                  // August collections 8000

    $trend = app(MetricsService::class)->chargedVsCollectedTrend($fx['actor'], '2026-06-01', '2026-08-31', 'month');

    // Three ordered buckets, the empty July bucket present with zeros (never dropped).
    expect(array_column($trend['buckets'], 'label'))->toBe(['2026-06', '2026-07', '2026-08'])
        ->and(cvcBucket($trend, '2026-06'))->toMatchArray(['charges_minor' => 20000, 'collections_minor' => 5000])
        ->and(cvcBucket($trend, '2026-07'))->toMatchArray(['charges_minor' => 0, 'collections_minor' => 0])
        ->and(cvcBucket($trend, '2026-08'))->toMatchArray(['charges_minor' => 30000, 'collections_minor' => 8000]);
});

// ── THE PARTITION: Σ buckets === the range totals (δ=0), consistent with the shared helpers ──────

test('the buckets partition the range — Sigma buckets equals the range totals (delta=0)', function () {
    $fx = cvcFixture();
    Carbon::setTestNow('2026-06-15 12:00:00');
    $june = cvcInvoice($fx, 20000, '2026-06-05');
    cvcCollect($fx, $june, 5000);
    Carbon::setTestNow('2026-08-15 12:00:00');
    $aug = cvcInvoice($fx, 30000, '2026-08-10');
    cvcCollect($fx, $aug, 8000);

    $svc = app(MetricsService::class);
    $trend = $svc->chargedVsCollectedTrend($fx['actor'], '2026-06-01', '2026-08-31', 'month');

    // Σ buckets === the range totals, exactly.
    expect(array_sum(array_column($trend['buckets'], 'charges_minor')))->toBe($trend['total_charges_minor'])
        ->and(array_sum(array_column($trend['buckets'], 'collections_minor')))->toBe($trend['total_collections_minor'])
        ->and($trend['total_charges_minor'])->toBe(50000)
        ->and($trend['total_collections_minor'])->toBe(13000)
        ->and($trend['partitions'])->toBeTrue();

    // ONE definition of the figures: the trend totals equal the roll-forward's charges/collections for
    // the same range (both use the shared helpers — no fourth definition of "charges"/"collections").
    $roll = $svc->arRollForward($fx['actor'], '2026-06-01', '2026-08-31');
    expect($trend['total_charges_minor'])->toBe($roll['charges_minor'])
        ->and($trend['total_collections_minor'])->toBe($roll['collections_minor']);
});

// ── Clean bucket boundaries: a charge on the edge lands in exactly one bucket ─────────────────────

test('a charge on a bucket boundary lands in exactly one bucket (clean partition)', function () {
    $fx = cvcFixture();
    Carbon::setTestNow('2026-07-15 12:00:00');
    cvcInvoice($fx, 10000, '2026-06-30'); // last day of June
    cvcInvoice($fx, 20000, '2026-07-01'); // first day of July

    $trend = app(MetricsService::class)->chargedVsCollectedTrend($fx['actor'], '2026-06-01', '2026-07-31', 'month');

    expect(cvcBucket($trend, '2026-06')['charges_minor'])->toBe(10000) // June 30 → June only
        ->and(cvcBucket($trend, '2026-07')['charges_minor'])->toBe(20000) // July 1 → July only
        ->and($trend['partitions'])->toBeTrue();
});

// ── Weekly buckets also tile the range exactly ───────────────────────────────────────────────────

test('weekly buckets also partition the range (Sigma buckets equals the totals)', function () {
    $fx = cvcFixture();
    Carbon::setTestNow('2026-06-20 12:00:00');
    $inv = cvcInvoice($fx, 14000, '2026-06-03'); // week 1
    cvcCollect($fx, $inv, 6000);

    $trend = app(MetricsService::class)->chargedVsCollectedTrend($fx['actor'], '2026-06-01', '2026-06-28', 'week');

    // 2026-06-01..06-28 = four 7-day windows; the series is ordered and tiles the range.
    expect($trend['buckets'])->toHaveCount(4)
        ->and(array_sum(array_column($trend['buckets'], 'charges_minor')))->toBe($trend['total_charges_minor'])
        ->and(array_sum(array_column($trend['buckets'], 'collections_minor')))->toBe($trend['total_collections_minor'])
        ->and($trend['partitions'])->toBeTrue();
});

// ── billing.view + tenant-scoped ─────────────────────────────────────────────────────────────────

test('the trend is gated on billing.view and is tenant-scoped', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');
    $fx = cvcFixture('alpha');
    cvcInvoice($fx, 20000, '2026-06-05');
    $svc = app(MetricsService::class);

    $reception = cvcUser($fx['tenant'], 'reception'); // no billing.view
    expect(fn () => $svc->chargedVsCollectedTrend($reception, '2026-06-01', '2026-06-30', 'month'))->toThrow(AuthorizationException::class);

    // A second tenant sees an all-zero trend that still partitions.
    $beta = cvcFixture('beta');
    $trend = $svc->chargedVsCollectedTrend($beta['actor'], '2026-06-01', '2026-06-30', 'month');
    expect($trend['total_charges_minor'])->toBe(0)
        ->and($trend['total_collections_minor'])->toBe(0)
        ->and($trend['partitions'])->toBeTrue();
});
