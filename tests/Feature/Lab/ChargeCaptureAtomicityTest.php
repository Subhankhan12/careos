<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Exceptions\TariffNotFoundForDateException;
use Modules\Billing\Models\Charge;
use Modules\ED\Models\EdVisit;
use Modules\ED\Models\EdVisitCharge;
use Modules\ED\Services\EdBillingService;
use Modules\ED\Services\EdVisitService;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\LabOrderCharge;
use Modules\Lab\Models\LabTest;
use Modules\Lab\Services\LabBillingService;
use Modules\Lab\Services\LabCatalogService;
use Modules\Lab\Services\LabOrderService;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Radiology\Models\RadiologyExam;
use Modules\Radiology\Models\RadiologyOrder;
use Modules\Radiology\Models\RadiologyOrderCharge;
use Modules\Radiology\Services\RadiologyBillingService;
use Modules\Radiology\Services\RadiologyCatalogService;
use Modules\Radiology\Services\RadiologyOrderService;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| QA-FIX.8c — P8-H2 (+ P7-M5): charge capture is atomic with its link rows
|--------------------------------------------------------------------------
| THREE SERVICES, ZERO `DB::transaction` BETWEEN THEM. `ChargeCaptureService::
| capture()` commits in its own transaction, and the LINK table is the
| idempotency key — so a failure between the capture and the link left a Charge
| the guard could not see, and a retry re-captured what had already succeeded.
| This is the shape Phase 3 predicted would generalise: P3-C1 → P4-H2 → P6-M10
| → P7-M5 → P8-H2 (twice).
|
| ED IS INCLUDED DELIBERATELY, AND IT WAS THE WORST OF THE THREE. `chargeVisit`
| captured EVERY charge into a collection first and wrote the link rows in a
| SEPARATE LATER LOOP, so one failure orphaned all of them — the exact P6-M10
| shape. Lab and Radiology capture one charge and link it one statement later: a
| narrower window, but the same hole and the same concurrency gap.
|
| ED IS ALSO THE ONLY ONE THAT CAN BE DEMONSTRATED. Because it captures MORE
| THAN ONE charge, a mid-capture failure is reachable by pricing the attendance
| but not the service code — the QA-FIX.6a method. Lab and Radiology capture
| exactly one charge per order, so there is no "partway" to fail at without
| mocking the link write; their atomicity is held by the structural guard below
| and by the identical remedy, and that limitation is stated rather than papered
| over.
*/

/** @return array{tenant: Tenant, biller: User, labOrder: LabOrder, radOrder: RadiologyOrder, visit: EdVisit} */
function ccaFixture(string $slug): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $biller = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $biller->id, 'role_id' => Role::where('key', 'org_admin')->firstOrFail()->id]);

    StaffProfile::query()->create([
        'first_name' => 'Ravi', 'last_name' => 'Rao', 'display_name' => 'Dr Ravi Rao',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);

    $test = app(LabCatalogService::class)->authorTest($biller, 'LAB-K', 'Potassium', 'Blood', 'mmol/L', '3.5–5.1');
    $labOrder = app(LabOrderService::class)->place($biller, $patient, $test, LabOrder::PRIORITY_ROUTINE)['labOrder'];

    $exam = app(RadiologyCatalogService::class)->authorExam($biller, 'RAD-CXR', 'Chest X-ray', 'Röntgen', 'Thorax');
    $radOrder = app(RadiologyOrderService::class)->place($biller, $patient, $exam, RadiologyOrder::PRIORITY_ROUTINE)['radiologyOrder'];

    $visits = app(EdVisitService::class);
    $visit = $visits->register($biller, $patient, $branch, EdVisit::ARRIVAL_AMBULANCE, 'Chest pain');
    foreach ([EdVisit::STATUS_TRIAGED, EdVisit::STATUS_IN_TREATMENT, EdVisit::STATUS_AWAITING_DISPOSITION] as $to) {
        $visits->transition($biller, $visit, $to);
        $visit = $visit->fresh();
    }

    return compact('tenant', 'biller', 'labOrder', 'radOrder', 'visit');
}

// ------------------------------ ED — THE ONE THAT CAN BE DEMONSTRATED LIVE ----

test('P7-M5: an ED capture that fails partway leaves NOTHING behind — no orphan charge, no link', function () {
    $fx = ccaFixture('cca-ed-atomic');
    $svc = app(EdBillingService::class);

    // Price the ATTENDANCE but NOT the service code, then ask for both. Capture #1 succeeds; capture #2
    // raises TariffNotFoundForDateException. Before the fix, #1 had already committed on its own.
    $svc->priceAttendance($fx['biller'], 2000);

    expect(fn () => $svc->chargeVisit($fx['biller'], $fx['visit']->fresh(), true, ['ED-XRAY']))
        ->toThrow(TariffNotFoundForDateException::class);

    // THE PROPERTY (D-182 — this FAILS before the fix, where it found 1 charge and 0 links).
    expect(Charge::query()->count())->toBe(0)
        ->and(EdVisitCharge::query()->count())->toBe(0);
});

test('P7-M5: after a failed ED capture, a RETRY bills the visit exactly once — no double-billing', function () {
    $fx = ccaFixture('cca-ed-retry');
    $svc = app(EdBillingService::class);

    $svc->priceAttendance($fx['biller'], 2000);
    expect(fn () => $svc->chargeVisit($fx['biller'], $fx['visit']->fresh(), true, ['ED-XRAY']))
        ->toThrow(TariffNotFoundForDateException::class);

    // The operator prices what was missing and retries — the natural response to the error.
    $svc->priceService($fx['biller'], 'ED-XRAY', 'Chest X-ray', 3500);
    $captured = $svc->chargeVisit($fx['biller'], $fx['visit']->fresh(), true, ['ED-XRAY']);

    // Exactly ONE attendance charge. Before the fix the orphan survived AND the idempotency guard read
    // empty, so the retry captured a SECOND attendance charge: the guard permitted the double-billing it
    // exists to prevent.
    expect($captured)->toHaveCount(2)
        ->and(Charge::query()->where('code', EdBillingService::ATTENDANCE_CODE)->count())->toBe(1)
        ->and(Charge::query()->count())->toBe(2)
        ->and(EdVisitCharge::query()->count())->toBe(2);
});

test('P7-M5: a rolled-back ED capture writes no audit entry, and the hash chain still verifies', function () {
    $fx = ccaFixture('cca-ed-audit');
    $svc = app(EdBillingService::class);
    $svc->priceAttendance($fx['biller'], 2000);

    test()->actingAs($fx['biller']);
    $before = AuditEvent::query()->count();

    expect(fn () => $svc->chargeVisit($fx['biller'], $fx['visit']->fresh(), true, ['ED-XRAY']))
        ->toThrow(TariffNotFoundForDateException::class);

    // A charge that never existed must not be audited as though it had (D-179), and rolling the write back
    // must not leave a gap in the append-only chain.
    expect(AuditEvent::query()->where('action', 'like', 'charge%')->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe($before)
        ->and(app(AuditService::class)->verifyChain()['ok'] ?? true)->toBeTrue();
});

// ------------------------------------------------- LAB + RADIOLOGY ----

test('P8-H2: every captured Lab charge has its link row — the invariant that makes re-billing impossible', function () {
    $fx = ccaFixture('cca-lab-link');
    $svc = app(LabBillingService::class);
    $svc->priceTest($fx['biller'], LabTest::query()->firstOrFail(), 2500);

    $charge = $svc->chargeOrder($fx['biller'], $fx['labOrder']);

    expect(Charge::query()->count())->toBe(1)
        ->and(LabOrderCharge::query()->where('charge_id', $charge->id)->count())->toBe(1)
        // No charge exists without a link, which is what the idempotency guard reads.
        ->and(Charge::query()->count())->toBe(LabOrderCharge::query()->count());
});

test('P8-H2: charging a Lab order twice captures exactly one charge', function () {
    $fx = ccaFixture('cca-lab-idem');
    $svc = app(LabBillingService::class);
    $svc->priceTest($fx['biller'], LabTest::query()->firstOrFail(), 2500);

    $first = $svc->chargeOrder($fx['biller'], $fx['labOrder']);
    $second = $svc->chargeOrder($fx['biller'], $fx['labOrder']->fresh());

    expect($second->id)->toBe($first->id)
        ->and(Charge::query()->count())->toBe(1)
        ->and(LabOrderCharge::query()->count())->toBe(1);
});

test('P8-H2: every captured Radiology charge has its link row, and a second charge is idempotent', function () {
    $fx = ccaFixture('cca-rad');
    $svc = app(RadiologyBillingService::class);
    $svc->priceExam($fx['biller'], RadiologyExam::query()->firstOrFail(), 4500);

    $first = $svc->chargeOrder($fx['biller'], $fx['radOrder']);
    $second = $svc->chargeOrder($fx['biller'], $fx['radOrder']->fresh());

    expect($second->id)->toBe($first->id)
        ->and(Charge::query()->count())->toBe(1)
        ->and(RadiologyOrderCharge::query()->count())->toBe(1)
        ->and(Charge::query()->count())->toBe(RadiologyOrderCharge::query()->count());
});

// ------------------------------------------------------ STRUCTURAL GUARD ----

test('P8-H2 STRUCTURAL GUARD: all three capture paths are wrapped in one transaction with a row lock', function () {
    // Lab and Radiology capture exactly ONE charge per order, so a "partway" failure is not reachable
    // without mocking the link write — their atomicity is held here and by the identical remedy that ED
    // demonstrates live above. Mutation-checked: removing any `DB::transaction` reddens this.
    // Comments are stripped: each file EXPLAINS the old defect, and a fence its own rationale trips is a
    // fence that gets deleted (the QA-FIX.7b lesson).
    $strip = function (string $src): string {
        $src = preg_replace('~/\*.*?\*/~s', ' ', $src) ?? $src;

        return preg_replace('~(?<![:\'"])//[^\n]*~', ' ', $src) ?? $src;
    };

    $paths = [
        'Lab' => base_path('Modules/Lab/src/Services/LabBillingService.php'),
        'Radiology' => base_path('Modules/Radiology/src/Services/RadiologyBillingService.php'),
        'ED' => base_path('Modules/ED/src/Services/EdBillingService.php'),
    ];

    foreach ($paths as $module => $path) {
        $code = $strip((string) File::get($path));

        expect(substr_count($code, 'DB::transaction'))->toBe(1, "{$module} billing must wrap its capture in one transaction")
            // …and the owning row is locked, so two concurrent captures serialise rather than both
            // reading an empty idempotency guard.
            ->and($code)->toContain('for update')
            // The link is written INSIDE the transaction, beside its charge.
            ->and($code)->toContain('captureManual');
    }

    // ED specifically must no longer collect charges and link them in a SEPARATE later pass — the shape
    // that made it the worst of the three.
    $ed = $strip((string) File::get($paths['ED']));
    expect($ed)->not->toContain('foreach ($captured as $charge)');
});

// ------------------------------------------------------- POSITIVE CONTROL ----

test('POSITIVE CONTROL — a clean run still captures and links every charge, in all three modules', function () {
    // Without this, every assertion above could be satisfied by services that simply stopped capturing.
    $fx = ccaFixture('cca-clean');

    $lab = app(LabBillingService::class);
    $lab->priceTest($fx['biller'], LabTest::query()->firstOrFail(), 2500);
    $lab->chargeOrder($fx['biller'], $fx['labOrder']);

    $rad = app(RadiologyBillingService::class);
    $rad->priceExam($fx['biller'], RadiologyExam::query()->firstOrFail(), 4500);
    $rad->chargeOrder($fx['biller'], $fx['radOrder']);

    $ed = app(EdBillingService::class);
    $ed->priceAttendance($fx['biller'], 2000);
    $ed->priceService($fx['biller'], 'ED-XRAY', 'Chest X-ray', 3500);
    $ed->chargeVisit($fx['biller'], $fx['visit']->fresh(), true, ['ED-XRAY']);

    expect(LabOrderCharge::query()->count())->toBe(1)
        ->and(RadiologyOrderCharge::query()->count())->toBe(1)
        ->and(EdVisitCharge::query()->count())->toBe(2)
        // Four charges, four links: the invariant across all three modules at once.
        ->and(Charge::query()->count())->toBe(4)
        ->and(LabOrderCharge::query()->count() + RadiologyOrderCharge::query()->count() + EdVisitCharge::query()->count())->toBe(4);
});
