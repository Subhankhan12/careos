<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
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
 * BILLAR.P4 — AR / collections / charges split BY PAYER, grouped over the REAL invoices.payer_type
 * dimension (self-pay vs. private insurance), as a MetricsService engine aggregate. THE TIE: the split
 * is a real partition — every issued invoice has exactly one payer_type, so the groups SUM to the
 * overall totals (δ=0). No fabricated payer categories. These tests ADD coverage; no existing behaviour
 * test is modified.
 */

function bpUser(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, catalog: TariffCatalog}
 */
function bpFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $actor = bpUser($tenant);
    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $patient = app(PatientService::class)->create(['first_name' => 'Payer', 'last_name' => 'Split', 'date_of_birth' => '1988-01-01', 'sex' => 'female']);
    $catalog = TariffCatalog::query()->create(['key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1, 'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => []]);

    return compact('tenant', 'actor', 'branch', 'patient', 'catalog');
}

/** Issue an invoice of `$priceMinor` for a given real payer_type. */
function bpInvoice(array $fx, int $priceMinor, string $payerType, string $issueDate = '2026-06-05'): Invoice
{
    static $codeSeq = 41000; // deterministic-unique tariff code (random_int(10,99) collides across a test)
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
        $service->createDraftFromCharges($fx['patient'], [$charge], $fx['actor'], $payerType, $payerType === Invoice::PAYER_PRIVATE_INSURANCE ? 'Helsana' : null, Carbon::parse($issueDate), Carbon::parse($issueDate)->addDays(14)),
        $fx['actor'],
    );
}

/** Find one payer group by payer_type. */
function bpGroup(array $result, string $payerType): ?array
{
    foreach ($result['groups'] as $group) {
        if ($group['payer_type'] === $payerType) {
            return $group;
        }
    }

    return null;
}

afterEach(fn () => Carbon::setTestNow());

// ── Groups AR / collections / charges by the real payer_type and TIES to the totals ─────────────

test('by-payer groups AR/collections/charges by the real payer_type and sum to the totals (delta=0)', function () {
    Carbon::setTestNow('2026-06-30 12:00:00');
    $fx = bpFixture();
    $self = bpInvoice($fx, 20000, Invoice::PAYER_SELF_PAY);
    $ins = bpInvoice($fx, 30000, Invoice::PAYER_PRIVATE_INSURANCE);

    $payments = app(PaymentService::class);
    $p1 = $payments->record(5000, 'cash', $fx['actor'], $fx['patient'], null, 'EUR', now());
    $payments->allocate($p1, $self, 5000, $fx['actor']);   // self-pay collections 5000
    $p2 = $payments->record(10000, 'bank_transfer', $fx['actor'], $fx['patient'], null, 'EUR', now());
    $payments->allocate($p2, $ins, 10000, $fx['actor']);   // insurance collections 10000

    $result = app(MetricsService::class)->arByPayer($fx['actor'], '2026-06-01', '2026-06-30');

    $selfGroup = bpGroup($result, Invoice::PAYER_SELF_PAY);
    $insGroup = bpGroup($result, Invoice::PAYER_PRIVATE_INSURANCE);

    expect($selfGroup)->toMatchArray(['ar_minor' => 15000, 'collections_minor' => 5000, 'charges_minor' => 20000])
        ->and($insGroup)->toMatchArray(['ar_minor' => 20000, 'collections_minor' => 10000, 'charges_minor' => 30000]);

    // THE TIE: the groups sum to the overall totals, exactly.
    expect($result['total_ar_minor'])->toBe(35000)
        ->and($result['total_collections_minor'])->toBe(15000)
        ->and($result['total_charges_minor'])->toBe(50000)
        ->and($result['ties'])->toBeTrue();
});

// ── THE TIE holds through a P1 write-off (the partition stays exact) ──────────────────────────────

test('the by-payer partition still ties after a write-off (no drift, no double-count)', function () {
    Carbon::setTestNow('2026-06-30 12:00:00');
    $fx = bpFixture();
    $self = bpInvoice($fx, 20000, Invoice::PAYER_SELF_PAY);
    bpInvoice($fx, 30000, Invoice::PAYER_PRIVATE_INSURANCE);
    app(AdjustmentService::class)->writeOff($self, 8000, 'Bad debt.', $fx['actor']); // reduces self-pay AR

    $result = app(MetricsService::class)->arByPayer($fx['actor'], '2026-06-01', '2026-06-30');

    // self-pay AR dropped by the write-off; the groups STILL sum to the (reduced) total.
    expect(bpGroup($result, Invoice::PAYER_SELF_PAY)['ar_minor'])->toBe(12000)
        ->and(array_sum(array_column($result['groups'], 'ar_minor')))->toBe($result['total_ar_minor'])
        ->and($result['total_ar_minor'])->toBe(42000) // 12000 + 30000
        ->and($result['ties'])->toBeTrue();
});

// ── Only the REAL payer types appear — no fabricated Swiss taxonomy ──────────────────────────────

test('only the real payer types are grouped — no fabricated payer categories', function () {
    Carbon::setTestNow('2026-06-30 12:00:00');
    $fx = bpFixture();
    bpInvoice($fx, 20000, Invoice::PAYER_SELF_PAY);
    bpInvoice($fx, 30000, Invoice::PAYER_PRIVATE_INSURANCE);

    $result = app(MetricsService::class)->arByPayer($fx['actor'], '2026-06-01', '2026-06-30');

    $payerTypes = array_column($result['groups'], 'payer_type');
    // exactly the two modeled payer types — NOT the wireframe's supplementary/accident/social invented split.
    expect($payerTypes)->toEqualCanonicalizing([Invoice::PAYER_SELF_PAY, Invoice::PAYER_PRIVATE_INSURANCE])
        ->and($payerTypes)->not->toContain('accident')
        ->and($payerTypes)->not->toContain('social');
});

// ── An unexpected payer_type gets its OWN group, never dropped (exhaustive partition) ─────────────

test('an unexpected payer_type forms its own group and is not dropped (the partition stays exhaustive)', function () {
    Carbon::setTestNow('2026-06-30 12:00:00');
    $fx = bpFixture();
    bpInvoice($fx, 20000, Invoice::PAYER_SELF_PAY);

    // Inject an issued invoice carrying a payer_type outside the known enum (bypassing the service guard),
    // plus its projection row — a real invoice the by-payer split must still account for.
    $inv = new Invoice;
    $inv->forceFill([
        'tenant_id' => $fx['tenant']->id, 'patient_id' => $fx['patient']->id, 'payer_type' => 'foundation',
        'number' => 'INJ-1', 'series' => Invoice::SERIES_INVOICE, 'status' => Invoice::STATUS_ISSUED,
        'issue_date' => '2026-06-05', 'due_date' => '2026-06-19', 'currency' => 'EUR',
        'subtotal_minor' => 8000, 'vat_total_minor' => 0, 'total_minor' => 8000, 'open_balance_minor' => 8000,
    ])->save();
    (new InvoiceBalance)->forceFill([
        'tenant_id' => $fx['tenant']->id, 'invoice_id' => $inv->id, 'open_balance_minor' => 8000, 'status' => Invoice::STATUS_ISSUED,
    ])->save();

    $result = app(MetricsService::class)->arByPayer($fx['actor'], '2026-06-01', '2026-06-30');

    // The unexpected payer is its OWN group (labelled by its raw value), and the totals still tie.
    expect(bpGroup($result, 'foundation'))->not->toBeNull()
        ->and(bpGroup($result, 'foundation')['ar_minor'])->toBe(8000)
        ->and($result['total_ar_minor'])->toBe(28000) // 20000 self-pay + 8000 foundation
        ->and(array_sum(array_column($result['groups'], 'ar_minor')))->toBe($result['total_ar_minor'])
        ->and($result['ties'])->toBeTrue();
});

// ── billing.view + tenant-scoped ─────────────────────────────────────────────────────────────────

test('the by-payer aggregate is gated on billing.view and is tenant-scoped', function () {
    Carbon::setTestNow('2026-06-30 12:00:00');
    $fx = bpFixture('alpha');
    bpInvoice($fx, 20000, Invoice::PAYER_SELF_PAY);
    $svc = app(MetricsService::class);

    $reception = bpUser($fx['tenant'], 'reception'); // no billing.view
    expect(fn () => $svc->arByPayer($reception, '2026-06-01', '2026-06-30'))->toThrow(AuthorizationException::class);

    // A second tenant sees none of alpha's payers.
    $beta = bpFixture('beta');
    $result = $svc->arByPayer($beta['actor'], '2026-06-01', '2026-06-30');
    expect($result['groups'])->toBe([])
        ->and($result['total_ar_minor'])->toBe(0)
        ->and($result['ties'])->toBeTrue();
});
