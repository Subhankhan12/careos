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
use Modules\Radiology\Exceptions\RadiologyBillingException;
use Modules\Radiology\Models\RadiologyExam;
use Modules\Radiology\Models\RadiologyOrder;
use Modules\Radiology\Services\RadiologyBillingService;
use Modules\Radiology\Services\RadiologyCatalogService;
use Modules\Radiology\Services\RadiologyOrderService;

uses(RefreshDatabase::class);

/*
 * RAD.G5 — radiology billing: an imaging order accrues its exam fee through the EXISTING engine, reconciling-to-
 * the-unit. The LAST buildable Phase-4 gate. Per docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md — STRICTLY ORCHESTRATION,
 * no new money math. The fee is a tariff, NOT report-driven (the fence).
 */

const RADB_PERIOD = '2026-06';

beforeEach(fn () => Carbon::setTestNow('2026-06-15 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function radbCtx(): TenantContext
{
    return app(TenantContext::class);
}

function radbUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, branch: Branch, biller: User, radiographer: User, reception: User, clinician: StaffProfile, patient: Patient, exam: RadiologyExam, order: RadiologyOrder} */
function radbFixture(string $slug = 'radb'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Imaging', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    radbCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $biller = radbUser($tenant, 'org_admin');       // billing.manage + radiology.catalog + order.manage + admission/ward/bed.manage
    $radiographer = radbUser($tenant, 'radiographer'); // order.manage + radiology.study, NO billing.manage
    $reception = radbUser($tenant, 'reception');       // no billing.manage
    $clinician = StaffProfile::query()->create([
        'first_name' => 'Ravi', 'last_name' => 'Rao', 'display_name' => 'Dr Ravi Rao',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $exam = app(RadiologyCatalogService::class)->authorExam($biller, 'RAD-CXR', 'Chest X-ray', 'X-ray', 'Chest', false);
    $order = app(RadiologyOrderService::class)->place($biller, $patient, $exam, RadiologyOrder::PRIORITY_ROUTINE)['radiologyOrder'];

    return compact('tenant', 'branch', 'biller', 'radiographer', 'reception', 'clinician', 'patient', 'exam', 'order');
}

test('an imaging exam maps to a TENANT-AUTHORED TariffItem in the radiology catalog (no licensed pricing)', function () {
    $fx = radbFixture();
    $svc = app(RadiologyBillingService::class);

    $item = $svc->priceExam($fx['biller'], $fx['exam'], 4500);

    $catalog = TariffCatalog::query()->where('key', 'radiology')->firstOrFail();
    expect($item->code)->toBe('RAD-CXR')
        ->and($item->unit_price_minor)->toBe(4500)
        ->and($item->tariff_catalog_id)->toBe($catalog->id)
        ->and(TariffItem::query()->where('tariff_catalog_id', $catalog->id)->count())->toBe(1);

    expect(fn () => $svc->priceExam($fx['biller'], $fx['exam'], 0))->toThrow(RadiologyBillingException::class);
});

test('an imaging order captures a charge via the EXISTING ChargeCaptureService — engine-computed + snapshotted; idempotent', function () {
    $fx = radbFixture();
    $svc = app(RadiologyBillingService::class);
    $svc->priceExam($fx['biller'], $fx['exam'], 4500);

    $charge = $svc->chargeOrder($fx['biller'], $fx['order']);

    expect($charge->code)->toBe('RAD-CXR')
        ->and($charge->quantity)->toBe(1)
        ->and($charge->unit_price_minor)->toBe(4500)   // the SNAPSHOTTED fee
        ->and($charge->line_total_minor)->toBe(4500);  // the ENGINE computed the line total (1 × 4500)

    // Idempotent: an imaging order is billed once (the radiology_order_charges link).
    $again = $svc->chargeOrder($fx['biller'], $fx['order']);
    expect($again->id)->toBe($charge->id)
        ->and(Charge::query()->where('patient_id', $fx['patient']->id)->count())->toBe(1);
});

test('an OUTPATIENT invoice with imaging charges RECONCILES-TO-THE-UNIT (six invariants, delta = 0)', function () {
    Storage::fake('local');
    $fx = radbFixture();
    $svc = app(RadiologyBillingService::class);
    $svc->priceExam($fx['biller'], $fx['exam'], 4500);
    $svc->chargeOrder($fx['biller'], $fx['order']);

    $invoice = $svc->invoiceOrder($fx['biller'], $fx['order']);
    expect($invoice->status)->toBe(Invoice::STATUS_ISSUED)
        ->and($invoice->number)->not->toBeNull()
        ->and($invoice->total_minor)->toBe(4500)
        ->and(Charge::query()->where('patient_id', $fx['patient']->id)->whereNull('invoice_id')->count())->toBe(0);

    // THE KEY PROOF: the existing ReconciliationEngine ties the period to the unit WITH imaging charges present.
    $report = app(ReconciliationEngine::class)->check(RADB_PERIOD);
    $i4 = collect($report['invariants'])->firstWhere('invariant', 'I4');
    expect($report['passed'])->toBeTrue()
        ->and($i4['ok'])->toBeTrue()
        ->and($i4['delta_minor'])->toBe(0);
});

test('an INPATIENT patient: the imaging charges join the STAY discharge invoice (bed-days + imaging) and reconcile-to-the-unit (the composite episode)', function () {
    Storage::fake('local');
    $fx = radbFixture();
    $svc = app(RadiologyBillingService::class);
    $svc->priceExam($fx['biller'], $fx['exam'], 4500);

    // Inpatient scaffolding (the org_admin biller holds admission/ward/bed + billing.manage).
    app(BedBillingService::class)->seedStarter($fx['biller']);
    $ward = app(WardService::class)->create($fx['biller'], $fx['branch']->id, 'Acute Ward', 'AW');
    $bed = app(BedService::class)->create($fx['biller'], $ward, '1', Bed::TYPE_GENERAL);

    // Admit + backdate so bed-days accrue; the imaging charge's service_date (the order date = now) stays inside.
    $stay = app(AdmissionService::class)->admit($fx['biller'], $fx['patient'], $bed, $fx['clinician'], Stay::TYPE_ELECTIVE);
    $stay->forceFill(['admitted_at' => Carbon::parse('2026-06-12 08:00:00')])->save();

    // Charge the imaging order — a patient charge with a service_date inside the stay window.
    $svc->chargeOrder($fx['biller'], $fx['order']);

    // Discharge + invoice the STAY: the existing invoiceStay sweeps the bed-days AND the imaging charge onto ONE invoice.
    app(AdmissionService::class)->discharge($fx['biller'], $stay->fresh(), Stay::DISPOSITION_HOME, 'stable');
    $invoice = app(BedBillingService::class)->invoiceStay($fx['biller'], $stay->fresh());

    $radCharge = Charge::query()->where('invoice_id', $invoice->id)->where('code', 'RAD-CXR')->first();
    expect($radCharge)->not->toBeNull()
        ->and($radCharge->status)->toBe(Charge::STATUS_INVOICED)
        ->and(Charge::query()->where('invoice_id', $invoice->id)->where('code', 'BED-DAY-GENERAL')->count())->toBeGreaterThan(0)
        ->and(Charge::query()->where('patient_id', $fx['patient']->id)->whereNull('invoice_id')->count())->toBe(0);

    // THE KEY PROOF (composite episode): reconcile-to-the-unit with imaging charges + bed-days on ONE stay invoice.
    $report = app(ReconciliationEngine::class)->check(RADB_PERIOD);
    $i4 = collect($report['invariants'])->firstWhere('invariant', 'I4');
    expect($report['passed'])->toBeTrue()
        ->and($i4['ok'])->toBeTrue()
        ->and($i4['delta_minor'])->toBe(0);
});

test('the snapshotted fee stands: re-pricing the exam later never changes a past charge', function () {
    $fx = radbFixture();
    $svc = app(RadiologyBillingService::class);
    $svc->priceExam($fx['biller'], $fx['exam'], 4500);

    $charge = $svc->chargeOrder($fx['biller'], $fx['order']);
    expect($charge->unit_price_minor)->toBe(4500)->and($charge->line_total_minor)->toBe(4500);

    $svc->priceExam($fx['biller'], $fx['exam'], 9999);
    expect(TariffItem::query()->where('code', 'RAD-CXR')->firstOrFail()->unit_price_minor)->toBe(9999)
        ->and($charge->fresh()->unit_price_minor)->toBe(4500)   // the past charge keeps the snapshot
        ->and($charge->fresh()->line_total_minor)->toBe(4500);
});

test('FENCE: the exam fee is a TARIFF, not report-driven; and Radiology computes NO billing money', function () {
    $fx = radbFixture();
    $svc = app(RadiologyBillingService::class);
    $svc->priceExam($fx['biller'], $fx['exam'], 4500);

    // Two orders for the SAME exam get the SAME tariff fee (4500), regardless of any report — NOT report-driven.
    $c1 = $svc->chargeOrder($fx['biller'], $fx['order']);
    $order2 = app(RadiologyOrderService::class)->place($fx['biller'], $fx['patient'], $fx['exam'], RadiologyOrder::PRIORITY_STAT)['radiologyOrder'];
    $c2 = $svc->chargeOrder($fx['biller'], $order2);
    expect($c1->unit_price_minor)->toBe(4500)->and($c2->unit_price_minor)->toBe(4500); // same fee (STAT priority does not change it)

    // Radiology computes NO billing money — line/VAT/subtotal totals + VAT rounding live ONLY in the engine.
    $forbidden = ['line_total_minor', 'vat_total_minor', 'subtotal_minor', 'vatMinor', 'intdiv('];
    $files = collect(File::allFiles(base_path('Modules/Radiology/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach ($files as $file) {
        foreach ($forbidden as $needle) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Radiology must not compute billing money ({$needle}) — {$file->getRelativePathname()}");
        }
    }

    // The link table stores NO money; the charge/tariff path carries no report/finding/severity column (fee ≠ report).
    foreach (['unit_price_minor', 'line_total_minor', 'amount_minor', 'finding', 'severity', 'abnormal'] as $word) {
        expect(Schema::getColumnListing('radiology_order_charges'))->not->toContain($word, "radiology_order_charges must not carry: {$word}");
    }
});

test('radiology billing is RBAC-gated (billing.manage — the billing office, not the radiology bench) and tenant scoped, fail closed', function () {
    $fx = radbFixture();
    $svc = app(RadiologyBillingService::class);
    $svc->priceExam($fx['biller'], $fx['exam'], 4500);

    // The radiographer holds order.manage + radiology.study but NOT billing.manage — the bench bills nothing.
    expect(fn () => $svc->priceExam($fx['radiographer'], $fx['exam'], 100))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->chargeOrder($fx['radiographer'], $fx['order']))->toThrow(AuthorizationException::class);
    // reception too.
    expect(fn () => $svc->chargeOrder($fx['reception'], $fx['order']))->toThrow(AuthorizationException::class);

    // Cross-tenant: tenant B's biller cannot charge tenant A's imaging order — fail closed.
    $fxB = radbFixture('radbb');
    radbCtx()->set($fxB['tenant']);
    expect(fn () => $svc->chargeOrder($fxB['biller'], $fx['order']))->toThrow(CrossTenantReferenceException::class);
});
