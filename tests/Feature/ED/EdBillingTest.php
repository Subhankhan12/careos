<?php

use App\Services\EdDispositionService;
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
use Modules\ED\Exceptions\EdBillingException;
use Modules\ED\Models\EdTriage;
use Modules\ED\Models\EdVisit;
use Modules\ED\Services\EdBillingService;
use Modules\ED\Services\EdVisitService;
use Modules\ED\Services\TriageService;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\Ward;
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

uses(RefreshDatabase::class);

/*
 * ED.G6 — ED billing: the visit + services accrue charges through the EXISTING engine, reconciling-to-the-unit.
 * The FINAL Phase-6 gate. Per docs/HOSPITAL-PHASE6-ED-MAP.md §2.5 — STRICTLY ORCHESTRATION, no new money math.
 */

// A fixed month so the reconciliation period ties out deterministically (now() frozen in beforeEach).
const EDB_PERIOD = '2026-06';

beforeEach(fn () => Carbon::setTestNow('2026-06-15 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function edbillCtx(): TenantContext
{
    return app(TenantContext::class);
}

function edbillUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, branch: Branch, biller: User, edPhysician: User, reception: User, clinician: StaffProfile, patient: Patient} */
function edbillFixture(string $slug = 'edbill'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' ED', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    edbillCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $biller = edbillUser($tenant, 'org_admin');       // billing.manage + ed.manage + admission.manage + ward/bed.manage
    $edPhysician = edbillUser($tenant, 'ed_physician'); // ed.manage, NO billing.manage
    $reception = edbillUser($tenant, 'reception');       // no billing.manage
    $clinician = StaffProfile::query()->create([
        'first_name' => 'Ravi', 'last_name' => 'Rao', 'display_name' => 'Dr Ravi Rao',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);

    return compact('tenant', 'branch', 'biller', 'edPhysician', 'reception', 'clinician', 'patient');
}

/** Register + flow a visit to awaiting_disposition. */
function edbillAwaitingVisit(User $actor, Patient $patient, Branch $branch): EdVisit
{
    $visits = app(EdVisitService::class);
    $visit = $visits->register($actor, $patient, $branch, EdVisit::ARRIVAL_AMBULANCE, 'Chest pain');
    foreach ([EdVisit::STATUS_TRIAGED, EdVisit::STATUS_IN_TREATMENT, EdVisit::STATUS_AWAITING_DISPOSITION] as $to) {
        $visits->transition($actor, $visit, $to);
        $visit = $visit->fresh();
    }

    return $visit;
}

test('an ED attendance/service maps to a TENANT-AUTHORED TariffItem in the ed catalog (no licensed pricing)', function () {
    $fx = edbillFixture();
    $svc = app(EdBillingService::class);

    $attendance = $svc->priceAttendance($fx['biller'], 2000);
    $service = $svc->priceService($fx['biller'], 'ED-XRAY', 'Chest X-ray', 3500);

    $catalog = TariffCatalog::query()->where('key', 'ed')->firstOrFail();
    expect($attendance->code)->toBe('ED-ATTENDANCE')
        ->and($attendance->unit_price_minor)->toBe(2000)
        ->and($attendance->tariff_catalog_id)->toBe($catalog->id)
        ->and($service->code)->toBe('ED-XRAY')
        ->and($service->unit_price_minor)->toBe(3500)
        // Tenant-authored: the catalog holds ONLY what the tenant priced — nothing licensed is bundled.
        ->and(TariffItem::query()->where('tariff_catalog_id', $catalog->id)->count())->toBe(2);

    // A non-positive price is rejected (a guard, not money math).
    expect(fn () => $svc->priceAttendance($fx['biller'], 0))->toThrow(EdBillingException::class);
});

test('the visit captures charges via the EXISTING ChargeCaptureService — engine-computed + snapshotted; idempotent', function () {
    $fx = edbillFixture();
    $svc = app(EdBillingService::class);
    $svc->priceAttendance($fx['biller'], 2000);
    $svc->priceService($fx['biller'], 'ED-XRAY', 'Chest X-ray', 3500);

    $visit = app(EdVisitService::class)->register($fx['biller'], $fx['patient'], $fx['branch'], EdVisit::ARRIVAL_WALK_IN, 'Cough');
    $charges = $svc->chargeVisit($fx['biller'], $visit, true, ['ED-XRAY']);

    $att = $charges->firstWhere('code', 'ED-ATTENDANCE');
    expect($charges)->toHaveCount(2)
        ->and($att->quantity)->toBe(1)
        ->and($att->unit_price_minor)->toBe(2000)      // the SNAPSHOTTED fee
        ->and($att->line_total_minor)->toBe(2000)      // the ENGINE computed the line total (1 × 2000)
        ->and($charges->firstWhere('code', 'ED-XRAY')->line_total_minor)->toBe(3500);

    // Idempotent: a visit is billed once (the ed_visit_charges link).
    $again = $svc->chargeVisit($fx['biller'], $visit, true, ['ED-XRAY']);
    expect($again)->toHaveCount(2)
        ->and(Charge::query()->where('patient_id', $fx['patient']->id)->count())->toBe(2);
});

test('a DISCHARGED ED patient invoice (attendance + services) RECONCILES-TO-THE-UNIT (six invariants, delta = 0)', function () {
    Storage::fake('local');
    $fx = edbillFixture();
    $svc = app(EdBillingService::class);
    $svc->priceAttendance($fx['biller'], 2000);
    $svc->priceService($fx['biller'], 'ED-XRAY', 'Chest X-ray', 3500);

    $visit = edbillAwaitingVisit($fx['biller'], $fx['patient'], $fx['branch']);
    app(EdDispositionService::class)->discharge($fx['biller'], $visit, 'Home');
    $svc->chargeVisit($fx['biller'], $visit->fresh(), true, ['ED-XRAY']); // 2000 + 3500

    $invoice = $svc->invoiceVisit($fx['biller'], $visit->fresh());
    expect($invoice->status)->toBe(Invoice::STATUS_ISSUED)
        ->and($invoice->number)->not->toBeNull()
        ->and($invoice->total_minor)->toBe(5500)
        ->and(Charge::query()->where('patient_id', $fx['patient']->id)->whereNull('invoice_id')->count())->toBe(0);

    // THE KEY PROOF: the existing ReconciliationEngine ties the period to the unit WITH ED charges present.
    $report = app(ReconciliationEngine::class)->check(EDB_PERIOD);
    $i4 = collect($report['invariants'])->firstWhere('invariant', 'I4');
    expect($report['passed'])->toBeTrue()
        ->and($i4['ok'])->toBeTrue()
        ->and($i4['delta_minor'])->toBe(0);
});

test('an ADMITTED ED patient: the ED charges join the STAY discharge invoice (bed-days + ED) and reconcile-to-the-unit (the composite episode)', function () {
    Storage::fake('local');
    $fx = edbillFixture();
    $svc = app(EdBillingService::class);
    $svc->priceAttendance($fx['biller'], 2000);

    // Inpatient scaffolding (the org_admin biller holds admission/ward/bed + billing.manage).
    app(BedBillingService::class)->seedStarter($fx['biller']);
    $ward = app(WardService::class)->create($fx['biller'], $fx['branch']->id, 'Acute Ward', 'AW');
    $bed = app(BedService::class)->create($fx['biller'], $ward, '1', Bed::TYPE_GENERAL);

    // ED visit → admitted (the G5 handoff creates the Stay). Backdate admission so bed-days accrue; the ED
    // charge's service_date (dispositioned_at = now) stays inside the stay window.
    $visit = edbillAwaitingVisit($fx['biller'], $fx['patient'], $fx['branch']);
    $result = app(EdDispositionService::class)->admit($fx['biller'], $visit, $bed, $fx['clinician'], 'Admit');
    $stay = $result['stay'];
    $stay->forceFill(['admitted_at' => Carbon::parse('2026-06-12 08:00:00')])->save();

    // Charge the ED visit — a patient charge with a service_date inside the stay window (ED never imports Hospital).
    $svc->chargeVisit($fx['biller'], $visit->fresh(), true, []); // ED attendance = 2000

    // Discharge + invoice the STAY: the existing invoiceStay sweeps the bed-days AND the ED charge onto ONE invoice.
    app(AdmissionService::class)->discharge($fx['biller'], $stay->fresh(), Stay::DISPOSITION_HOME, 'stable');
    $invoice = app(BedBillingService::class)->invoiceStay($fx['biller'], $stay->fresh());

    // The ED attendance charge rode onto the stay invoice (INVOICED) alongside the bed-days; nothing dangling.
    $edCharge = Charge::query()->where('invoice_id', $invoice->id)->where('code', 'ED-ATTENDANCE')->first();
    expect($edCharge)->not->toBeNull()
        ->and($edCharge->status)->toBe(Charge::STATUS_INVOICED)
        ->and(Charge::query()->where('invoice_id', $invoice->id)->where('code', 'BED-DAY-GENERAL')->count())->toBeGreaterThan(0)
        ->and(Charge::query()->where('patient_id', $fx['patient']->id)->whereNull('invoice_id')->count())->toBe(0);

    // THE KEY PROOF (composite episode): reconcile-to-the-unit with ED charges + bed-days on ONE stay invoice.
    $report = app(ReconciliationEngine::class)->check(EDB_PERIOD);
    $i4 = collect($report['invariants'])->firstWhere('invariant', 'I4');
    expect($report['passed'])->toBeTrue()
        ->and($i4['ok'])->toBeTrue()
        ->and($i4['delta_minor'])->toBe(0);
});

test('the snapshotted fee stands: re-pricing the ED attendance later never changes a past charge', function () {
    $fx = edbillFixture();
    $svc = app(EdBillingService::class);
    $svc->priceAttendance($fx['biller'], 2000);

    $visit = app(EdVisitService::class)->register($fx['biller'], $fx['patient'], $fx['branch'], EdVisit::ARRIVAL_WALK_IN, 'Cough');
    $charge = $svc->chargeVisit($fx['biller'], $visit, true, [])->firstWhere('code', 'ED-ATTENDANCE');
    expect($charge->unit_price_minor)->toBe(2000)->and($charge->line_total_minor)->toBe(2000);

    // The tenant re-prices the attendance upward — future concern, never retroactive.
    $svc->priceAttendance($fx['biller'], 9999);
    expect(TariffItem::query()->where('code', 'ED-ATTENDANCE')->firstOrFail()->unit_price_minor)->toBe(9999)
        ->and($charge->fresh()->unit_price_minor)->toBe(2000)   // the past charge keeps the snapshot
        ->and($charge->fresh()->line_total_minor)->toBe(2000);
});

test('FENCE: the ED fee is a TARIFF, not acuity-driven; and ED computes NO billing money', function () {
    $fx = edbillFixture();
    $svc = app(EdBillingService::class);
    $svc->priceAttendance($fx['biller'], 2000);
    $nurse = StaffProfile::query()->create(['first_name' => 'Nadia', 'last_name' => 'Nurse', 'display_name' => 'Nadia Nurse', 'profession' => 'nurse', 'primary_branch_id' => $fx['branch']->id, 'status' => StaffProfile::STATUS_ACTIVE]);

    // Two visits triaged at OPPOSITE acuities — the attendance fee is the SAME tariff (2000), NOT acuity-driven.
    $v1 = app(EdVisitService::class)->register($fx['biller'], $fx['patient'], $fx['branch'], EdVisit::ARRIVAL_AMBULANCE, 'Critical');
    app(TriageService::class)->record($fx['biller'], $v1, $nurse, 'Critical', EdTriage::SCALE_ESI, '1'); // highest acuity
    $p2 = app(PatientService::class)->create(['first_name' => 'Bo', 'last_name' => 'Ben', 'date_of_birth' => '1980-01-01', 'sex' => 'male']);
    $v2 = app(EdVisitService::class)->register($fx['biller'], $p2, $fx['branch'], EdVisit::ARRIVAL_WALK_IN, 'Minor');
    app(TriageService::class)->record($fx['biller'], $v2, $nurse, 'Minor', EdTriage::SCALE_ESI, '5'); // lowest acuity

    $c1 = $svc->chargeVisit($fx['biller'], $v1->fresh(), true, [])->firstWhere('code', 'ED-ATTENDANCE');
    $c2 = $svc->chargeVisit($fx['biller'], $v2->fresh(), true, [])->firstWhere('code', 'ED-ATTENDANCE');
    expect($c1->unit_price_minor)->toBe(2000)->and($c2->unit_price_minor)->toBe(2000); // same fee regardless of acuity

    // ED computes NO billing money — line/VAT/subtotal totals + VAT rounding live ONLY in the engine.
    $forbidden = ['line_total_minor', 'vat_total_minor', 'subtotal_minor', 'vatMinor', 'intdiv('];
    $files = collect(File::allFiles(base_path('Modules/ED/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach ($files as $file) {
        foreach ($forbidden as $needle) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("ED must not compute billing money ({$needle}) — {$file->getRelativePathname()}");
        }
    }
    // The app-layer ED composer is money-math-clean too.
    foreach ($forbidden as $needle) {
        expect(str_contains(File::get(base_path('app/Services/EdDispositionService.php')), $needle))->toBeFalse("the ED composer must not compute money ({$needle})");
    }

    // The link table stores NO money; the charge/tariff path carries no acuity/severity column (fee ≠ acuity).
    foreach (['unit_price_minor', 'line_total_minor', 'amount_minor', 'acuity', 'severity'] as $word) {
        expect(Schema::getColumnListing('ed_visit_charges'))->not->toContain($word, "ed_visit_charges must not carry: {$word}");
    }
});

test('ED billing is RBAC-gated (billing.manage — the billing office, not the ED team) and tenant scoped, fail closed', function () {
    $fx = edbillFixture();
    $svc = app(EdBillingService::class);
    $svc->priceAttendance($fx['biller'], 2000);
    $visit = app(EdVisitService::class)->register($fx['biller'], $fx['patient'], $fx['branch'], EdVisit::ARRIVAL_WALK_IN, 'Cough');

    // The ED physician holds ed.manage but NOT billing.manage — the ED team bills nothing.
    expect(fn () => $svc->priceAttendance($fx['edPhysician'], 100))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->chargeVisit($fx['edPhysician'], $visit, true, []))->toThrow(AuthorizationException::class);
    // reception too.
    expect(fn () => $svc->chargeVisit($fx['reception'], $visit, true, []))->toThrow(AuthorizationException::class);

    // Cross-tenant: tenant B's biller cannot charge tenant A's visit — fail closed.
    $fxB = edbillFixture('edbillb');
    edbillCtx()->set($fxB['tenant']);
    expect(fn () => $svc->chargeVisit($fxB['biller'], $visit, true, []))->toThrow(CrossTenantReferenceException::class);
});
