<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Problem;
use Modules\Clinical\Models\Recall;
use Modules\Clinical\Models\RecallRule;
use Modules\Clinical\Models\Referral;
use Modules\Clinical\Services\EncounterService;
use Modules\Clinical\Services\RecallEngine;
use Modules\Clinical\Services\RecallService;
use Modules\Clinical\Services\ReferralService;
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

function d5Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function d5Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function d5Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function d5User(Tenant $tenant, string $role = 'doctor'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    if ($role !== '') {
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => d5Role($role)->id,
        ]);
    }

    return $user;
}

function d5Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function d5Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Referral',
        'last_name' => 'Recall',
        'date_of_birth' => '1980-09-10',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function d5Staff(Branch $branch, ?User $user = null, array $overrides = []): StaffProfile
{
    return StaffProfile::query()->create([
        'user_id' => $user?->id,
        'first_name' => 'Riley',
        'last_name' => 'Reviewer',
        'display_name' => 'Riley Reviewer',
        'profession' => 'doctor',
        'primary_branch_id' => $branch->id,
        ...$overrides,
    ]);
}

function d5Encounter(User $actor, Patient $patient, StaffProfile $practitioner, Branch $branch, string $type = Encounter::TYPE_CONSULTATION): Encounter
{
    return app(EncounterService::class)->open(
        $patient,
        $practitioner,
        $branch,
        null,
        $type,
        $actor,
    );
}

function d5ClosedEncounter(Patient $patient, StaffProfile $practitioner, Branch $branch, string $type, string $startedAt): Encounter
{
    return Encounter::query()->create([
        'patient_id' => $patient->id,
        'practitioner_id' => $practitioner->id,
        'branch_id' => $branch->id,
        'type' => $type,
        'started_at' => $startedAt,
        'ended_at' => Carbon::parse($startedAt)->addMinutes(30),
        'status' => Encounter::STATUS_CLOSED,
    ]);
}

function d5Problem(Patient $patient, StaffProfile $recorder, string $code, string $status = Problem::STATUS_ACTIVE): Problem
{
    return Problem::query()->create([
        'patient_id' => $patient->id,
        'description' => 'Documented problem '.$code,
        'code' => $code,
        'status' => $status,
        'recorded_by' => $recorder->id,
        'recorded_at' => now(),
    ]);
}

function d5AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('referrals are tenant isolated RBAC guarded and audited through lifecycle transitions', function () {
    $alpha = d5Tenant('alpha');
    $beta = d5Tenant('beta');

    d5Ctx()->set($alpha);
    $doctor = d5User($alpha, 'doctor');
    $reception = d5User($alpha, 'reception');
    $branch = d5Branch();
    $patient = d5Patient();
    $staff = d5Staff($branch, $doctor);
    $encounter = d5Encounter($doctor, $patient, $staff, $branch);

    $referral = app(ReferralService::class)->create($patient, $doctor, [
        'direction' => Referral::DIRECTION_OUTBOUND,
        'to_provider_name' => 'External Orthopedics',
        'specialty' => 'orthopedics',
        'reason' => 'Clinician documented reason',
    ], $encounter);

    app(ReferralService::class)->send($referral, $doctor);
    app(ReferralService::class)->respond($referral->refresh(), Referral::STATUS_ACCEPTED, $doctor, 'Accepted by provider');
    app(ReferralService::class)->complete($referral->refresh(), $doctor);

    expect($referral->refresh()->status)->toBe(Referral::STATUS_COMPLETED)
        ->and($referral->sent_at)->not->toBeNull()
        ->and($referral->responded_at)->not->toBeNull();

    expect(fn () => app(ReferralService::class)->send($referral, $doctor))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(ReferralService::class)->create($patient, $reception, [
        'reason' => 'Denied',
    ]))->toThrow(AuthorizationException::class);

    expect(d5AuditRows($alpha->id, 'referral.created'))->toHaveCount(1)
        ->and(d5AuditRows($alpha->id, 'referral.sent'))->toHaveCount(1)
        ->and(d5AuditRows($alpha->id, 'referral.responded'))->toHaveCount(1)
        ->and(d5AuditRows($alpha->id, 'referral.completed'))->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($alpha->id)['ok'])->toBeTrue();

    d5Ctx()->set($beta);
    expect(Referral::query()->whereKey($referral->id)->exists())->toBeFalse();

    d5Ctx()->forget();
    expect(fn () => Referral::query()->count())->toThrow(TenantContextMissingException::class);
});

test('internal referral branch links must stay in tenant and external referrals avoid tenant widening', function () {
    $alpha = d5Tenant('alpha');
    $beta = d5Tenant('beta');

    d5Ctx()->set($alpha);
    $doctor = d5User($alpha, 'doctor');
    $branch = d5Branch();
    $patient = d5Patient();
    $staff = d5Staff($branch, $doctor);
    d5Encounter($doctor, $patient, $staff, $branch);

    d5Ctx()->set($beta);
    $betaBranch = d5Branch('BETA');

    d5Ctx()->set($alpha);
    expect(fn () => app(ReferralService::class)->create($patient, $doctor, [
        'direction' => Referral::DIRECTION_OUTBOUND,
        'to_branch_id' => $betaBranch->id,
        'reason' => 'Cross tenant branch should fail',
    ]))->toThrow(CrossTenantReferenceException::class);

    $external = app(ReferralService::class)->create($patient, $doctor, [
        'direction' => Referral::DIRECTION_OUTBOUND,
        'to_provider_name' => 'External CareOS tenant recorded by name',
        'reason' => 'Provider name only until explicit share objects exist',
    ]);

    expect($external->to_branch_id)->toBeNull()
        ->and($external->to_provider_name)->toBe('External CareOS tenant recorded by name');
});

test('recall engine deterministically generates due recalls from exact criteria and never leaks tenants', function () {
    Carbon::setTestNow('2026-07-09 12:00:00');

    try {
        $alpha = d5Tenant('alpha');
        $beta = d5Tenant('beta');

        d5Ctx()->set($alpha);
        $doctor = d5User($alpha, 'doctor');
        $branch = d5Branch();
        $staff = d5Staff($branch, $doctor);
        $duePatient = d5Patient(['first_name' => 'Due']);
        $wrongCodePatient = d5Patient(['first_name' => 'Wrong']);
        $recentFollowUpPatient = d5Patient(['first_name' => 'Recent']);
        $oldFollowUpPatient = d5Patient(['first_name' => 'Old']);

        d5Problem($duePatient, $staff, 'A1');
        d5Problem($wrongCodePatient, $staff, 'B1');
        d5Problem($recentFollowUpPatient, $staff, 'A1');
        d5Problem($oldFollowUpPatient, $staff, 'A1');
        d5ClosedEncounter($recentFollowUpPatient, $staff, $branch, Encounter::TYPE_FOLLOW_UP, '2026-05-01 09:00:00');
        d5ClosedEncounter($oldFollowUpPatient, $staff, $branch, Encounter::TYPE_FOLLOW_UP, '2025-10-01 09:00:00');

        RecallRule::query()->create([
            'name' => 'A1 follow-up missing',
            'criteria' => [
                'active_problem_codes' => ['A1'],
                'missing_encounter_type' => Encounter::TYPE_FOLLOW_UP,
            ],
            'interval_months' => 6,
            'active' => true,
        ]);
        RecallRule::query()->create([
            'name' => 'Inactive rule',
            'criteria' => ['active_problem_codes' => ['A1']],
            'interval_months' => 1,
            'active' => false,
        ]);

        d5Ctx()->set($beta);
        $betaDoctor = d5User($beta, 'doctor');
        $betaStaff = d5Staff(d5Branch('BETA'), $betaDoctor);
        $betaPatient = d5Patient(['first_name' => 'Beta']);
        d5Problem($betaPatient, $betaStaff, 'A1');
        RecallRule::query()->create([
            'name' => 'Beta A1',
            'criteria' => ['active_problem_codes' => ['A1']],
            'interval_months' => 1,
            'active' => true,
        ]);

        $generated = app(RecallEngine::class)->evaluate($alpha, $doctor);

        expect($generated->pluck('patient_id')->sort()->values()->all())
            ->toBe(collect([$duePatient->id, $oldFollowUpPatient->id])->sort()->values()->all())
            ->and($generated)->toHaveCount(2)
            ->and(d5AuditRows($alpha->id, 'recall.generated'))->toHaveCount(2)
            ->and(app(AuditService::class)->verifyChain($alpha->id)['ok'])->toBeTrue();

        d5Ctx()->set($alpha);
        expect(Recall::query()->where('patient_id', $betaPatient->id)->exists())->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

test('recall status lifecycle is RBAC guarded and audited', function () {
    $tenant = d5Tenant('alpha');
    d5Ctx()->set($tenant);
    $doctor = d5User($tenant, 'doctor');
    $reception = d5User($tenant, 'reception');
    $branch = d5Branch();
    $patient = d5Patient();
    $staff = d5Staff($branch, $doctor);
    d5Problem($patient, $staff, 'A1');
    $rule = RecallRule::query()->create([
        'name' => 'A1',
        'criteria' => ['active_problem_codes' => ['A1']],
        'interval_months' => 1,
        'active' => true,
    ]);
    $recall = Recall::query()->create([
        'patient_id' => $patient->id,
        'rule_id' => $rule->id,
        'due_on' => '2026-07-09',
    ]);

    expect(fn () => app(RecallService::class)->transition($recall, Recall::STATUS_CONTACTED, $reception))
        ->toThrow(AuthorizationException::class);

    app(RecallService::class)->transition($recall, Recall::STATUS_CONTACTED, $doctor);
    app(RecallService::class)->transition($recall->refresh(), Recall::STATUS_BOOKED, $doctor);
    app(RecallService::class)->transition($recall->refresh(), Recall::STATUS_COMPLETED, $doctor);

    expect($recall->refresh()->status)->toBe(Recall::STATUS_COMPLETED);
    expect(fn () => app(RecallService::class)->transition($recall, Recall::STATUS_DISMISSED, $doctor))
        ->toThrow(InvalidArgumentException::class);

    expect(d5AuditRows($tenant->id, 'recall.contacted'))->toHaveCount(1)
        ->and(d5AuditRows($tenant->id, 'recall.booked'))->toHaveCount(1)
        ->and(d5AuditRows($tenant->id, 'recall.completed'))->toHaveCount(1);
});

test('referral recall schemas expose expected columns', function () {
    expect(Schema::hasColumns('referrals', [
        'id',
        'tenant_id',
        'patient_id',
        'encounter_id',
        'direction',
        'to_provider_name',
        'from_provider_name',
        'to_branch_id',
        'specialty',
        'reason',
        'status',
        'sent_at',
        'responded_at',
        'notes',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('recall_rules', [
            'id',
            'tenant_id',
            'name',
            'criteria',
            'interval_months',
            'active',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('recalls', [
            'id',
            'tenant_id',
            'patient_id',
            'rule_id',
            'due_on',
            'status',
        ]))->toBeTrue();
});
