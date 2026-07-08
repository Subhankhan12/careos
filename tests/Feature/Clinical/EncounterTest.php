<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Services\EncounterService;
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
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\BookingService;

uses(RefreshDatabase::class);

function d1Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function d1Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function d1Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function d1User(Tenant $tenant, string $role = 'doctor'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    if ($role !== '') {
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => d1Role($role)->id,
        ]);
    }

    return $user;
}

function d1Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function d1Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Clinical',
        'last_name' => 'Patient',
        'date_of_birth' => '1980-05-10',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function d1Practitioner(Branch $branch, array $overrides = []): StaffProfile
{
    return StaffProfile::query()->create([
        'first_name' => 'Dana',
        'last_name' => 'Doctor',
        'display_name' => 'Dr Dana Doctor',
        'profession' => 'doctor',
        'primary_branch_id' => $branch->id,
        ...$overrides,
    ]);
}

function d1Service(array $overrides = []): Service
{
    return Service::query()->create([
        'name' => 'Consult',
        'code' => 'CONS',
        'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => false,
        'active' => true,
        ...$overrides,
    ]);
}

function d1Resource(Branch $branch): BookableResource
{
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Practitioner',
        'branch_id' => $branch->id,
        'active' => true,
    ]);

    ResourceAvailability::query()->create([
        'resource_id' => $resource->id,
        'weekday' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    return $resource;
}

function d1Appointment(
    Service $service,
    Patient $patient,
    Branch $branch,
    BookableResource $resource,
    User $user,
): Appointment {
    return app(BookingService::class)->book(
        $service->id,
        $patient->id,
        $branch->id,
        '2026-07-13 10:00:00',
        [$resource->id],
        $user,
    );
}

function d1AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('encounters are tenant isolated and fail closed', function () {
    $alpha = d1Tenant('alpha');
    $beta = d1Tenant('beta');

    d1Ctx()->set($alpha);
    $alphaUser = d1User($alpha);
    $alphaBranch = d1Branch('A');
    $alphaPatient = d1Patient(['last_name' => 'Alpha']);
    $alphaPractitioner = d1Practitioner($alphaBranch);
    $alphaEncounter = app(EncounterService::class)->open(
        $alphaPatient,
        $alphaPractitioner,
        $alphaBranch,
        null,
        Encounter::TYPE_CONSULTATION,
        $alphaUser,
    );

    d1Ctx()->set($beta);
    $betaUser = d1User($beta);
    $betaBranch = d1Branch('B');
    $betaPatient = d1Patient(['last_name' => 'Beta']);
    $betaPractitioner = d1Practitioner($betaBranch);
    app(EncounterService::class)->open(
        $betaPatient,
        $betaPractitioner,
        $betaBranch,
        null,
        Encounter::TYPE_CONSULTATION,
        $betaUser,
    );

    d1Ctx()->set($alpha);

    expect(Encounter::query()->count())->toBe(1)
        ->and(Encounter::query()->first()->is($alphaEncounter))->toBeTrue()
        ->and(Encounter::query()->where('patient_id', $betaPatient->id)->exists())->toBeFalse();

    d1Ctx()->forget();

    expect(fn () => Encounter::query()->count())->toThrow(TenantContextMissingException::class);
});

test('encounter manage permission is granted to doctors and nurses but not reception', function () {
    $tenant = d1Tenant('alpha');
    d1Ctx()->set($tenant);

    $doctor = d1User($tenant, 'doctor');
    $nurse = d1User($tenant, 'nurse');
    $reception = d1User($tenant, 'reception');
    $branch = d1Branch();
    $patient = d1Patient();
    $practitioner = d1Practitioner($branch);

    expect(Gate::forUser($doctor)->allows('encounter.manage', ['branch_id' => $branch->id]))->toBeTrue()
        ->and(Gate::forUser($nurse)->allows('encounter.manage', ['branch_id' => $branch->id]))->toBeTrue()
        ->and(Gate::forUser($reception)->allows('encounter.manage', ['branch_id' => $branch->id]))->toBeFalse()
        ->and(fn () => app(EncounterService::class)->open(
            $patient,
            $practitioner,
            $branch,
            null,
            Encounter::TYPE_CONSULTATION,
            $reception,
        ))->toThrow(AuthorizationException::class);
});

test('only one open encounter is allowed per patient practitioner pair', function () {
    $tenant = d1Tenant('alpha');
    d1Ctx()->set($tenant);
    $user = d1User($tenant);
    $branch = d1Branch();
    $patient = d1Patient();
    $practitioner = d1Practitioner($branch);
    $encounters = app(EncounterService::class);

    $first = $encounters->open(
        $patient,
        $practitioner,
        $branch,
        null,
        Encounter::TYPE_CONSULTATION,
        $user,
    );

    expect(fn () => $encounters->open(
        $patient,
        $practitioner,
        $branch,
        null,
        Encounter::TYPE_FOLLOW_UP,
        $user,
    ))->toThrow(InvalidArgumentException::class);

    $encounters->close($first, $user);
    $second = $encounters->open(
        $patient,
        $practitioner,
        $branch,
        null,
        Encounter::TYPE_FOLLOW_UP,
        $user,
    );

    expect($second->status)->toBe(Encounter::STATUS_OPEN)
        ->and(Encounter::query()->where('status', Encounter::STATUS_OPEN)->count())->toBe(1);
});

test('opening from an appointment transitions it to in progress through Scheduling service', function () {
    $tenant = d1Tenant('alpha');
    d1Ctx()->set($tenant);
    $user = d1User($tenant);
    $branch = d1Branch();
    $patient = d1Patient();
    $practitioner = d1Practitioner($branch);
    $service = d1Service();
    $resource = d1Resource($branch);
    $appointment = d1Appointment($service, $patient, $branch, $resource, $user);

    $encounter = app(EncounterService::class)->open(
        $patient,
        $practitioner,
        $branch,
        $appointment,
        Encounter::TYPE_CONSULTATION,
        $user,
        'Booked consultation',
    );

    expect($encounter->appointment_id)->toBe($appointment->id)
        ->and($appointment->refresh()->status)->toBe(Appointment::STATUS_IN_PROGRESS)
        ->and(d1AuditRows($tenant->id, 'appointment.in_progress'))->toHaveCount(1)
        ->and(d1AuditRows($tenant->id, 'encounter.opened'))->toHaveCount(1);
});

test('walk in encounters can be opened without an appointment and closed with audit', function () {
    $tenant = d1Tenant('alpha');
    d1Ctx()->set($tenant);
    $user = d1User($tenant);
    $branch = d1Branch();
    $patient = d1Patient();
    $practitioner = d1Practitioner($branch);

    $encounter = app(EncounterService::class)->open(
        $patient,
        $practitioner,
        $branch,
        null,
        Encounter::TYPE_OTHER,
        $user,
        'Administrative visit',
    );
    $closed = app(EncounterService::class)->close($encounter, $user);

    expect($encounter->appointment_id)->toBeNull()
        ->and($closed->status)->toBe(Encounter::STATUS_CLOSED)
        ->and($closed->ended_at)->not->toBeNull()
        ->and(d1AuditRows($tenant->id, 'encounter.opened'))->toHaveCount(1)
        ->and(d1AuditRows($tenant->id, 'encounter.closed'))->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('cross tenant patient or practitioner references are rejected', function () {
    $alpha = d1Tenant('alpha');
    $beta = d1Tenant('beta');

    d1Ctx()->set($alpha);
    $alphaUser = d1User($alpha);
    $alphaBranch = d1Branch('A');
    $alphaPatient = d1Patient();
    $alphaPractitioner = d1Practitioner($alphaBranch);

    d1Ctx()->set($beta);
    $betaBranch = d1Branch('B');
    $betaPatient = d1Patient(['last_name' => 'Beta']);
    $betaPractitioner = d1Practitioner($betaBranch);

    d1Ctx()->set($alpha);

    expect(fn () => app(EncounterService::class)->open(
        $betaPatient,
        $alphaPractitioner,
        $alphaBranch,
        null,
        Encounter::TYPE_CONSULTATION,
        $alphaUser,
    ))->toThrow(CrossTenantReferenceException::class)
        ->and(fn () => app(EncounterService::class)->open(
            $alphaPatient,
            $betaPractitioner,
            $alphaBranch,
            null,
            Encounter::TYPE_CONSULTATION,
            $alphaUser,
        ))->toThrow(CrossTenantReferenceException::class);
});

test('viewing an encounter writes a patient scoped read audit row', function () {
    $tenant = d1Tenant('alpha');
    d1Ctx()->set($tenant);
    $user = d1User($tenant);
    $branch = d1Branch();
    $patient = d1Patient();
    $practitioner = d1Practitioner($branch);
    $encounter = app(EncounterService::class)->open(
        $patient,
        $practitioner,
        $branch,
        null,
        Encounter::TYPE_CONSULTATION,
        $user,
    );

    $this->actingAs($user)
        ->getJson(route('clinical.encounters.show', $encounter->id))
        ->assertOk()
        ->assertJsonPath('encounter.id', $encounter->id);

    $rows = d1AuditRows($tenant->id, 'read');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->resource_type)->toBe('encounter')
        ->and($rows[0]->resource_id)->toBe($encounter->id)
        ->and($rows[0]->patient_id)->toBe($patient->id)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});
