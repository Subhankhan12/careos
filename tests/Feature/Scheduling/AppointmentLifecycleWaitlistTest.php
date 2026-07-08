<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Exceptions\BookingConflictException;
use Modules\Scheduling\Exceptions\IllegalAppointmentTransitionException;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Models\WaitlistEntry;
use Modules\Scheduling\Services\AppointmentService;
use Modules\Scheduling\Services\BookingService;
use Modules\Scheduling\Services\WaitlistService;

uses(RefreshDatabase::class);

function c4Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function c4Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function c4Branch(string $code = 'MAIN'): Branch
{
    return Branch::create(['name' => $code.' Branch', 'code' => $code]);
}

function c4User(Tenant $tenant): User
{
    $user = User::factory()->forTenant($tenant)->create();

    RoleAssignment::create([
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'reception')->firstOrFail()->id,
    ]);

    return $user;
}

function c4Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Casey',
        'last_name' => 'Calendar',
        'date_of_birth' => '1990-02-03',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function c4Service(array $overrides = []): Service
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

function c4Resource(Branch $branch, array $overrides = []): BookableResource
{
    $resource = BookableResource::create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Practitioner',
        'branch_id' => $branch->id,
        'active' => true,
        ...$overrides,
    ]);

    ResourceAvailability::create([
        'resource_id' => $resource->id,
        'weekday' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    return $resource;
}

function c4Book(
    Service $service,
    Patient $patient,
    Branch $branch,
    BookableResource $resource,
    User $user,
    string $startsAt = '2026-07-13 10:00:00',
): Appointment {
    return app(BookingService::class)->book(
        $service->id,
        $patient->id,
        $branch->id,
        $startsAt,
        [$resource->id],
        $user,
    );
}

function c4AuditCount(string $tenantId, string $action): int
{
    return (int) DB::scalar(
        'select count(*) from audit_events where tenant_id <=> ? and action = ?',
        [$tenantId, $action],
    );
}

test('legal appointment lifecycle transitions are enforced and audited', function () {
    $tenant = c4Tenant('alpha');
    c4Ctx()->set($tenant);
    $branch = c4Branch();
    $service = c4Service();
    $resource = c4Resource($branch);
    $patient = c4Patient();
    $user = c4User($tenant);
    $appointments = app(AppointmentService::class);

    $appointment = c4Book($service, $patient, $branch, $resource, $user);
    $appointment = $appointments->confirm($appointment, $user);
    $appointment = $appointments->arrive($appointment, $user);
    $appointment = $appointments->start($appointment, $user);
    $appointment = $appointments->complete($appointment, $user);

    expect($appointment->status)->toBe(Appointment::STATUS_COMPLETED)
        ->and(c4AuditCount($tenant->id, 'appointment.confirmed'))->toBe(1)
        ->and(c4AuditCount($tenant->id, 'appointment.arrived'))->toBe(1)
        ->and(c4AuditCount($tenant->id, 'appointment.in_progress'))->toBe(1)
        ->and(c4AuditCount($tenant->id, 'appointment.completed'))->toBe(1);

    $illegal = c4Book($service, $patient, $branch, $resource, $user, '2026-07-13 11:00:00');

    expect(fn () => $appointments->complete($illegal, $user))
        ->toThrow(IllegalAppointmentTransitionException::class)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('active lifecycle states keep resources blocked until cancellation frees them', function () {
    $tenant = c4Tenant('alpha');
    c4Ctx()->set($tenant);
    $branch = c4Branch();
    $service = c4Service();
    $resource = c4Resource($branch);
    $patient = c4Patient();
    $user = c4User($tenant);
    $appointments = app(AppointmentService::class);

    $appointment = c4Book($service, $patient, $branch, $resource, $user);
    $appointment = $appointments->confirm($appointment, $user);

    expect(fn () => c4Book($service, $patient, $branch, $resource, $user))
        ->toThrow(BookingConflictException::class);

    $cancelled = $appointments->cancel($appointment, $user, 'patient requested a later slot');
    $rebooked = c4Book($service, $patient, $branch, $resource, $user);

    expect($cancelled->status)->toBe(Appointment::STATUS_CANCELLED)
        ->and($cancelled->status_reason)->toBe('patient requested a later slot')
        ->and($cancelled->status_changed_by)->toBe((string) $user->id)
        ->and($cancelled->resourceLinks()->count())->toBe(0)
        ->and($rebooked->status)->toBe(Appointment::STATUS_BOOKED)
        ->and(c4AuditCount($tenant->id, 'appointment.cancelled'))->toBe(1);
});

test('reschedule is atomic and no show is terminal', function () {
    $tenant = c4Tenant('alpha');
    c4Ctx()->set($tenant);
    $branch = c4Branch();
    $service = c4Service();
    $resource = c4Resource($branch);
    $patient = c4Patient();
    $user = c4User($tenant);
    $appointments = app(AppointmentService::class);

    $original = c4Book($service, $patient, $branch, $resource, $user, '2026-07-13 10:00:00');
    c4Book($service, $patient, $branch, $resource, $user, '2026-07-13 11:00:00');

    expect(fn () => $appointments->reschedule(
        $original,
        '2026-07-13 11:00:00',
        [$resource->id],
        $user,
        'move to occupied slot',
    ))->toThrow(BookingConflictException::class);

    $original->refresh();
    expect($original->status)->toBe(Appointment::STATUS_BOOKED)
        ->and($original->resourceLinks()->count())->toBe(1)
        ->and(Appointment::query()->count())->toBe(2);

    $new = $appointments->reschedule(
        $original,
        '2026-07-13 12:00:00',
        [$resource->id],
        $user,
        'patient asked for noon',
    );

    expect($original->refresh()->status)->toBe(Appointment::STATUS_RESCHEDULED)
        ->and($original->resourceLinks()->count())->toBe(0)
        ->and($new->rescheduled_from_id)->toBe($original->id)
        ->and($new->status)->toBe(Appointment::STATUS_BOOKED)
        ->and(c4AuditCount($tenant->id, 'appointment.rescheduled'))->toBe(1);

    $noShow = c4Book($service, $patient, $branch, $resource, $user, '2026-07-13 13:00:00');
    $noShow = $appointments->noShow($noShow, $user, 'did not arrive');

    expect(fn () => $appointments->confirm($noShow, $user))
        ->toThrow(IllegalAppointmentTransitionException::class);
});

test('waitlist matching respects service branch and desired window', function () {
    $tenant = c4Tenant('alpha');
    c4Ctx()->set($tenant);
    $branch = c4Branch('A');
    $otherBranch = c4Branch('B');
    $service = c4Service();
    $otherService = c4Service(['code' => 'OTHER']);
    $patient = c4Patient();
    $waitlist = app(WaitlistService::class);

    $flexible = $waitlist->create([
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'priority' => 1,
    ]);
    $specific = $waitlist->create([
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'branch_id' => $branch->id,
        'desired_starts_at' => '2026-07-13 09:00:00',
        'desired_ends_at' => '2026-07-13 12:00:00',
        'flexible' => false,
        'priority' => 5,
    ]);
    $waitlist->create([
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'branch_id' => $otherBranch->id,
        'priority' => 10,
    ]);
    $waitlist->create([
        'patient_id' => $patient->id,
        'service_id' => $otherService->id,
        'priority' => 10,
    ]);
    $waitlist->create([
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'desired_starts_at' => '2026-07-13 13:00:00',
        'desired_ends_at' => '2026-07-13 14:00:00',
        'flexible' => false,
    ]);

    expect($waitlist->matchingForSlot(
        $service->id,
        $branch->id,
        '2026-07-13 10:00:00',
        '2026-07-13 10:30:00',
    )->pluck('id')->all())->toBe([$specific->id, $flexible->id]);
});

test('waitlist offer accept books through the concurrency safe path and audits status changes', function () {
    $tenant = c4Tenant('alpha');
    c4Ctx()->set($tenant);
    $branch = c4Branch();
    $service = c4Service();
    $resource = c4Resource($branch);
    $patient = c4Patient();
    $user = c4User($tenant);
    $appointment = c4Book($service, $patient, $branch, $resource, $user);
    $waitlist = app(WaitlistService::class);
    $entry = $waitlist->create([
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'branch_id' => $branch->id,
        'desired_starts_at' => '2026-07-13 09:00:00',
        'desired_ends_at' => '2026-07-13 12:00:00',
        'flexible' => false,
        'priority' => 3,
    ]);

    app(AppointmentService::class)->cancel($appointment, $user, 'free for waitlist');

    expect($waitlist->matchingForAppointment($appointment)->pluck('id')->all())->toBe([$entry->id]);

    $offered = $waitlist->offer(
        $entry,
        $appointment->starts_at,
        $appointment->ends_at,
        $branch->id,
        $user,
    );
    $booked = $waitlist->accept($offered, [$resource->id], $user);

    expect($booked->status)->toBe(Appointment::STATUS_BOOKED)
        ->and($booked->resourceLinks()->count())->toBe(1)
        ->and($offered->refresh()->status)->toBe(WaitlistEntry::STATUS_BOOKED)
        ->and(c4AuditCount($tenant->id, 'waitlist.offered'))->toBe(1)
        ->and(c4AuditCount($tenant->id, 'waitlist.booked'))->toBe(1)
        ->and(c4AuditCount($tenant->id, 'appointment.booked'))->toBe(2)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('waitlist entries are tenant isolated and fail closed', function () {
    $alpha = c4Tenant('alpha');
    $beta = c4Tenant('beta');

    c4Ctx()->set($alpha);
    $alphaService = c4Service();
    $alphaPatient = c4Patient();
    $alphaEntry = app(WaitlistService::class)->create([
        'patient_id' => $alphaPatient->id,
        'service_id' => $alphaService->id,
    ]);

    c4Ctx()->set($beta);
    $betaService = c4Service(['code' => 'B-CONS']);
    $betaPatient = c4Patient(['last_name' => 'Beta']);
    app(WaitlistService::class)->create([
        'patient_id' => $betaPatient->id,
        'service_id' => $betaService->id,
    ]);

    expect(WaitlistEntry::query()->count())->toBe(1)
        ->and(WaitlistEntry::query()->find($alphaEntry->id))->toBeNull();

    c4Ctx()->forget();

    expect(fn () => WaitlistEntry::query()->count())->toThrow(TenantContextMissingException::class);
});
