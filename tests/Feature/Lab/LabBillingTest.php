<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Services\AdmissionService;
use Modules\Hospital\Services\BedBillingService;
use Modules\Hospital\Services\BedService;
use Modules\Hospital\Services\WardService;
use Modules\Lab\Exceptions\LabBillingException;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\LabTest;
use Modules\Lab\Services\LabBillingService;
use Modules\Lab\Services\LabCatalogService;
use Modules\Lab\Services\LabOrderService;
use Modules\Lab\Services\LabResultService;
use Modules\Lab\Services\SpecimenService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * LAB.G6 — lab billing: a lab order accrues its test fee through the EXISTING engine, reconciling-to-the-unit.
 * The FINAL buildable Phase-3 gate. Per docs/HOSPITAL-PHASE3-LAB-MAP.md — STRICTLY ORCHESTRATION, no new money
 * math. The fee is a tariff, NOT result-driven (the fence).
 */

// A fixed month so the reconciliation period ties out deterministically (now() frozen in beforeEach).
const LABB_PERIOD = '2026-06';

beforeEach(fn () => Carbon::setTestNow('2026-06-15 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function labbillCtx(): TenantContext
{
    return app(TenantContext::class);
}

function labbillUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, branch: Branch, biller: User, labTech: User, reception: User, clinician: StaffProfile, patient: Patient, test: LabTest} */
function labbillFixture(string $slug = 'labbill'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Lab', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    labbillCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $biller = labbillUser($tenant, 'org_admin');   // billing.manage + order.manage + lab.catalog + lab.result + admission/ward/bed.manage
    $labTech = labbillUser($tenant, 'lab_tech');   // order.manage + lab.result, NO billing.manage (the lab bench)
    $reception = labbillUser($tenant, 'reception'); // no billing.manage
    $clinician = StaffProfile::query()->create([
        'first_name' => 'Ravi', 'last_name' => 'Rao', 'display_name' => 'Dr Ravi Rao',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $test = app(LabCatalogService::class)->authorTest($biller, 'LAB-K', 'Potassium', 'Blood', 'mmol/L', '3.5–5.1');

    return compact('tenant', 'branch', 'biller', 'labTech', 'reception', 'clinician', 'patient', 'test');
}

/** Place a lab order + collect + record a result so the Order → resulted; returns the LabOrder. */
function labbillResulted(User $orderer, User $labTech, Patient $patient, LabTest $test, string $value = '4.2'): LabOrder
{
    $labOrder = app(LabOrderService::class)->place($orderer, $patient, $test, LabOrder::PRIORITY_ROUTINE)['labOrder'];
    $specimen = app(SpecimenService::class)->collect($labTech, $labOrder);
    app(LabResultService::class)->record($labTech, $specimen, ['value' => $value]);

    return $labOrder->fresh();
}

test('a lab test maps to a TENANT-AUTHORED TariffItem in the lab catalog (no licensed pricing)', function () {
    $fx = labbillFixture();
    $svc = app(LabBillingService::class);

    $item = $svc->priceTest($fx['biller'], $fx['test'], 2500);

    $catalog = TariffCatalog::query()->where('key', 'lab')->firstOrFail();
    expect($item->code)->toBe('LAB-K')                 // keyed by the LAB.G1 catalog code
        ->and($item->unit_price_minor)->toBe(2500)
        ->and($item->tariff_catalog_id)->toBe($catalog->id)
        // Tenant-authored: the catalog holds ONLY what the tenant priced — nothing licensed is bundled.
        ->and(TariffItem::query()->where('tariff_catalog_id', $catalog->id)->count())->toBe(1);

    // A non-positive price is rejected (a guard, not money math).
    expect(fn () => $svc->priceTest($fx['biller'], $fx['test'], 0))->toThrow(LabBillingException::class);
});

test('a resulted lab test captures a charge via the EXISTING ChargeCaptureService — engine-computed + snapshotted; idempotent', function () {
    $fx = labbillFixture();
    $svc = app(LabBillingService::class);
    $svc->priceTest($fx['biller'], $fx['test'], 2500);

    $labOrder = labbillResulted($fx['biller'], $fx['labTech'], $fx['patient'], $fx['test']);
    $charge = $svc->chargeOrder($fx['biller'], $labOrder);

    expect($charge->code)->toBe('LAB-K')
        ->and($charge->quantity)->toBe(1)
        ->and($charge->unit_price_minor)->toBe(2500)   // the SNAPSHOTTED fee
        ->and($charge->line_total_minor)->toBe(2500);  // the ENGINE computed the line total (1 × 2500)

    // Idempotent: a lab order is billed once (the lab_order_charges link).
    $again = $svc->chargeOrder($fx['biller'], $labOrder);
    expect($again->id)->toBe($charge->id)
        ->and(Charge::query()->where('patient_id', $fx['patient']->id)->count())->toBe(1);
});

test('an OUTPATIENT invoice with lab charges RECONCILES-TO-THE-UNIT (six invariants, delta = 0)', function () {
    Storage::fake('local');
    $fx = labbillFixture();
    $svc = app(LabBillingService::class);
    $svc->priceTest($fx['biller'], $fx['test'], 2500);

    $labOrder = labbillResulted($fx['biller'], $fx['labTech'], $fx['patient'], $fx['test']);
    $svc->chargeOrder($fx['biller'], $labOrder);

    $invoice = $svc->invoiceOrder($fx['biller'], $labOrder);
    expect($invoice->status)->toBe(Invoice::STATUS_ISSUED)
        ->and($invoice->number)->not->toBeNull()
        ->and($invoice->total_minor)->toBe(2500)
        ->and(Charge::query()->where('patient_id', $fx['patient']->id)->whereNull('invoice_id')->count())->toBe(0);

    // THE KEY PROOF: the existing ReconciliationEngine ties the period to the unit WITH lab charges present.
    $report = app(ReconciliationEngine::class)->check(LABB_PERIOD);
    $i4 = collect($report['invariants'])->firstWhere('invariant', 'I4');
    expect($report['passed'])->toBeTrue()
        ->and($i4['ok'])->toBeTrue()
        ->and($i4['delta_minor'])->toBe(0);
});

test('an INPATIENT patient: the lab charges join the STAY discharge invoice (bed-days + lab) and reconcile-to-the-unit (the composite episode)', function () {
    Storage::fake('local');
    $fx = labbillFixture();
    $svc = app(LabBillingService::class);
    $svc->priceTest($fx['biller'], $fx['test'], 2500);

    // Inpatient scaffolding (the org_admin biller holds admission/ward/bed + billing.manage).
    app(BedBillingService::class)->seedStarter($fx['biller']);
    $ward = app(WardService::class)->create($fx['biller'], $fx['branch']->id, 'Acute Ward', 'AW');
    $bed = app(BedService::class)->create($fx['biller'], $ward, '1', Bed::TYPE_GENERAL);

    // Admit + backdate so bed-days accrue; the lab charge's service_date (resulted = now) stays inside the window.
    $stay = app(AdmissionService::class)->admit($fx['biller'], $fx['patient'], $bed, $fx['clinician'], Stay::TYPE_ELECTIVE);
    $stay->forceFill(['admitted_at' => Carbon::parse('2026-06-12 08:00:00')])->save();

    // Charge the resulted lab order — a patient charge with a service_date inside the stay window.
    $labOrder = labbillResulted($fx['biller'], $fx['labTech'], $fx['patient'], $fx['test']);
    $svc->chargeOrder($fx['biller'], $labOrder);

    // Discharge + invoice the STAY: the existing invoiceStay sweeps the bed-days AND the lab charge onto ONE invoice.
    app(AdmissionService::class)->discharge($fx['biller'], $stay->fresh(), Stay::DISPOSITION_HOME, 'stable');
    $invoice = app(BedBillingService::class)->invoiceStay($fx['biller'], $stay->fresh());

    // The lab charge rode onto the stay invoice (INVOICED) alongside the bed-days; nothing dangling.
    $labCharge = Charge::query()->where('invoice_id', $invoice->id)->where('code', 'LAB-K')->first();
    expect($labCharge)->not->toBeNull()
        ->and($labCharge->status)->toBe(Charge::STATUS_INVOICED)
        ->and(Charge::query()->where('invoice_id', $invoice->id)->where('code', 'BED-DAY-GENERAL')->count())->toBeGreaterThan(0)
        ->and(Charge::query()->where('patient_id', $fx['patient']->id)->whereNull('invoice_id')->count())->toBe(0);

    // THE KEY PROOF (composite episode): reconcile-to-the-unit with lab charges + bed-days on ONE stay invoice.
    $report = app(ReconciliationEngine::class)->check(LABB_PERIOD);
    $i4 = collect($report['invariants'])->firstWhere('invariant', 'I4');
    expect($report['passed'])->toBeTrue()
        ->and($i4['ok'])->toBeTrue()
        ->and($i4['delta_minor'])->toBe(0);
});

test('the snapshotted fee stands: re-pricing the lab test later never changes a past charge', function () {
    $fx = labbillFixture();
    $svc = app(LabBillingService::class);
    $svc->priceTest($fx['biller'], $fx['test'], 2500);

    $labOrder = labbillResulted($fx['biller'], $fx['labTech'], $fx['patient'], $fx['test']);
    $charge = $svc->chargeOrder($fx['biller'], $labOrder);
    expect($charge->unit_price_minor)->toBe(2500)->and($charge->line_total_minor)->toBe(2500);

    // The tenant re-prices the test upward — future concern, never retroactive.
    $svc->priceTest($fx['biller'], $fx['test'], 9999);
    expect(TariffItem::query()->where('code', 'LAB-K')->firstOrFail()->unit_price_minor)->toBe(9999)
        ->and($charge->fresh()->unit_price_minor)->toBe(2500)   // the past charge keeps the snapshot
        ->and($charge->fresh()->line_total_minor)->toBe(2500);
});

test('FENCE: the lab fee is a TARIFF, not result-driven; and Lab computes NO billing money', function () {
    $fx = labbillFixture();
    $svc = app(LabBillingService::class);
    $svc->priceTest($fx['biller'], $fx['test'], 2500);

    // Two orders for the SAME test with OPPOSITE result values — the fee is the SAME tariff (2500), NOT result-driven.
    $normal = labbillResulted($fx['biller'], $fx['labTech'], $fx['patient'], $fx['test'], '4.2');   // in range
    $p2 = app(PatientService::class)->create(['first_name' => 'Bo', 'last_name' => 'Ben', 'date_of_birth' => '1980-01-01', 'sex' => 'male']);
    $extreme = labbillResulted($fx['biller'], $fx['labTech'], $p2, $fx['test'], '9.9');             // wildly out of range

    $c1 = $svc->chargeOrder($fx['biller'], $normal);
    $c2 = $svc->chargeOrder($fx['biller'], $extreme);
    expect($c1->unit_price_minor)->toBe(2500)->and($c2->unit_price_minor)->toBe(2500); // same fee regardless of result

    // Lab computes NO billing money — line/VAT/subtotal totals + VAT rounding live ONLY in the engine.
    $forbidden = ['line_total_minor', 'vat_total_minor', 'subtotal_minor', 'vatMinor', 'intdiv('];
    $files = collect(File::allFiles(base_path('Modules/Lab/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach ($files as $file) {
        foreach ($forbidden as $needle) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Lab must not compute billing money ({$needle}) — {$file->getRelativePathname()}");
        }
    }

    // The link table stores NO money; the charge/tariff path carries no result/severity column (fee ≠ result).
    foreach (['unit_price_minor', 'line_total_minor', 'amount_minor', 'result_value', 'severity', 'abnormal'] as $word) {
        expect(Schema::getColumnListing('lab_order_charges'))->not->toContain($word, "lab_order_charges must not carry: {$word}");
    }
});

test('lab billing is RBAC-gated (billing.manage — the billing office, not the lab bench) and tenant scoped, fail closed', function () {
    $fx = labbillFixture();
    $svc = app(LabBillingService::class);
    $svc->priceTest($fx['biller'], $fx['test'], 2500);
    $labOrder = labbillResulted($fx['biller'], $fx['labTech'], $fx['patient'], $fx['test']);

    // The lab tech holds order.manage + lab.result but NOT billing.manage — the lab bench bills nothing.
    expect(fn () => $svc->priceTest($fx['labTech'], $fx['test'], 100))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->chargeOrder($fx['labTech'], $labOrder))->toThrow(AuthorizationException::class);
    // reception too.
    expect(fn () => $svc->chargeOrder($fx['reception'], $labOrder))->toThrow(AuthorizationException::class);

    // Cross-tenant: tenant B's biller cannot charge tenant A's lab order — fail closed.
    $fxB = labbillFixture('labbillb');
    labbillCtx()->set($fxB['tenant']);
    expect(fn () => $svc->chargeOrder($fxB['biller'], $labOrder))->toThrow(CrossTenantReferenceException::class);
});
