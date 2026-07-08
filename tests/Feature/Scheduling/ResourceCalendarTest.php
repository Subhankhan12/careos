<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Services\AvailabilityService;

uses(RefreshDatabase::class);

function resourceCalendarTenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function resourceCalendarContext(): TenantContext
{
    return app(TenantContext::class);
}

function resourceCalendarBranch(string $code = 'MAIN'): Branch
{
    return Branch::create(['name' => $code.' Branch', 'code' => $code]);
}

function resourceCalendarStaff(?string $branchId = null): StaffProfile
{
    return StaffProfile::create([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'display_name' => 'Ada Lovelace',
        'profession' => 'doctor',
        'primary_branch_id' => $branchId,
        'status' => StaffProfile::STATUS_ACTIVE,
    ]);
}

function resourceCalendarResource(array $overrides = []): BookableResource
{
    $branch = $overrides['branch'] ?? resourceCalendarBranch();
    unset($overrides['branch']);

    return BookableResource::create(array_merge([
        'type' => BookableResource::TYPE_ROOM,
        'name' => 'Room 1',
        'branch_id' => $branch->id,
        'active' => true,
    ], $overrides));
}

/**
 * @param  list<array{string, string}>  $expected
 */
function expectWindowTimes(array $windows, array $expected): void
{
    expect(array_map(
        fn (array $window): array => [
            $window['start_at']->format('Y-m-d H:i'),
            $window['end_at']->format('Y-m-d H:i'),
        ],
        $windows,
    ))->toBe($expected);
}

test('resources are tenant isolated and fail closed', function () {
    $alpha = resourceCalendarTenant('alpha');
    $beta = resourceCalendarTenant('beta');

    resourceCalendarContext()->set($alpha);
    $alphaResource = resourceCalendarResource(['branch' => resourceCalendarBranch('A')]);

    resourceCalendarContext()->set($beta);
    $betaResource = resourceCalendarResource(['branch' => resourceCalendarBranch('B')]);

    expect(BookableResource::pluck('id')->all())->toBe([$betaResource->id])
        ->and(BookableResource::find($alphaResource->id))->toBeNull();

    resourceCalendarContext()->forget();

    expect(fn () => BookableResource::query()->get())->toThrow(TenantContextMissingException::class);
});

test('availability windows use date overrides and subtract date blocks', function () {
    $tenant = resourceCalendarTenant('alpha');
    resourceCalendarContext()->set($tenant);
    $resource = resourceCalendarResource();

    ResourceAvailability::create([
        'resource_id' => $resource->id,
        'weekday' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);
    ResourceAvailability::create([
        'resource_id' => $resource->id,
        'date' => '2026-07-13',
        'start_time' => '10:00',
        'end_time' => '12:00',
    ]);
    ResourceAvailability::create([
        'resource_id' => $resource->id,
        'date' => '2026-07-13',
        'start_time' => '10:30',
        'end_time' => '11:00',
        'is_available' => false,
        'reason' => 'brief block',
    ]);

    $windows = app(AvailabilityService::class)->windowsFor($resource, '2026-07-13', '2026-07-13');

    expectWindowTimes($windows, [
        ['2026-07-13 10:00', '2026-07-13 10:30'],
        ['2026-07-13 11:00', '2026-07-13 12:00'],
    ]);
});

test('full day time off removes recurring availability', function () {
    $tenant = resourceCalendarTenant('alpha');
    resourceCalendarContext()->set($tenant);
    $resource = resourceCalendarResource();

    ResourceAvailability::create([
        'resource_id' => $resource->id,
        'weekday' => 2,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);
    ResourceAvailability::create([
        'resource_id' => $resource->id,
        'date' => '2026-07-14',
        'is_available' => false,
        'reason' => 'time off',
    ]);

    expect(app(AvailabilityService::class)->windowsFor($resource, '2026-07-14', '2026-07-14'))->toBe([]);
});

test('practitioner resources link to same tenant staff profiles', function () {
    $tenant = resourceCalendarTenant('alpha');
    resourceCalendarContext()->set($tenant);
    $branch = resourceCalendarBranch('A');
    $staff = resourceCalendarStaff($branch->id);

    $resource = resourceCalendarResource([
        'branch' => $branch,
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Dr Lovelace',
        'staff_profile_id' => $staff->id,
    ]);

    expect($resource->staffProfile->id)->toBe($staff->id)
        ->and($resource->branch->id)->toBe($branch->id);
});

test('vehicle room and chair resources have no staff link', function () {
    $tenant = resourceCalendarTenant('alpha');
    resourceCalendarContext()->set($tenant);
    $branch = resourceCalendarBranch('A');
    $staff = resourceCalendarStaff($branch->id);

    foreach ([BookableResource::TYPE_VEHICLE, BookableResource::TYPE_ROOM, BookableResource::TYPE_CHAIR] as $type) {
        $resource = resourceCalendarResource([
            'branch' => $branch,
            'type' => $type,
            'name' => ucfirst($type),
        ]);

        expect($resource->staff_profile_id)->toBeNull();
    }

    expect(fn () => resourceCalendarResource([
        'branch' => $branch,
        'type' => BookableResource::TYPE_ROOM,
        'staff_profile_id' => $staff->id,
    ]))->toThrow(InvalidArgumentException::class);
});

test('cross tenant resource foreign keys are rejected', function () {
    $alpha = resourceCalendarTenant('alpha');
    $beta = resourceCalendarTenant('beta');

    resourceCalendarContext()->set($beta);
    $betaBranch = resourceCalendarBranch('B');
    $betaStaff = resourceCalendarStaff($betaBranch->id);
    $betaResource = resourceCalendarResource(['branch' => $betaBranch]);

    resourceCalendarContext()->set($alpha);
    $alphaBranch = resourceCalendarBranch('A');

    expect(fn () => resourceCalendarResource([
        'branch_id' => $betaBranch->id,
    ]))->toThrow(CrossTenantReferenceException::class)
        ->and(fn () => resourceCalendarResource([
            'branch' => $alphaBranch,
            'type' => BookableResource::TYPE_PRACTITIONER,
            'staff_profile_id' => $betaStaff->id,
        ]))->toThrow(CrossTenantReferenceException::class)
        ->and(fn () => ResourceAvailability::create([
            'resource_id' => $betaResource->id,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]))->toThrow(CrossTenantReferenceException::class);
});
