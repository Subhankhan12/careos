<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Exceptions\BookingConflictException;
use Modules\Scheduling\Exceptions\BookingUnavailableException;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentResource;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\BookingService;

uses(RefreshDatabase::class);

function bookingTenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function bookingCtx(): TenantContext
{
    return app(TenantContext::class);
}

function bookingBranch(string $code = 'MAIN'): Branch
{
    return Branch::create(['name' => $code.' Branch', 'code' => $code]);
}

function bookingUser(Tenant $tenant, string $roleKey = 'reception'): User
{
    $user = User::factory()->forTenant($tenant)->create();
    $role = Role::where('key', $roleKey)->firstOrFail();

    RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id]);

    return $user;
}

function bookingPatient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Pat',
        'last_name' => 'Schedule',
        'date_of_birth' => '1988-03-14',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function bookingServiceModel(array $overrides = []): Service
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

function bookingResource(Branch $branch, array $overrides = []): BookableResource
{
    return BookableResource::create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Dr Resource',
        'branch_id' => $branch->id,
        'active' => true,
        ...$overrides,
    ]);
}

function bookingRecurringAvailability(BookableResource $resource, string $start = '09:00', string $end = '17:00'): void
{
    ResourceAvailability::create([
        'resource_id' => $resource->id,
        'weekday' => 1,
        'start_time' => $start,
        'end_time' => $end,
    ]);
}

function bookingEngine(): BookingService
{
    return app(BookingService::class);
}

test('appointments and appointment resources are tenant isolated and fail closed', function () {
    $alpha = bookingTenant('alpha');
    $beta = bookingTenant('beta');

    bookingCtx()->set($alpha);
    $alphaBranch = bookingBranch('A');
    $alphaService = bookingServiceModel();
    $alphaResource = bookingResource($alphaBranch);
    bookingRecurringAvailability($alphaResource);
    $alphaPatient = bookingPatient();
    $alphaUser = bookingUser($alpha);
    bookingEngine()->book($alphaService->id, $alphaPatient->id, $alphaBranch->id, '2026-07-13 10:00:00', [$alphaResource->id], $alphaUser);

    bookingCtx()->set($beta);
    $betaBranch = bookingBranch('B');
    $betaService = bookingServiceModel(['code' => 'B-CONS']);
    $betaResource = bookingResource($betaBranch);
    bookingRecurringAvailability($betaResource);
    $betaPatient = bookingPatient(['last_name' => 'Beta']);
    $betaUser = bookingUser($beta);
    $betaAppointment = bookingEngine()->book($betaService->id, $betaPatient->id, $betaBranch->id, '2026-07-13 10:00:00', [$betaResource->id], $betaUser);

    bookingCtx()->set($alpha);

    expect(Appointment::query()->count())->toBe(1)
        ->and(Appointment::query()->first()->patient_id)->toBe($alphaPatient->id)
        ->and(Appointment::query()->find($betaAppointment->id))->toBeNull()
        ->and(AppointmentResource::query()->count())->toBe(1);

    bookingCtx()->forget();

    expect(fn () => Appointment::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => AppointmentResource::query()->count())->toThrow(TenantContextMissingException::class);
});

test('booking requires appointment manage permission', function () {
    $tenant = bookingTenant('alpha');
    bookingCtx()->set($tenant);
    $branch = bookingBranch();
    $service = bookingServiceModel();
    $resource = bookingResource($branch);
    bookingRecurringAvailability($resource);
    $patient = bookingPatient();
    $user = User::factory()->forTenant($tenant)->create();

    expect(fn () => bookingEngine()->book(
        $service->id,
        $patient->id,
        $branch->id,
        '2026-07-13 10:00:00',
        [$resource->id],
        $user,
    ))->toThrow(AuthorizationException::class);
});

test('booking rejects slots outside resource availability', function () {
    $tenant = bookingTenant('alpha');
    bookingCtx()->set($tenant);
    $branch = bookingBranch();
    $service = bookingServiceModel();
    $resource = bookingResource($branch);
    bookingRecurringAvailability($resource, '09:00', '11:00');
    $patient = bookingPatient();
    $user = bookingUser($tenant);

    expect(fn () => bookingEngine()->book(
        $service->id,
        $patient->id,
        $branch->id,
        '2026-07-13 11:00:00',
        [$resource->id],
        $user,
    ))->toThrow(BookingUnavailableException::class);
});

test('booking respects service buffers with half open overlap windows', function () {
    $tenant = bookingTenant('alpha');
    bookingCtx()->set($tenant);
    $branch = bookingBranch();
    $service = bookingServiceModel([
        'buffer_before_minutes' => 10,
        'buffer_after_minutes' => 10,
    ]);
    $resource = bookingResource($branch);
    bookingRecurringAvailability($resource);
    $patient = bookingPatient();
    $user = bookingUser($tenant);

    bookingEngine()->book($service->id, $patient->id, $branch->id, '2026-07-13 10:00:00', [$resource->id], $user);

    expect(fn () => bookingEngine()->book(
        $service->id,
        $patient->id,
        $branch->id,
        '2026-07-13 10:35:00',
        [$resource->id],
        $user,
    ))->toThrow(BookingConflictException::class);

    $next = bookingEngine()->book($service->id, $patient->id, $branch->id, '2026-07-13 10:50:00', [$resource->id], $user);

    expect($next)->toBeInstanceOf(Appointment::class)
        ->and(Appointment::query()->count())->toBe(2);
});

test('multi resource booking requires every resource free and leaves no partial rows', function () {
    $tenant = bookingTenant('alpha');
    bookingCtx()->set($tenant);
    $branch = bookingBranch();
    $chairOnly = bookingServiceModel([
        'code' => 'CHAIR',
        'requires_resource_types' => [BookableResource::TYPE_CHAIR],
    ]);
    $combined = bookingServiceModel([
        'code' => 'COMBO',
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER, BookableResource::TYPE_CHAIR],
    ]);
    $practitioner = bookingResource($branch);
    $chair = bookingResource($branch, [
        'type' => BookableResource::TYPE_CHAIR,
        'name' => 'Chair 1',
    ]);
    bookingRecurringAvailability($practitioner);
    bookingRecurringAvailability($chair);
    $patient = bookingPatient();
    $user = bookingUser($tenant);

    bookingEngine()->book($chairOnly->id, $patient->id, $branch->id, '2026-07-13 10:00:00', [$chair->id], $user);

    expect(fn () => bookingEngine()->book(
        $combined->id,
        $patient->id,
        $branch->id,
        '2026-07-13 10:00:00',
        [$practitioner->id, $chair->id],
        $user,
    ))->toThrow(BookingConflictException::class);

    expect(Appointment::query()->count())->toBe(1)
        ->and(AppointmentResource::query()->count())->toBe(1);
});

test('cross tenant resources and patients are rejected', function () {
    $alpha = bookingTenant('alpha');
    $beta = bookingTenant('beta');

    bookingCtx()->set($beta);
    $betaBranch = bookingBranch('B');
    $betaResource = bookingResource($betaBranch);
    bookingRecurringAvailability($betaResource);
    $betaPatient = bookingPatient(['last_name' => 'Beta']);

    bookingCtx()->set($alpha);
    $alphaBranch = bookingBranch('A');
    $alphaService = bookingServiceModel();
    $alphaUser = bookingUser($alpha);

    expect(fn () => bookingEngine()->book(
        $alphaService->id,
        $betaPatient->id,
        $alphaBranch->id,
        '2026-07-13 10:00:00',
        [$betaResource->id],
        $alphaUser,
    ))->toThrow(CrossTenantReferenceException::class);
});

test('booking writes appointment booked audit event and keeps chain valid', function () {
    $tenant = bookingTenant('alpha');
    bookingCtx()->set($tenant);
    $branch = bookingBranch();
    $service = bookingServiceModel();
    $resource = bookingResource($branch);
    bookingRecurringAvailability($resource);
    $patient = bookingPatient();
    $user = bookingUser($tenant);

    $appointment = bookingEngine()->book(
        $service->id,
        $patient->id,
        $branch->id,
        '2026-07-13 10:00:00',
        [$resource->id],
        $user,
    );

    $rows = DB::select(
        'select * from audit_events where tenant_id <=> ? and action = ? and resource_id = ?',
        [$tenant->id, 'appointment.booked', $appointment->id],
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->patient_id)->toBe($patient->id)
        ->and($rows[0]->actor_id)->toBe((string) $user->id)
        ->and(json_decode($rows[0]->context, true)['resource_ids'])->toBe([$resource->id])
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});
