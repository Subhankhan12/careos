<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
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

function c6Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function c6Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function c6User(Tenant $tenant, string $role = 'reception'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    if ($role !== '') {
        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => Role::where('key', $role)->firstOrFail()->id,
        ]);
    }

    return $user;
}

function c6Branch(string $code = 'MAIN'): Branch
{
    return Branch::create(['name' => $code.' Branch', 'code' => $code]);
}

function c6Service(array $overrides = []): Service
{
    return Service::create([
        'name' => 'Consult',
        'code' => 'CONS',
        'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => true,
        'active' => true,
        ...$overrides,
    ]);
}

function c6Resource(Branch $branch, string $name = 'Practitioner'): BookableResource
{
    $resource = BookableResource::create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => $name,
        'branch_id' => $branch->id,
        'active' => true,
    ]);

    ResourceAvailability::create([
        'resource_id' => $resource->id,
        'weekday' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    return $resource;
}

function c6Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create(
        [
            'first_name' => 'Sam',
            'last_name' => 'Schedule',
            'date_of_birth' => '1990-01-01',
            'sex' => 'female',
            ...$overrides,
        ],
        [['type' => 'email', 'value' => 'sam@example.test', 'is_primary' => true]],
    );
}

function c6Book(Service $service, Patient $patient, Branch $branch, BookableResource $resource, User $user, string $startsAt = '2026-07-13 10:00:00'): Appointment
{
    return app(BookingService::class)->book(
        $service->id,
        $patient->id,
        $branch->id,
        $startsAt,
        [$resource->id],
        $user,
    );
}

test('day-board is RBAC gated tenant scoped and renders the Inertia component', function () {
    $alpha = c6Tenant('alpha');
    $beta = c6Tenant('beta');

    c6Ctx()->set($alpha);
    $user = c6User($alpha);
    $branch = c6Branch('A');
    $service = c6Service();
    $resource = c6Resource($branch);
    $patient = c6Patient(['last_name' => 'Alpha']);
    c6Book($service, $patient, $branch, $resource, $user);

    c6Ctx()->set($beta);
    $betaUser = c6User($beta);
    $betaBranch = c6Branch('B');
    $betaService = c6Service(['code' => 'B-CONS']);
    $betaResource = c6Resource($betaBranch, 'Beta Practitioner');
    $betaPatient = c6Patient(['last_name' => 'Beta']);
    c6Book($betaService, $betaPatient, $betaBranch, $betaResource, $betaUser);

    c6Ctx()->set($alpha);

    $this->actingAs($user)
        ->get(route('scheduling.day-board', ['branch_id' => $branch->id, 'date' => '2026-07-13']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Scheduling/DayBoard')
            ->has('appointments', 1)
            ->where('appointments.0.patient', 'Sam Alpha')
            ->has('resources', 1));

    $unprivileged = c6User($alpha, '');
    $this->actingAs($unprivileged)->get(route('scheduling.day-board'))->assertForbidden();
});

test('day-board lifecycle action updates an appointment', function () {
    $tenant = c6Tenant('alpha');
    c6Ctx()->set($tenant);
    $user = c6User($tenant);
    $branch = c6Branch();
    $service = c6Service();
    $resource = c6Resource($branch);
    $patient = c6Patient();
    $appointment = c6Book($service, $patient, $branch, $resource, $user);

    $this->actingAs($user)
        ->post(route('scheduling.day-board.transition'), [
            'appointment_id' => $appointment->id,
            'action' => 'arrive',
        ])
        ->assertRedirect();

    expect($appointment->refresh()->status)->toBe(Appointment::STATUS_ARRIVED);
});

test('quick-book uses the safe booking path and only exposes free slots', function () {
    $tenant = c6Tenant('alpha');
    c6Ctx()->set($tenant);
    $user = c6User($tenant);
    $branch = c6Branch();
    $service = c6Service();
    $resource = c6Resource($branch);
    $patient = c6Patient();
    c6Book($service, $patient, $branch, $resource, $user, '2026-07-13 10:00:00');

    $this->actingAs($user)
        ->postJson(route('scheduling.day-board.slots'), [
            'service_id' => $service->id,
            'branch_id' => $branch->id,
            'date' => '2026-07-13',
        ])
        ->assertOk()
        ->assertJsonMissing(['starts_at' => '2026-07-13 10:00:00']);

    $this->actingAs($user)
        ->post(route('scheduling.day-board.quick-book'), [
            'service_id' => $service->id,
            'patient_id' => $patient->id,
            'branch_id' => $branch->id,
            'starts_at' => '2026-07-13 11:00:00',
            'resource_ids' => [$resource->id],
        ])
        ->assertRedirect();

    expect(Appointment::query()->where('starts_at', '2026-07-13 11:00:00')->exists())->toBeTrue();
});

test('public booking page renders without auth and only lists online services', function () {
    $tenant = c6Tenant('alpha');
    c6Ctx()->set($tenant);
    c6Service(['name' => 'Online Consult', 'code' => 'ONLINE', 'bookable_online' => true]);
    c6Service(['name' => 'Private Service', 'code' => 'PRIVATE', 'bookable_online' => false]);
    c6Branch();

    $this->get(route('public.booking.index', $tenant->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Book')
            ->has('services', 1)
            ->where('services.0.name', 'Online Consult'));
});

test('public booking creates or reuses a patient through duplicate detection and books online', function () {
    $tenant = c6Tenant('alpha');
    c6Ctx()->set($tenant);
    $branch = c6Branch();
    $service = c6Service();
    $resource = c6Resource($branch);
    $existing = c6Patient([
        'first_name' => 'Alex',
        'last_name' => 'Public',
        'date_of_birth' => '1988-02-02',
    ]);

    $this->post(route('public.booking.store', $tenant->slug), [
        'service_id' => $service->id,
        'branch_id' => $branch->id,
        'starts_at' => '2026-07-13 10:00:00',
        'resource_ids' => [$resource->id],
        'first_name' => 'Alex',
        'last_name' => 'Public',
        'date_of_birth' => '1988-02-02',
        'sex' => 'female',
        'email' => 'alex@example.test',
    ])->assertRedirect(route('public.booking.index', $tenant->slug));

    $appointment = Appointment::query()->firstOrFail();

    expect(Patient::query()->count())->toBe(1)
        ->and($appointment->patient_id)->toBe($existing->id)
        ->and($appointment->source)->toBe(Appointment::SOURCE_ONLINE)
        ->and($appointment->booked_by)->toBeNull();
});

test('public booking enforces online only services', function () {
    $tenant = c6Tenant('alpha');
    c6Ctx()->set($tenant);
    $branch = c6Branch();
    $service = c6Service(['bookable_online' => false]);
    $resource = c6Resource($branch);

    $this->post(route('public.booking.store', $tenant->slug), [
        'service_id' => $service->id,
        'branch_id' => $branch->id,
        'starts_at' => '2026-07-13 10:00:00',
        'resource_ids' => [$resource->id],
        'first_name' => 'New',
        'last_name' => 'Patient',
        'date_of_birth' => '1991-03-03',
        'sex' => 'female',
        'email' => 'new@example.test',
    ])->assertNotFound();
});
