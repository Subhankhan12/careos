<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Models\AuditEvent;
use Modules\Clinical\Models\Encounter;
use Modules\Comms\Contracts\TelehealthProvider;
use Modules\Comms\Models\TelehealthSession;
use Modules\Comms\Providers\Telehealth\FakeTelehealthProvider;
use Modules\Comms\Services\TelehealthService;
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
 * CLINIC.W10 — staff telehealth join surfaces the CLINICIAN side of the same telehealth
 * sessions the portal patient joins (W3). These tests prove it issues the EXISTING staff
 * token through TelehealthService (recording-disabled, role staff), is encounter.manage-
 * gated, tenant-scoped, shows only the clinician's OWN sessions, and audits the issue.
 * No new telehealth logic — the not-recorded / no-media-on-server discipline is intact.
 */

function w10tCtx(): TenantContext
{
    return app(TenantContext::class);
}

function w10tFake(): FakeTelehealthProvider
{
    $fake = app(FakeTelehealthProvider::class);
    app()->instance(FakeTelehealthProvider::class, $fake);
    app()->instance(TelehealthProvider::class, $fake);

    return $fake;
}

function w10tDoctor(Tenant $tenant, string $suffix = ''): array
{
    w10tCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', 'doctor')->firstOrFail()->id]);

    $branch = Branch::query()->create(['name' => 'Tele Branch'.$suffix, 'code' => 'TEL'.($suffix ?: 'A')]);
    $staff = StaffProfile::query()->create([
        'user_id' => $user->id, 'first_name' => 'Tele'.$suffix, 'last_name' => 'Doctor',
        'display_name' => 'Tele Doctor'.$suffix, 'profession' => 'doctor',
        'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);

    return ['user' => $user, 'staff' => $staff, 'branch' => $branch];
}

function w10tUser(Tenant $tenant, string $role): User
{
    w10tCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, doctor: User, staff: StaffProfile, branch: Branch, session: TelehealthSession}
 */
function w10tFixture(string $slug = 'alpha'): array
{
    w10tFake();
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    w10tCtx()->set($tenant);

    ['user' => $doctor, 'staff' => $staff, 'branch' => $branch] = w10tDoctor($tenant);

    $patient = app(PatientService::class)->create(['first_name' => 'Video', 'last_name' => 'Patient', 'date_of_birth' => '1987-07-07', 'sex' => 'female']);
    $encounter = Encounter::query()->create([
        'patient_id' => $patient->id, 'practitioner_id' => $staff->id, 'branch_id' => $branch->id, 'appointment_id' => null,
        'type' => Encounter::TYPE_CONSULTATION, 'started_at' => now()->toDateTimeString(),
        'status' => Encounter::STATUS_OPEN, 'reason_for_visit' => 'Telehealth fixture',
    ]);
    $session = app(TelehealthService::class)->createSessionFromEncounter($encounter, $doctor);

    return compact('tenant', 'doctor', 'staff', 'branch', 'session');
}

test('the clinician sees their own session and Join issues the EXISTING staff token (recording disabled), audited', function () {
    $fx = w10tFixture();

    w10tCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get(route('telehealth.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Telehealth/Sessions')
            ->has('sessions', 1)
            ->where('sessions.0.patientName', 'Video Patient')
            ->where('sessions.0.status', TelehealthSession::STATUS_CREATED));

    // Join issues a staff-role token through the existing service.
    w10tCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->post(route('telehealth.token', $fx['session']->id))
        ->assertOk()
        ->assertJsonStructure(['token', 'room', 'role', 'expires_at'])
        ->assertJson(['role' => 'staff']);

    // Issuing the token is audited (the existing path) — no new audit added by the controller.
    expect(AuditEvent::query()->where('tenant_id', $fx['tenant']->id)->where('action', 'telehealth.token_issued')->exists())->toBeTrue();

    // The not-recorded discipline is intact: the issued token grants pin recording OFF.
    w10tCtx()->set($fx['tenant']);
    $token = app(TelehealthService::class)->joinTokenForStaff($fx['session'], $fx['doctor']);
    expect($token->role)->toBe('staff')
        ->and($token->grants['recorder'])->toBeFalse()
        ->and($token->grants['roomRecord'])->toBeFalse()
        ->and($token->grants['roomAdmin'])->toBeFalse();
});

test('staff telehealth is encounter.manage gated, tenant-scoped, and shows only the clinician own sessions', function () {
    $fx = w10tFixture();

    // reception has no encounter.manage → the page and the token action are denied.
    $reception = w10tUser($fx['tenant'], 'reception');
    w10tCtx()->forget();
    $this->actingAs($reception)->get(route('telehealth.index'))->assertForbidden();
    w10tCtx()->forget();
    $this->actingAs($reception)->post(route('telehealth.token', $fx['session']->id))->assertForbidden();

    // A second clinician in the same tenant does NOT see the first clinician's session.
    $other = w10tDoctor($fx['tenant'], '2');
    w10tCtx()->forget();
    $this->actingAs($other['user'])
        ->get(route('telehealth.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('sessions', 0));

    // A cross-tenant session id fails closed as 404 (BelongsToTenant + string id).
    $beta = w10tFixture('beta');
    w10tCtx()->forget();
    $this->actingAs($beta['doctor'])->post(route('telehealth.token', $fx['session']->id))->assertNotFound();
});
