<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\Ward;
use Modules\Hospital\Services\AdmissionService;
use Modules\Hospital\Services\BedService;
use Modules\Hospital\Services\WardService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * HOSPITAL.G3 — the ward board (live bed-occupancy cockpit). PRESENTATIONAL over the G1 bed model
 * + G2 ADT domain (P0D.GU): these tests assert the controller PROPS (assertInertia) — beds by
 * housekeeping status + the current patient per occupied bed + a plain occupancy count — never
 * markup, and that admit-from-the-board runs through the proven AdmissionService.
 */

function wbCtx(): TenantContext
{
    return app(TenantContext::class);
}

function wbTenant(string $slug = 'wardhosp'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Hospital', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    wbCtx()->set($tenant);

    return $tenant;
}

function wbUser(Tenant $tenant, string $role): User
{
    // twoFactorEnabled so the user clears the 2FA middleware on real HTTP requests (the board is a
    // route, unlike the G1/G2 service-level tests) — the AppLandingTest pattern.
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, branch: Branch, ward: Ward, bedA: Bed, bedB: Bed, clinician: StaffProfile, patient: Patient, clerk: User, doctor: User, manager: User, billing: User} */
function wbFixture(string $slug = 'wardhosp'): array
{
    $tenant = wbTenant($slug);
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $clerk = wbUser($tenant, 'admissions_clerk'); // admission.manage (+ patient.view)
    $doctor = wbUser($tenant, 'doctor');          // patient.view, NO admission.manage / bed.manage
    $manager = wbUser($tenant, 'bed_manager');    // bed.manage / ward.manage / patient.view
    $billing = wbUser($tenant, 'billing');        // NO patient.view
    $ward = app(WardService::class)->create($manager, $branch->id, 'Ward 1', 'W1');
    $bedA = app(BedService::class)->create($manager, $ward, '1', Bed::TYPE_GENERAL);
    $bedB = app(BedService::class)->create($manager, $ward, '2', Bed::TYPE_ICU);
    $clinician = StaffProfile::query()->create([
        'first_name' => 'Hana', 'last_name' => 'Hospitalist', 'display_name' => 'Dr Hana Hospitalist',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Ivy', 'last_name' => 'Inpatient', 'date_of_birth' => '1975-03-03', 'sex' => 'female']);

    return compact('tenant', 'branch', 'ward', 'bedA', 'bedB', 'clinician', 'patient', 'clerk', 'doctor', 'manager', 'billing');
}

function wbAssertNoJudgment(array $data): void
{
    $forbidden = ['acuity', 'severity', 'score', 'risk', 'grade', 'flag', 'abnormal', 'priority',
        'deterioration', 'triage', 'ews', 'news', 'rating', 'alert', 'recommendation', 'interpretation'];
    foreach ($data as $key => $value) {
        expect(in_array((string) $key, $forbidden, true))->toBeFalse("interpretation key '{$key}' leaked into the ward-board payload");
        if (is_array($value)) {
            wbAssertNoJudgment($value);
        }
    }
}

test('the ward board renders each ward\'s beds with their status and the current patient per occupied bed', function () {
    $fx = wbFixture();
    app(AdmissionService::class)->admit($fx['clerk'], $fx['patient'], $fx['bedA'], $fx['clinician'], Stay::TYPE_ELECTIVE);

    wbCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get('/hospital/wards')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Hospital/WardBoard')
            ->has('wards', 1)
            ->where('wards.0.name', 'Ward 1')
            ->where('wards.0.summary.occupied', 1)
            ->where('wards.0.summary.total', 2)
            ->has('wards.0.beds', 2)
            // beds ordered by label: bed '1' (occupied by the patient) then bed '2' (free).
            ->where('wards.0.beds.0.status', Bed::STATUS_OCCUPIED)
            ->where('wards.0.beds.0.occupant.patient', 'Ivy Inpatient')
            ->has('wards.0.beds.0.occupant.admitted_at')
            ->where('wards.0.beds.1.status', Bed::STATUS_FREE)
            ->where('wards.0.beds.1.occupant', null));
});

test('admit-from-the-board goes through AdmissionService: the bed becomes occupied atomically (reuses G2)', function () {
    $fx = wbFixture();

    // The board's admit action POSTs to the EXISTING admit route (AdmissionController::store ->
    // AdmissionService::admit -> the proven concurrency-safe BedService::claim).
    wbCtx()->forget();
    $this->actingAs($fx['clerk'])
        ->post('/hospital/admissions', [
            'patient_id' => $fx['patient']->id,
            'bed_id' => $fx['bedA']->id,
            'admitting_clinician_id' => $fx['clinician']->id,
            'admission_type' => Stay::TYPE_EMERGENCY,
        ])
        ->assertRedirect();

    wbCtx()->set($fx['tenant']);
    expect(Stay::query()->where('patient_id', $fx['patient']->id)->where('status', Stay::STATUS_ADMITTED)->count())->toBe(1)
        ->and($fx['bedA']->fresh()->status)->toBe(Bed::STATUS_OCCUPIED); // occupied via the proven claim
});

test('FENCE: the ward board payload is operational only — no acuity/severity/risk/priority field', function () {
    $fx = wbFixture();
    app(AdmissionService::class)->admit($fx['clerk'], $fx['patient'], $fx['bedA'], $fx['clinician'], Stay::TYPE_EMERGENCY);

    wbCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get('/hospital/wards')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Hospital/WardBoard')
            // status (housekeeping) + a plain occupancy count are present; NO judgment key anywhere.
            ->where('wards.0.beds.0.status', Bed::STATUS_OCCUPIED)
            ->where('wards.0.summary.occupied', 1)
            ->where('wards', function ($wards) {
                wbAssertNoJudgment(collect($wards)->toArray());

                return true;
            }));
});

test('the ward board is RBAC-gated: patient.view to view, and the surfaced actions carry their own gates', function () {
    $fx = wbFixture();

    // billing (no patient.view) is denied the board; the doctor (patient.view) reaches it.
    wbCtx()->forget();
    $this->actingAs($fx['billing'])->get('/hospital/wards')->assertForbidden();
    wbCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get('/hospital/wards')
        ->assertOk()
        // the action affordances reflect the actor's permissions (server Gate authoritative).
        ->assertInertia(fn (Assert $page) => $page
            ->where('actions.can_admit', false)       // doctor lacks admission.manage
            ->where('actions.can_manage_beds', false) // ...and bed.manage
            ->where('actions.patients', []));         // pickers only surface when the actor can admit

    // the bed-status write is bed.manage: the doctor is denied, the bed manager is allowed.
    wbCtx()->forget();
    $this->actingAs($fx['doctor'])->post('/hospital/beds/'.$fx['bedA']->id.'/status', ['status' => Bed::STATUS_BLOCKED])->assertForbidden();
    wbCtx()->forget();
    $this->actingAs($fx['manager'])->post('/hospital/beds/'.$fx['bedA']->id.'/status', ['status' => Bed::STATUS_BLOCKED])->assertRedirect();
    expect($fx['bedA']->fresh()->status)->toBe(Bed::STATUS_BLOCKED);
});

test('the ward board is tenant scoped: it shows only the current tenant\'s wards', function () {
    $fxA = wbFixture('alpha');
    app(AdmissionService::class)->admit($fxA['clerk'], $fxA['patient'], $fxA['bedA'], $fxA['clinician'], Stay::TYPE_ELECTIVE);

    // a second tenant with its own ward
    $fxB = wbFixture('beta');

    wbCtx()->forget();
    $this->actingAs($fxB['doctor'])
        ->get('/hospital/wards')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('wards', 1) // only tenant B's ward — tenant A's is invisible
            ->where('wards.0.id', $fxB['ward']->id)
            ->where('wards.0.summary.occupied', 0)); // B's beds are all free
});
