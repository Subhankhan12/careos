<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\BranchHours;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Exceptions\BookingUnavailableException;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AvailableSlotFinder;
use Modules\Scheduling\Services\BookingService;

uses(RefreshDatabase::class);

/*
 * CLINIC.W8b — the booking/slot engine respects a branch's configured opening hours.
 * A branch with NO hours keeps the engine's default 07:00–19:00 window (backward
 * compatible — every existing scheduling test has no hours and stays green). MONDAY is
 * 2026-07-13 (weekday 1), matching the existing scheduling suite.
 */

/**
 * @return array{tenant: Tenant, branch: Branch, service: Service, resource: BookableResource, patient: Patient, user: User}
 */
function bhFixture(): array
{
    $tenant = Tenant::create(['name' => 'Alpha Care', 'slug' => 'alpha', 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    $service = Service::create([
        'name' => 'Consult', 'code' => 'CONS', 'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER], 'bookable_online' => true, 'active' => true,
    ]);
    $resource = BookableResource::create(['type' => BookableResource::TYPE_PRACTITIONER, 'name' => 'Dr R', 'branch_id' => $branch->id, 'active' => true]);
    // Wide resource availability so ONLY the branch hours bound the slots.
    ResourceAvailability::create(['resource_id' => $resource->id, 'weekday' => 1, 'start_time' => '07:00', 'end_time' => '19:00']);
    $patient = app(PatientService::class)->create(['first_name' => 'Pat', 'last_name' => 'Hours', 'date_of_birth' => '1980-01-01', 'sex' => 'female']);
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', 'reception')->firstOrFail()->id]);

    return compact('tenant', 'branch', 'service', 'resource', 'patient', 'user');
}

function bhSlotStarts(array $fx): array
{
    return collect(app(AvailableSlotFinder::class)->forServiceBranchDate($fx['service'], $fx['branch']->id, '2026-07-13'))
        ->pluck('starts_at')
        ->all();
}

test('an unconfigured branch keeps the default 07:00–19:00 slot window (backward compatible)', function () {
    $fx = bhFixture();

    $starts = bhSlotStarts($fx);

    expect($starts)->toContain('2026-07-13 07:00:00') // default open
        ->and($starts)->toContain('2026-07-13 13:00:00'); // within the default window
});

test('configured opening hours bound the offered slots', function () {
    $fx = bhFixture();
    BranchHours::create(['branch_id' => $fx['branch']->id, 'weekday' => 1, 'is_closed' => false, 'open_time' => '09:00', 'close_time' => '12:00']);

    $starts = bhSlotStarts($fx);

    expect($starts)->toContain('2026-07-13 09:00:00')      // first slot at open
        ->and($starts)->not->toContain('2026-07-13 07:00:00')  // before open — not offered
        ->and($starts)->not->toContain('2026-07-13 13:00:00'); // after close — not offered
});

test('a closed day offers no slots', function () {
    $fx = bhFixture();
    BranchHours::create(['branch_id' => $fx['branch']->id, 'weekday' => 1, 'is_closed' => true]);

    expect(bhSlotStarts($fx))->toBe([]);
});

test('booking is rejected outside the branch opening hours and accepted inside', function () {
    $fx = bhFixture();
    BranchHours::create(['branch_id' => $fx['branch']->id, 'weekday' => 1, 'is_closed' => false, 'open_time' => '09:00', 'close_time' => '12:00']);
    $engine = app(BookingService::class);

    // 13:00 is inside resource availability (07–19) but OUTSIDE branch hours (09–12).
    expect(fn () => $engine->book($fx['service']->id, $fx['patient']->id, $fx['branch']->id, '2026-07-13 13:00:00', [$fx['resource']->id], $fx['user']))
        ->toThrow(BookingUnavailableException::class);

    // 10:00 is within the branch hours → books.
    $appointment = $engine->book($fx['service']->id, $fx['patient']->id, $fx['branch']->id, '2026-07-13 10:00:00', [$fx['resource']->id], $fx['user']);
    expect($appointment->exists)->toBeTrue();
});
