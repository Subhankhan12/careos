<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Services\ClinicalListService;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\Clinical\Services\MedicationService;
use Modules\Comms\Services\ThreadService;
use Modules\Nursing\Services\ServiceAgreementService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientMergeService;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\RbacProvisioner;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/**
 * The UI rule says a component may HIDE a control, but the server must refuse
 * independently. These tests craft the call directly — no button, no page — so
 * a permission that lives only in a .vue file fails here.
 */
function p3RbacCtx(): TenantContext
{
    return app(TenantContext::class);
}

/**
 * A user holding exactly one starter role — nothing else.
 */
function p3RoleUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('tenant_id', $tenant->id)->where('key', $role)->firstOrFail()->id,
        'branch_id' => null,
    ]);

    return $user;
}

/**
 * @return array{tenant: Tenant, branch: Branch, patient: Patient, staff: StaffProfile}
 */
function p3RbacFixture(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Rbac Clinic',
        'slug' => 'rbac-clinic',
        'region' => 'eu',
        'status' => 'active',
    ]);
    p3RbacCtx()->set($tenant);

    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Ree',
        'last_name' => 'Back',
        'date_of_birth' => '1975-03-03',
        'sex' => 'female',
    ]);
    $staff = StaffProfile::query()->create([
        'first_name' => 'Sam',
        'last_name' => 'Staff',
        'display_name' => 'Sam Staff',
        'profession' => 'doctor',
        'primary_branch_id' => $branch->id,
        'status' => StaffProfile::STATUS_ACTIVE,
    ]);

    return compact('tenant', 'branch', 'patient', 'staff');
}

/**
 * The starter role catalogue is the contract these tests lean on: if a future
 * gate quietly hands `reception` billing.manage, this fails first.
 */
test('the starter role catalogue withholds each sensitive permission from the roles that must not have it', function () {
    $withheld = [
        'note.sign' => ['reception', 'billing', 'coordinator'],
        'allergy.override' => ['reception', 'billing', 'coordinator', 'nurse'],
        'patient.merge' => ['reception', 'billing', 'coordinator', 'nurse', 'doctor'],
        'billing.manage' => ['reception', 'nurse', 'doctor', 'coordinator'],
        'dispatch.manage' => ['reception', 'billing', 'nurse', 'doctor'],
        'timesheet.approve' => ['reception', 'billing', 'nurse', 'doctor'],
        'comms.manage' => ['billing', 'nurse', 'doctor', 'coordinator'],
        'agreement.manage' => ['reception', 'billing', 'nurse', 'doctor'],
    ];

    foreach ($withheld as $permission => $roles) {
        foreach ($roles as $role) {
            expect(RbacProvisioner::ROLE_TEMPLATES[$role]['permissions'])
                ->not->toContain($permission, "starter role {$role} must not hold {$permission}");
        }
    }
});

test('note.sign: a user without it cannot sign a note, however the request is crafted', function () {
    $fx = p3RbacFixture();
    $reception = p3RoleUser($fx['tenant'], 'reception');
    $doctor = p3RoleUser($fx['tenant'], 'doctor');

    $encounter = Encounter::query()->create([
        'patient_id' => $fx['patient']->id,
        'practitioner_id' => $fx['staff']->id,
        'branch_id' => $fx['branch']->id,
        'type' => Encounter::TYPE_CONSULTATION,
        'started_at' => now(),
        'status' => Encounter::STATUS_OPEN,
    ]);

    $notes = app(ClinicalNoteService::class);
    $draft = $notes->saveDraft($encounter, $fx['staff'], ['subjective' => 'Reported.'], $doctor);

    expect(fn () => $notes->sign($draft, $reception))->toThrow(AuthorizationException::class)
        ->and($draft->refresh()->status)->toBe(ClinicalNote::STATUS_DRAFT);
});

test('allergy.override: a nurse cannot override an allergy hard-stop', function () {
    $fx = p3RbacFixture();
    $nurse = p3RoleUser($fx['tenant'], 'nurse');

    app(ClinicalListService::class)->recordAllergy(
        $fx['patient'],
        $fx['staff'],
        $nurse,
        ['substance' => 'Penicillin', 'severity' => 'severe'],
    );

    // Even WITH a reason, the override needs the permission.
    expect(fn () => app(MedicationService::class)->record(
        $fx['patient'],
        $fx['staff'],
        $nurse,
        ['name' => 'Penicillin', 'dose_text' => '500 mg', 'started_on' => now()->toDateString()],
        'Nurse decided to override.',
    ))->toThrow(AuthorizationException::class);
});

test('patient.merge: a doctor cannot merge patients', function () {
    $fx = p3RbacFixture();
    $doctor = p3RoleUser($fx['tenant'], 'doctor');

    $duplicate = app(PatientService::class)->create([
        'first_name' => 'Ree',
        'last_name' => 'Back',
        'date_of_birth' => '1975-03-03',
        'sex' => 'female',
    ]);

    expect(fn () => app(PatientMergeService::class)->merge($duplicate, $fx['patient'], 'Same person', $doctor))
        ->toThrow(AuthorizationException::class)
        ->and($duplicate->refresh()->status)->toBe(Patient::STATUS_ACTIVE);
});

test('billing.manage: reception cannot run reconciliation', function () {
    $fx = p3RbacFixture();
    $reception = p3RoleUser($fx['tenant'], 'reception');

    expect(fn () => app(ReconciliationEngine::class)->run($fx['tenant'], now()->format('Y-m'), $reception))
        ->toThrow(AuthorizationException::class);
});

test('agreement.manage: reception cannot create a nursing service agreement', function () {
    $fx = p3RbacFixture();
    $reception = p3RoleUser($fx['tenant'], 'reception');

    expect(fn () => app(ServiceAgreementService::class)->create([
        'patient_id' => $fx['patient']->id,
        'branch_id' => $fx['branch']->id,
        'funding_type' => 'self_pay',
        'starts_on' => now()->toDateString(),
    ], [[
        'service_id' => 'whatever',
        'planned_frequency_text' => 'weekly',
        'duration_minutes' => 60,
    ]], $reception))->toThrow(AuthorizationException::class);
});

test('comms.manage: a doctor cannot open or post to a patient thread', function () {
    $fx = p3RbacFixture();
    $doctor = p3RoleUser($fx['tenant'], 'doctor');

    expect(fn () => app(ThreadService::class)->openPatientThread($fx['patient'], 'Injected', $doctor))
        ->toThrow(AuthorizationException::class);
});

test('dispatch.manage and timesheet.approve are withheld from reception at the HTTP boundary', function () {
    $fx = p3RbacFixture();
    $reception = p3RoleUser($fx['tenant'], 'reception');

    $this->actingAs($reception);

    // Crafted directly — there is no button for this on reception's UI.
    $response = $this->post('/nursing/dispatch/assign', [
        'planned_visit_id' => 'does-not-matter',
        'resource_id' => 'does-not-matter',
    ]);

    // Refused before it can matter that the ids are junk.
    expect($response->status())->toBeIn([403, 404]);

    expect($this->get('/nursing/dispatch')->status())->toBe(403);
});
