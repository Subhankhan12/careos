<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentResource;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

test('parallel hammer allows exactly one booking for one resource slot', function () {
    $tenant = Tenant::create([
        'name' => 'Hammer Clinic',
        'slug' => 'hammer',
        'region' => 'eu',
        'status' => 'active',
    ]);

    app(TenantContext::class)->set($tenant);

    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $service = Service::create([
        'name' => 'Hammer Consult',
        'code' => 'HAMMER',
        'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => true,
        'active' => true,
    ]);
    $resource = BookableResource::create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Hammer Practitioner',
        'branch_id' => $branch->id,
    ]);
    ResourceAvailability::create([
        'resource_id' => $resource->id,
        'weekday' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Parallel',
        'last_name' => 'Hammer',
        'date_of_birth' => '1991-01-01',
        'sex' => 'female',
    ]);
    $user = User::factory()->forTenant($tenant)->create();
    $role = Role::where('key', 'reception')->firstOrFail();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id]);

    DB::disconnect();

    $notBefore = number_format(microtime(true) + 1.5, 6, '.', '');
    $processes = [];

    for ($i = 0; $i < 8; $i++) {
        $processes[] = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'scheduling:attempt-booking',
            $tenant->id,
            $service->id,
            $patient->id,
            $branch->id,
            $resource->id,
            (string) $user->id,
            '2026-07-13 10:00:00',
            '--not-before='.$notBefore,
        ], base_path(), null, null, 30);
    }

    foreach ($processes as $process) {
        $process->start();
    }

    foreach ($processes as $process) {
        $process->wait();
    }

    app(TenantContext::class)->set($tenant);

    $outputs = array_map(
        fn (Process $process): string => trim($process->getOutput().$process->getErrorOutput()),
        $processes,
    );
    $successes = array_values(array_filter($outputs, fn (string $output): bool => str_contains($output, 'BOOKED:')));
    $failures = array_values(array_filter($outputs, fn (string $output): bool => str_contains($output, 'CONFLICT:')));

    expect($successes)->toHaveCount(1)
        ->and($failures)->toHaveCount(7)
        ->and(Appointment::query()->count())->toBe(1)
        ->and(AppointmentResource::query()->count())->toBe(1)
        ->and(AppointmentResource::query()->where('resource_id', $resource->id)->count())->toBe(1);
});
