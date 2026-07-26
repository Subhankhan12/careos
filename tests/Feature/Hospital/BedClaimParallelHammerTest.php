<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Services\BedService;
use Modules\Hospital\Services\WardService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

/*
 * The concurrency proof for HOSPITAL.G1 — the map's key risk (§6.3, concurrent bed
 * moves). Eight OS processes race to claim the SAME free bed at a synchronised
 * instant; the BedService lock-row-then-assert idiom (BookingService pattern applied
 * to Bed) must yield EXACTLY ONE winner — the sibling of BookingParallelHammer /
 * VisitAssignmentParallelHammer.
 */
test('parallel hammer allows exactly one claim for one free bed', function () {
    $tenant = Tenant::create([
        'name' => 'Hammer Hospital',
        'slug' => 'hammerhosp',
        'region' => 'eu',
        'status' => 'active',
    ]);

    app(TenantContext::class)->set($tenant);

    $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $manager = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $manager->id, 'role_id' => Role::where('key', 'bed_manager')->firstOrFail()->id]);

    $ward = app(WardService::class)->create($manager, $branch->id, 'Ward 1', 'W1');
    $bed = app(BedService::class)->create($manager, $ward, '1', Bed::TYPE_GENERAL);

    // Claiming requires admission.manage — an admissions clerk holds it.
    $clerk = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $clerk->id, 'role_id' => Role::where('key', 'admissions_clerk')->firstOrFail()->id]);

    DB::disconnect();

    $notBefore = number_format(microtime(true) + 1.5, 6, '.', '');
    $processes = [];

    for ($i = 0; $i < 8; $i++) {
        $processes[] = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'hospital:attempt-bed-claim',
            $tenant->id,
            $bed->id,
            (string) $clerk->id,
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
    $successes = array_values(array_filter($outputs, fn (string $output): bool => str_contains($output, 'CLAIMED:')));
    $conflicts = array_values(array_filter($outputs, fn (string $output): bool => str_contains($output, 'CONFLICT:')));

    expect($successes)->toHaveCount(1)
        ->and($conflicts)->toHaveCount(7)
        ->and(Bed::query()->find($bed->id)->status)->toBe(Bed::STATUS_OCCUPIED);
});
