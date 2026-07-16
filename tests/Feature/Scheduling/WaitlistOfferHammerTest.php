<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
use Modules\Scheduling\Models\WaitlistEntry;
use Modules\Scheduling\Models\WaitlistOffer;
use Modules\Scheduling\Services\WaitlistOfferService;
use Modules\Scheduling\Services\WaitlistService;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

test('two concurrent accepts of the same freed slot resolve to exactly one booking', function () {
    Notification::fake();

    $tenant = Tenant::create([
        'name' => 'Offer Hammer',
        'slug' => 'offer-hammer',
        'region' => 'eu',
        'status' => 'active',
    ]);
    app(TenantContext::class)->set($tenant);

    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $service = Service::create([
        'name' => 'Offer Hammer Consult',
        'code' => 'OHAMMER',
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
        'weekday' => 1, // 2026-07-13 is a Monday
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create([
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'reception')->firstOrFail()->id,
    ]);

    // Six waitlisted patients, each with an OPEN offer for the SAME freed slot and
    // the SAME resource. Only one accept can win.
    $offerIds = [];
    for ($i = 0; $i < 6; $i++) {
        $patient = app(PatientService::class)->create([
            'first_name' => 'Waiter'.$i,
            'last_name' => 'Hammer',
            'date_of_birth' => '1990-01-01',
            'sex' => 'female',
        ]);
        $entry = app(WaitlistService::class)->create([
            'patient_id' => $patient->id,
            'service_id' => $service->id,
            'branch_id' => $branch->id,
            'flexible' => true,
            'priority' => 5,
        ]);
        $offer = app(WaitlistOfferService::class)->offer(
            $entry, $branch->id, '2026-07-13 10:00:00', '2026-07-13 10:30:00', [$resource->id], $user,
        );
        $offerIds[] = $offer->id;
    }

    DB::disconnect();

    $notBefore = number_format(microtime(true) + 1.5, 6, '.', '');
    $processes = [];

    foreach ($offerIds as $offerId) {
        $processes[] = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'scheduling:attempt-offer-accept',
            $tenant->id,
            $offerId,
            (string) $user->id,
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
    $accepted = array_values(array_filter($outputs, fn (string $o): bool => str_contains($o, 'ACCEPTED:')));
    $conflicts = array_values(array_filter($outputs, fn (string $o): bool => str_contains($o, 'CONFLICT:')));

    expect($accepted)->toHaveCount(1)
        ->and($conflicts)->toHaveCount(5)
        ->and(Appointment::query()->count())->toBe(1)
        ->and(AppointmentResource::query()->where('resource_id', $resource->id)->count())->toBe(1)
        ->and(WaitlistOffer::query()->where('status', WaitlistOffer::STATUS_ACCEPTED)->count())->toBe(1)
        ->and(WaitlistEntry::query()->where('status', WaitlistEntry::STATUS_BOOKED)->count())->toBe(1);
});
