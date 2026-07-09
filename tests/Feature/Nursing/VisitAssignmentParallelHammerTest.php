<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Nursing\Models\NurseConstraint;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Services\ServiceAgreementService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\Service;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

test('parallel hammer allows exactly one overlapping visit assignment to one nurse', function () {
    $tenant = Tenant::create([
        'name' => 'Hammer Nursing',
        'slug' => 'hammer-nursing',
        'region' => 'eu',
        'status' => 'active',
    ]);

    app(TenantContext::class)->set($tenant);

    $actor = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $actor->id,
        'role_id' => Role::query()->where('key', 'coordinator')->firstOrFail()->id,
    ]);
    $branch = Branch::query()->create(['name' => 'Hammer Branch', 'code' => 'HAM']);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Hammer',
        'last_name' => 'Patient',
        'date_of_birth' => '1941-03-03',
        'sex' => 'female',
    ]);
    $service = Service::query()->create([
        'name' => 'Hammer Visit',
        'code' => 'HAMMER-VISIT',
        'category' => 'home-care',
        'default_duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => false,
        'active' => true,
    ]);
    $agreement = app(ServiceAgreementService::class)->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'funding_type' => ServiceAgreement::FUNDING_OTHER,
        'starts_on' => '2026-08-01',
    ], [[
        'service_id' => $service->id,
        'planned_frequency_text' => 'Hammer visits',
        'required_qualification' => 'RN',
        'duration_minutes' => 60,
    ]], $actor);
    $plan = VisitPlan::query()->create([
        'service_agreement_id' => $agreement->id,
        'agreement_service_id' => $agreement->agreementServices()->firstOrFail()->id,
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=1',
        'timezone' => 'Europe/Zurich',
        'window_start_time' => '09:00:00',
        'window_end_time' => '11:00:00',
        'duration_minutes' => 60,
        'starts_on' => '2026-08-03',
        'active' => true,
    ]);
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Hammer Nurse',
        'branch_id' => $branch->id,
    ]);
    NurseConstraint::query()->create([
        'resource_id' => $resource->id,
        'qualification' => 'RN',
        'max_hours_per_week' => '40.00',
        'max_travel_minutes_between_visits' => 60,
    ]);

    $visitIds = [];

    for ($i = 0; $i < 8; $i++) {
        $scheduledDate = Carbon::parse('2026-08-03')->addDays($i)->toDateString();
        $visitIds[] = PlannedVisit::query()->create([
            'visit_plan_id' => $plan->id,
            'patient_id' => $patient->id,
            'scheduled_date' => $scheduledDate,
            'window_start_at' => '2026-08-03 09:00:00',
            'window_end_at' => '2026-08-03 10:00:00',
            'duration_minutes' => 60,
            'required_qualification' => 'RN',
            'status' => PlannedVisit::STATUS_PLANNED,
            'location_latitude' => '47.376900',
            'location_longitude' => '8.541700',
        ])->id;
    }

    DB::disconnect();

    $notBefore = number_format(microtime(true) + 1.5, 6, '.', '');
    $processes = [];

    foreach ($visitIds as $visitId) {
        $processes[] = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'nursing:attempt-visit-assignment',
            $tenant->id,
            $visitId,
            $resource->id,
            (string) $actor->id,
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
    $successes = array_values(array_filter($outputs, fn (string $output): bool => str_contains($output, 'ASSIGNED:')));
    $failures = array_values(array_filter($outputs, fn (string $output): bool => str_contains($output, 'CONFLICT:')));

    expect($successes)->toHaveCount(1)
        ->and($failures)->toHaveCount(7)
        ->and(PlannedVisit::query()->where('status', PlannedVisit::STATUS_ASSIGNED)->count())->toBe(1)
        ->and(PlannedVisit::query()->where('assigned_resource_id', $resource->id)->count())->toBe(1);
});
