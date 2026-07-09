<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Models\CarePlan;
use Modules\Clinical\Models\CarePlanGoal;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\ClinicalTask;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Services\CarePlanService;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\Clinical\Services\ClinicalTaskService;
use Modules\Clinical\Services\EncounterService;
use Modules\Clinical\Services\UnsignedNotesWorklist;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function d6Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function d6Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function d6Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function d6User(Tenant $tenant, string $role = 'doctor'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    if ($role !== '') {
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => d6Role($role)->id,
        ]);
    }

    return $user;
}

function d6Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function d6Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Care',
        'last_name' => 'Plan',
        'date_of_birth' => '1978-05-06',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function d6Staff(Branch $branch, ?User $user = null, array $overrides = []): StaffProfile
{
    return StaffProfile::query()->create([
        'user_id' => $user?->id,
        'first_name' => 'Casey',
        'last_name' => 'Clinician',
        'display_name' => 'Casey Clinician',
        'profession' => 'doctor',
        'primary_branch_id' => $branch->id,
        ...$overrides,
    ]);
}

function d6Encounter(User $actor, Patient $patient, StaffProfile $practitioner, Branch $branch): Encounter
{
    return app(EncounterService::class)->open(
        $patient,
        $practitioner,
        $branch,
        null,
        Encounter::TYPE_CONSULTATION,
        $actor,
    );
}

function d6Plan(Patient $patient, StaffProfile $creator, User $actor, array $overrides = []): CarePlan
{
    return app(CarePlanService::class)->create($patient, $creator, $actor, [
        'title' => 'Clinician-authored plan',
        'started_on' => '2026-07-09',
        ...$overrides,
    ], [
        ['description' => 'Clinician-authored goal', 'target_date' => '2026-08-01'],
    ]);
}

function d6Draft(User $actor, Encounter $encounter, StaffProfile $author, string $createdAt): ClinicalNote
{
    $note = app(ClinicalNoteService::class)->saveDraft(
        $encounter,
        $author,
        [
            'subjective' => 'Subjective',
            'objective' => 'Objective',
            'assessment' => 'Assessment',
            'plan' => 'Plan',
        ],
        $actor,
    );

    $note->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

    return $note->refresh();
}

function d6AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('care plans goals and tasks are tenant isolated fail closed and RBAC guarded', function () {
    $alpha = d6Tenant('alpha');
    $beta = d6Tenant('beta');

    d6Ctx()->set($alpha);
    $doctor = d6User($alpha, 'doctor');
    $reception = d6User($alpha, 'reception');
    $branch = d6Branch();
    $patient = d6Patient();
    $staff = d6Staff($branch, $doctor);
    $plan = d6Plan($patient, $staff, $doctor);
    $task = app(ClinicalTaskService::class)->create($doctor, $staff, [
        'patient_id' => $patient->id,
        'care_plan_id' => $plan->id,
        'title' => 'Follow up',
        'due_at' => '2026-07-10 09:00:00',
    ]);

    expect(CarePlan::query()->whereKey($plan->id)->exists())->toBeTrue()
        ->and(CarePlanGoal::query()->where('care_plan_id', $plan->id)->count())->toBe(1)
        ->and(ClinicalTask::query()->whereKey($task->id)->exists())->toBeTrue();

    expect(fn () => app(CarePlanService::class)->create($patient, $staff, $reception, [
        'title' => 'Denied',
        'started_on' => '2026-07-09',
    ]))->toThrow(AuthorizationException::class);

    d6Ctx()->set($beta);
    $betaDoctor = d6User($beta, 'doctor');
    $betaBranch = d6Branch('BETA');
    $betaPatient = d6Patient(['first_name' => 'Beta']);
    $betaStaff = d6Staff($betaBranch, $betaDoctor);
    $betaPlan = d6Plan($betaPatient, $betaStaff, $betaDoctor);

    expect(CarePlan::query()->whereKey($plan->id)->exists())->toBeFalse()
        ->and(CarePlan::query()->whereKey($betaPlan->id)->exists())->toBeTrue();

    d6Ctx()->forget();
    expect(fn () => CarePlan::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => ClinicalTask::query()->count())->toThrow(TenantContextMissingException::class);
});

test('clinical task assignment must reference same tenant staff and compatible patient links', function () {
    $alpha = d6Tenant('alpha');
    $beta = d6Tenant('beta');

    d6Ctx()->set($alpha);
    $doctor = d6User($alpha, 'doctor');
    $branch = d6Branch();
    $patient = d6Patient();
    $staff = d6Staff($branch, $doctor);
    $plan = d6Plan($patient, $staff, $doctor);
    $otherPatient = d6Patient(['first_name' => 'Other']);

    d6Ctx()->set($beta);
    $betaDoctor = d6User($beta, 'doctor');
    $betaStaff = d6Staff(d6Branch('BETA'), $betaDoctor);

    d6Ctx()->set($alpha);

    expect(fn () => app(ClinicalTaskService::class)->create($doctor, $betaStaff, [
        'patient_id' => $patient->id,
        'title' => 'Cross tenant',
        'due_at' => '2026-07-10 09:00:00',
    ]))->toThrow(CrossTenantReferenceException::class);

    expect(fn () => app(ClinicalTaskService::class)->create($doctor, $staff, [
        'patient_id' => $otherPatient->id,
        'care_plan_id' => $plan->id,
        'title' => 'Mismatched plan',
        'due_at' => '2026-07-10 09:00:00',
    ]))->toThrow(CrossTenantReferenceException::class);
});

test('care plan goal and clinical task status lifecycles are audited', function () {
    $tenant = d6Tenant('alpha');
    d6Ctx()->set($tenant);
    $doctor = d6User($tenant, 'doctor');
    $branch = d6Branch();
    $patient = d6Patient();
    $staff = d6Staff($branch, $doctor);
    $plan = d6Plan($patient, $staff, $doctor);
    $goal = CarePlanGoal::query()->where('care_plan_id', $plan->id)->firstOrFail();
    $task = app(ClinicalTaskService::class)->create($doctor, $staff, [
        'patient_id' => $patient->id,
        'care_plan_id' => $plan->id,
        'title' => 'Complete paperwork',
        'due_at' => '2026-07-10 09:00:00',
    ]);

    app(CarePlanService::class)->transitionGoal($goal, CarePlanGoal::STATUS_MET, $doctor);
    app(CarePlanService::class)->transition($plan, CarePlan::STATUS_COMPLETED, $doctor);
    app(ClinicalTaskService::class)->transition($task, ClinicalTask::STATUS_IN_PROGRESS, $doctor);
    app(ClinicalTaskService::class)->transition($task->refresh(), ClinicalTask::STATUS_DONE, $doctor);

    expect($plan->refresh()->status)->toBe(CarePlan::STATUS_COMPLETED)
        ->and($goal->refresh()->status)->toBe(CarePlanGoal::STATUS_MET)
        ->and($task->refresh()->status)->toBe(ClinicalTask::STATUS_DONE)
        ->and($task->completed_at)->not->toBeNull();

    expect(fn () => app(CarePlanService::class)->transition($plan, CarePlan::STATUS_CANCELLED, $doctor))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(ClinicalTaskService::class)->transition($task, ClinicalTask::STATUS_CANCELLED, $doctor))
        ->toThrow(InvalidArgumentException::class);

    expect(d6AuditRows($tenant->id, 'care_plan.created'))->toHaveCount(1)
        ->and(d6AuditRows($tenant->id, 'care_plan.completed'))->toHaveCount(1)
        ->and(d6AuditRows($tenant->id, 'care_plan_goal.met'))->toHaveCount(1)
        ->and(d6AuditRows($tenant->id, 'clinical_task.created'))->toHaveCount(1)
        ->and(d6AuditRows($tenant->id, 'clinical_task.done'))->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('unsigned notes worklist scopes own drafts and supervisor team drafts by age', function () {
    $tenant = d6Tenant('alpha');
    d6Ctx()->set($tenant);
    $doctor = d6User($tenant, 'doctor');
    $otherDoctor = d6User($tenant, 'doctor');
    $supervisor = d6User($tenant, 'org_admin');
    $branch = d6Branch();
    $patient = d6Patient();
    $doctorStaff = d6Staff($branch, $doctor, ['display_name' => 'Doctor One']);
    $otherStaff = d6Staff($branch, $otherDoctor, ['display_name' => 'Doctor Two']);
    $supervisorStaff = d6Staff($branch, $supervisor, ['display_name' => 'Supervisor']);
    $doctorEncounter = d6Encounter($doctor, $patient, $doctorStaff, $branch);
    $otherEncounter = d6Encounter($otherDoctor, $patient, $otherStaff, $branch);
    $supervisorEncounter = d6Encounter($supervisor, $patient, $supervisorStaff, $branch);

    $ownOld = d6Draft($doctor, $doctorEncounter, $doctorStaff, now()->subDays(10)->toDateTimeString());
    $otherOld = d6Draft($otherDoctor, $otherEncounter, $otherStaff, now()->subDays(9)->toDateTimeString());
    d6Draft($doctor, $doctorEncounter, $doctorStaff, now()->subDay()->toDateTimeString());
    $signedOld = d6Draft($supervisor, $supervisorEncounter, $supervisorStaff, now()->subDays(12)->toDateTimeString());
    app(ClinicalNoteService::class)->sign($signedOld, $supervisor);

    $worklist = app(UnsignedNotesWorklist::class);

    expect($worklist->olderThan($doctor, 7)->pluck('id')->all())->toBe([$ownOld->id])
        ->and($worklist->olderThan($supervisor, 7)->pluck('id')->all())->toBe([$ownOld->id, $otherOld->id]);
});

test('care plan and clinical task schemas expose the expected columns', function () {
    expect(Schema::hasColumns('care_plans', [
        'id',
        'tenant_id',
        'patient_id',
        'title',
        'status',
        'started_on',
        'ended_on',
        'created_by',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('care_plan_goals', [
            'id',
            'tenant_id',
            'care_plan_id',
            'description',
            'target_date',
            'status',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('clinical_tasks', [
            'id',
            'tenant_id',
            'patient_id',
            'care_plan_id',
            'encounter_id',
            'title',
            'description',
            'assigned_to',
            'due_at',
            'priority',
            'status',
            'completed_at',
        ]))->toBeTrue();
});
