<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Modules\Patients\Services\PatientService;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Pharmacy\Models\MedicationStock;
use Modules\Pharmacy\Services\MedicationOrderService;
use Modules\Pharmacy\Services\StockService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

/*
 * The concurrency proof for PHARMACY.G4 — the SAFE stock decrement. Eight OS processes race to dispense the
 * SAME single unit of stock at a synchronised instant; the DispensingService lock-row-then-assert idiom
 * (BedService::claim applied to stock) must yield EXACTLY ONE winner — no oversell, no negative on-hand.
 * The sibling of BedClaimParallelHammer / BookingParallelHammer.
 */
test('parallel hammer allows exactly one dispense for one unit of stock', function () {
    $tenant = Tenant::create(['name' => 'Hammer Pharmacy', 'slug' => 'hammerpharm', 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $doctor = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $doctor->id, 'role_id' => Role::where('key', 'doctor')->firstOrFail()->id]);
    $pharmacist = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $pharmacist->id, 'role_id' => Role::where('key', 'pharmacist')->firstOrFail()->id]);

    $patient = app(PatientService::class)->create(['first_name' => 'Hammer', 'last_name' => 'Patient', 'date_of_birth' => '1980-01-01', 'sex' => 'female']);
    $item = FormularyItem::query()->create(['code' => 'MED-HAMMER', 'name' => 'Hammer Med', 'form' => FormularyItem::FORM_TABLET, 'strength' => '1 mg']);
    $order = app(MedicationOrderService::class)->prescribe($doctor, $patient, $item, ['dose_amount' => '1', 'dose_unit' => 'mg', 'route' => 'PO', 'frequency' => 'OD']);

    // Exactly ONE unit of stock — only one of the racing dispenses can consume it.
    app(StockService::class)->receive($pharmacist, $item, 1);

    DB::disconnect();

    $notBefore = number_format(microtime(true) + 1.5, 6, '.', '');
    $processes = [];
    for ($i = 0; $i < 8; $i++) {
        $processes[] = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'pharmacy:attempt-dispense',
            $tenant->id,
            $order->id,
            (string) $pharmacist->id,
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
    $dispensed = array_values(array_filter($outputs, fn (string $o): bool => str_contains($o, 'DISPENSED:')));
    $insufficient = array_values(array_filter($outputs, fn (string $o): bool => str_contains($o, 'INSUFFICIENT:')));

    expect($dispensed)->toHaveCount(1)
        ->and($insufficient)->toHaveCount(7)
        ->and(MedicationStock::query()->where('formulary_item_id', $item->id)->firstOrFail()->on_hand)->toBe(0);
});
