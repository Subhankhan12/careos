<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Clinical\Models\Allergy;
use Modules\Clinical\Models\CarePlan;
use Modules\Clinical\Models\CarePlanGoal;
use Modules\Clinical\Models\ClinicalTask;
use Modules\Clinical\Models\Medication;
use Modules\Clinical\Models\Problem;
use Modules\Clinical\Models\Vital;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Models\VisitVital;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\Service;

uses(RefreshDatabase::class);

function e5Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Nursing',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function e5Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function e5Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function e5User(Tenant $tenant, string $role = 'nurse', bool $mfa = true): User
{
    $factory = User::factory()->forTenant($tenant);
    $user = ($mfa ? $factory->twoFactorEnabled() : $factory)->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => e5Role($role)->id,
    ]);

    return $user;
}

function e5Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function e5Staff(Branch $branch, User $user, string $name = 'Nora Nurse'): StaffProfile
{
    [$first, $last] = explode(' ', $name, 2);

    return StaffProfile::query()->create([
        'user_id' => $user->id,
        'first_name' => $first,
        'last_name' => $last,
        'display_name' => $name,
        'profession' => 'nurse',
        'primary_branch_id' => $branch->id,
    ]);
}

function e5Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Day',
        'last_name' => 'Pack',
        'date_of_birth' => '1940-01-02',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function e5Service(string $code): Service
{
    return Service::query()->create([
        'name' => 'Home nursing',
        'code' => $code,
        'category' => 'home-care',
        'default_duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => false,
        'active' => true,
    ]);
}

/**
 * @return array{tenant: Tenant, user: User, branch: Branch, staff: StaffProfile, resource: BookableResource, patient: Patient, visit: PlannedVisit}
 */
function e5Fixture(string $slug = 'alpha', string $patientFirst = 'Day', ?User $user = null, ?Tenant $tenant = null): array
{
    $tenant ??= e5Tenant($slug);
    e5Ctx()->set($tenant);
    $user ??= e5User($tenant);
    $branch = e5Branch(strtoupper(substr($slug, 0, 4)));
    $staff = e5Staff($branch, $user, ucfirst($slug).' Nurse');
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => ucfirst($slug).' Nurse Resource',
        'staff_profile_id' => $staff->id,
        'branch_id' => $branch->id,
    ]);
    $patient = e5Patient(['first_name' => $patientFirst]);

    PatientContact::query()->create([
        'patient_id' => $patient->id,
        'type' => PatientContact::TYPE_ADDRESS,
        'line1' => '1 Care Street',
        'city' => 'Zurich',
        'postal' => '8001',
        'country' => 'CH',
        'is_primary' => true,
    ]);

    $service = e5Service(strtoupper($slug).'-HOME');
    $agreement = ServiceAgreement::query()->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'funding_type' => ServiceAgreement::FUNDING_OTHER,
        'starts_on' => '2026-08-01',
        'status' => ServiceAgreement::STATUS_ACTIVE,
        'created_by' => $user->id,
    ]);
    $agreementService = AgreementService::query()->create([
        'service_agreement_id' => $agreement->id,
        'service_id' => $service->id,
        'planned_frequency_text' => 'As documented',
        'required_qualification' => 'RN',
        'duration_minutes' => 60,
    ]);
    $plan = VisitPlan::query()->create([
        'service_agreement_id' => $agreement->id,
        'agreement_service_id' => $agreementService->id,
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=1',
        'timezone' => 'Europe/Zurich',
        'window_start_time' => '09:00:00',
        'window_end_time' => '11:00:00',
        'duration_minutes' => 60,
        'starts_on' => '2026-08-03',
        'active' => true,
    ]);
    $visit = PlannedVisit::query()->create([
        'visit_plan_id' => $plan->id,
        'patient_id' => $patient->id,
        'scheduled_date' => '2026-08-03',
        'window_start_at' => '2026-08-03 07:00:00',
        'window_end_at' => '2026-08-03 08:00:00',
        'duration_minutes' => 60,
        'required_qualification' => 'RN',
        'status' => PlannedVisit::STATUS_ASSIGNED,
        'assigned_resource_id' => $resource->id,
        'assigned_at' => '2026-08-01 12:00:00',
        'assigned_by' => $user->id,
    ]);

    Allergy::query()->create([
        'patient_id' => $patient->id,
        'substance' => 'Penicillin',
        'substance_key' => 'penicillin',
        'reaction' => 'Rash',
        'severity' => Allergy::SEVERITY_MODERATE,
        'recorded_by' => $staff->id,
        'recorded_at' => '2026-08-01 09:00:00',
    ]);
    Medication::query()->create([
        'patient_id' => $patient->id,
        'name' => 'Clinician documented medication',
        'substance_key' => 'documented-medication',
        'dose_text' => 'As documented',
        'started_on' => '2026-08-01',
        'recorded_by' => $staff->id,
        'recorded_at' => '2026-08-01 09:05:00',
    ]);
    Problem::query()->create([
        'patient_id' => $patient->id,
        'description' => 'Documented active problem',
        'status' => Problem::STATUS_ACTIVE,
        'recorded_by' => $staff->id,
        'recorded_at' => '2026-08-01 09:10:00',
    ]);
    $carePlan = CarePlan::query()->create([
        'patient_id' => $patient->id,
        'title' => 'Clinician-authored care plan',
        'started_on' => '2026-08-01',
        'created_by' => $staff->id,
    ]);
    CarePlanGoal::query()->create([
        'care_plan_id' => $carePlan->id,
        'description' => 'Clinician-authored goal',
        'target_date' => '2026-08-20',
    ]);
    ClinicalTask::query()->create([
        'patient_id' => $patient->id,
        'care_plan_id' => $carePlan->id,
        'title' => 'Bring supplies',
        'assigned_to' => $staff->id,
        'due_at' => '2026-08-03 07:30:00',
    ]);

    return [
        'tenant' => $tenant,
        'user' => $user,
        'branch' => $branch,
        'staff' => $staff,
        'resource' => $resource,
        'patient' => $patient,
        'visit' => $visit,
    ];
}

function e5AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('nurse day-pack returns only the authenticated nurses assigned visits and read-audits each patient', function () {
    $fixture = e5Fixture('alpha', 'Alpha');
    e5Fixture('beta', 'Beta');

    e5Ctx()->set($fixture['tenant']);
    $otherNurse = e5User($fixture['tenant']);
    e5Fixture('othr', 'Other', $otherNurse, $fixture['tenant']);

    e5Ctx()->set($fixture['tenant']);
    $token = $fixture['user']->createToken('nurse-device', ['nurse:day-pack'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/nurse/day-pack?date=2026-08-03')
        ->assertOk()
        ->assertJsonPath('date', '2026-08-03')
        ->assertJsonCount(1, 'visits')
        ->assertJsonPath('visits.0.id', $fixture['visit']->id)
        ->assertJsonPath('visits.0.patient.id', $fixture['patient']->id)
        ->assertJsonPath('visits.0.patient.allergies.0.substance', 'Penicillin')
        ->assertJsonPath('visits.0.patient.medications.0.name', 'Clinician documented medication')
        ->assertJsonPath('visits.0.patient.problems.0.description', 'Documented active problem')
        ->assertJsonPath('visits.0.patient.care_plan_goals.0.description', 'Clinician-authored goal')
        ->assertJsonPath('visits.0.tasks.0.title', 'Bring supplies')
        ->assertJsonMissing(['name' => 'Beta Patient'])
        ->assertJsonMissing(['name' => 'Other Patient']);

    $readRows = e5AuditRows($fixture['tenant']->id, 'read');

    expect($readRows)->toHaveCount(1)
        ->and($readRows[0]->resource_type)->toBe('patient')
        ->and($readRows[0]->resource_id)->toBe($fixture['patient']->id)
        ->and($readRows[0]->patient_id)->toBe($fixture['patient']->id);
});

test('nurse day-pack includes a unified recent vitals history and records it in the read audit', function () {
    $fixture = e5Fixture('alpha', 'Alpha');

    // One CLINIC vital and one VISIT vital for the same patient — the two stores.
    Vital::query()->create([
        'patient_id' => $fixture['patient']->id,
        'recorded_at' => '2026-08-01 09:00:00',
        'systolic' => 118,
        'diastolic' => 76,
        'recorded_by' => $fixture['staff']->id,
    ]);
    $executionVisit = Visit::query()->create([
        'patient_id' => $fixture['patient']->id,
        'resource_id' => $fixture['resource']->id,
        'branch_id' => $fixture['branch']->id,
        'scheduled_start_at' => '2026-08-02 09:00:00',
        'status' => Visit::STATUS_COMPLETED,
        'client_visit_uuid' => 'vitals-history-uuid',
    ]);
    VisitVital::query()->create([
        'visit_id' => $executionVisit->id,
        'patient_id' => $fixture['patient']->id,
        'recorded_at' => '2026-08-02 15:00:00',
        'systolic' => 131,
        'spo2' => 96,
    ]);

    e5Ctx()->set($fixture['tenant']);
    $token = $fixture['user']->createToken('nurse-device', ['nurse:day-pack'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/nurse/day-pack?date=2026-08-03')
        ->assertOk()
        // Unified, most-recent-first, source-tagged, raw values only.
        ->assertJsonPath('visits.0.patient.vitals_history.systolic.0.value', 131)
        ->assertJsonPath('visits.0.patient.vitals_history.systolic.0.source', 'visit')
        ->assertJsonPath('visits.0.patient.vitals_history.systolic.1.value', 118)
        ->assertJsonPath('visits.0.patient.vitals_history.systolic.1.source', 'clinic')
        ->assertJsonPath('visits.0.patient.vitals_history.spo2.0.value', 96)
        // No interpretation leaks into the payload.
        ->assertJsonMissingPath('visits.0.patient.vitals_history.systolic.0.flag')
        ->assertJsonMissingPath('visits.0.patient.vitals_history.systolic.0.band');

    $readRows = e5AuditRows($fixture['tenant']->id, 'read');

    expect($readRows)->toHaveCount(1)
        ->and($readRows[0]->patient_id)->toBe($fixture['patient']->id)
        ->and(json_decode($readRows[0]->context, true)['includes_vitals_history'])->toBeTrue();
});

test('nurse device login requires credentials tenant staff and completed MFA', function () {
    $tenant = e5Tenant('alpha');
    e5Ctx()->set($tenant);
    $mfaUser = e5User($tenant, 'nurse', true);
    $noMfaUser = e5User($tenant, 'nurse', false);

    $this->postJson('/api/nurse/login', [
        'email' => $mfaUser->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.tenant_id', $tenant->id);

    $this->postJson('/api/nurse/login', [
        'email' => $noMfaUser->email,
        'password' => 'password',
    ])->assertForbidden();

    $this->postJson('/api/nurse/login', [
        'email' => $mfaUser->email,
        'password' => 'wrong-password',
    ])->assertUnprocessable();
});

test('revoked nurse token is rejected on the next sync attempt', function () {
    $fixture = e5Fixture('alpha');
    e5Ctx()->set($fixture['tenant']);

    $token = $this->postJson('/api/nurse/login', [
        'email' => $fixture['user']->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->json('token');

    $this->withToken($token)
        ->postJson('/api/nurse/logout')
        ->assertNoContent();

    expect(DB::table('personal_access_tokens')->count())->toBe(0);

    $this->refreshApplication();

    $this->withToken($token)
        ->getJson('/api/nurse/day-pack?date=2026-08-03')
        ->assertUnauthorized();
});

test('nurse day-pack token ability and MFA middleware fail closed', function () {
    $fixture = e5Fixture('alpha');
    e5Ctx()->set($fixture['tenant']);

    $wrongAbility = $fixture['user']->createToken('nurse-device', ['something-else'])->plainTextToken;

    $this->withToken($wrongAbility)
        ->getJson('/api/nurse/day-pack?date=2026-08-03')
        ->assertForbidden();

    $noMfa = e5User($fixture['tenant'], 'nurse', false);
    e5Staff($fixture['branch'], $noMfa, 'No Mfa');
    $noMfaToken = $noMfa->createToken('nurse-device', ['nurse:day-pack'])->plainTextToken;

    $this->withToken($noMfaToken)
        ->getJson('/api/nurse/day-pack?date=2026-08-03')
        ->assertForbidden();
});
