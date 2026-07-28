<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Models\SurgicalItemStock;
use Modules\Surgery\Services\SurgicalCaseService;
use Modules\Surgery\Services\SurgicalStockService;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

/*
 * The concurrency proof for SURGERY.G4 — the SAFE stock decrement. Eight OS processes race to use the SAME
 * single unit of a surgical item at a synchronised instant; the SurgicalUsageService lock-row-then-assert
 * idiom (the DispensingService / BedService::claim recipe) must yield EXACTLY ONE winner — no oversell, no
 * negative on-hand. The sibling of DispenseParallelHammer / TheatreBookingParallelHammer.
 */
test('parallel hammer allows exactly one use for one unit of surgical stock', function () {
    $tenant = Tenant::create(['name' => 'Hammer Surgery', 'slug' => 'hammersurgi', 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $surgeon = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $surgeon->id, 'role_id' => Role::where('key', 'surgeon')->firstOrFail()->id]);

    $branch = Branch::create(['name' => 'Main', 'code' => 'HSUR', 'timezone' => 'Europe/Zurich']);
    $surgeonProfile = StaffProfile::query()->create([
        'first_name' => 'Sara', 'last_name' => 'Sharp', 'display_name' => 'Dr Sara Sharp',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Hammer', 'last_name' => 'Patient', 'date_of_birth' => '1980-01-01', 'sex' => 'female']);
    $case = app(SurgicalCaseService::class)->schedule($surgeon, $patient, $surgeonProfile, 'Hammer procedure', Carbon::parse('2026-09-01 08:00:00'));

    $item = app(SurgicalStockService::class)->createItem($surgeon, 'IMP-HAMMER', 'Hammer implant', true);
    // Exactly ONE unit of stock — only one of the racing uses can consume it.
    app(SurgicalStockService::class)->receive($surgeon, $item, 1);

    DB::disconnect();

    $notBefore = number_format(microtime(true) + 1.5, 6, '.', '');
    $processes = [];
    for ($i = 0; $i < 8; $i++) {
        $processes[] = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'surgery:attempt-use-item',
            $tenant->id,
            $case->id,
            $item->id,
            (string) $surgeon->id,
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
    $used = array_values(array_filter($outputs, fn (string $o): bool => str_contains($o, 'USED:')));
    $insufficient = array_values(array_filter($outputs, fn (string $o): bool => str_contains($o, 'INSUFFICIENT:')));

    expect($used)->toHaveCount(1)
        ->and($insufficient)->toHaveCount(7)
        ->and(SurgicalItemStock::query()->where('surgical_item_id', $item->id)->firstOrFail()->on_hand)->toBe(0);
});
