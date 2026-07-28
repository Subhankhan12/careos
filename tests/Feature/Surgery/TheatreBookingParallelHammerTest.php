<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Models\Theatre;
use Modules\Surgery\Models\TheatreSlot;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

/*
 * The concurrency proof for SURGERY.G1 — the OVERLAP-LOCK INVARIANT. Eight OS processes race to book the SAME
 * overlapping surgical block in ONE theatre at a synchronised instant; the TheatreSchedulingService
 * lock-theatre-then-assert-no-overlap idiom (the BookingService::lockResource->assertNoOverlap /
 * BedService::claim / MedicationStock decrement family) must yield EXACTLY ONE winner — no double-booked
 * theatre. The sibling of DispenseParallelHammer / BedClaimParallelHammer / BookingParallelHammer.
 */
test('parallel hammer allows exactly one booking for one contested theatre block', function () {
    $tenant = Tenant::create(['name' => 'Hammer Surgery', 'slug' => 'hammersurg', 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $scheduler = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $scheduler->id, 'role_id' => Role::where('key', 'surgical_scheduler')->firstOrFail()->id]);

    $branch = Branch::create(['name' => 'Main', 'code' => 'HSRG', 'timezone' => 'Europe/Zurich']);
    $theatre = Theatre::query()->create(['branch_id' => $branch->id, 'name' => 'OR-1', 'type' => 'general']);

    DB::disconnect();

    // All eight race to book the IDENTICAL block 09:00–11:00 — only one can win the theatre.
    $notBefore = number_format(microtime(true) + 1.5, 6, '.', '');
    $processes = [];
    for ($i = 0; $i < 8; $i++) {
        $processes[] = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'surgery:attempt-book-slot',
            $tenant->id,
            $theatre->id,
            (string) $scheduler->id,
            '2026-08-15 09:00:00',
            '120',
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

    $outputs = array_map(fn (Process $p): string => trim($p->getOutput().$p->getErrorOutput()), $processes);
    $booked = array_values(array_filter($outputs, fn (string $o): bool => str_contains($o, 'BOOKED:')));
    $conflict = array_values(array_filter($outputs, fn (string $o): bool => str_contains($o, 'CONFLICT:')));

    expect($booked)->toHaveCount(1)
        ->and($conflict)->toHaveCount(7)
        ->and(TheatreSlot::query()->where('theatre_id', $theatre->id)->count())->toBe(1);
});
