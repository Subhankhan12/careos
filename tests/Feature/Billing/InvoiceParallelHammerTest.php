<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceSequence;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

test('parallel hammer issues gapless consecutive invoice numbers', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Hammer Billing',
        'slug' => 'hammer-billing',
        'region' => 'eu',
        'status' => 'active',
    ]);

    app(TenantContext::class)->set($tenant);

    $actor = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $actor->id,
        'role_id' => Role::query()->where('key', 'billing')->firstOrFail()->id,
    ]);
    $branch = Branch::query()->create(['name' => 'Hammer Branch', 'code' => 'HAM']);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Hammer',
        'last_name' => 'Invoice',
        'date_of_birth' => '1970-01-01',
        'sex' => 'female',
    ]);
    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-generic',
        'name' => 'EU Generic',
        'version' => 1,
        'valid_from' => '2026-01-01',
        'status' => TariffCatalog::STATUS_ACTIVE,
        'rules' => [],
    ]);
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id,
        'code' => 'HAMMER',
        'description' => 'Hammer invoice line',
        'unit_price_minor' => 1000,
        'vat_rate_bp' => 810,
        'unit' => 'session',
        'active' => true,
    ]);

    InvoiceSequence::query()->create([
        'series' => Invoice::SERIES_INVOICE,
        'next_number' => 1,
    ]);

    $invoiceIds = [];
    for ($i = 0; $i < 6; $i++) {
        $charge = Charge::query()->create([
            'patient_id' => $patient->id,
            'branch_id' => $branch->id,
            'service_date' => '2026-05-10',
            'tariff_catalog_id' => $catalog->id,
            'tariff_item_id' => $item->id,
            'code' => $item->code,
            'description' => $item->description,
            'unit_price_minor' => $item->unit_price_minor,
            'vat_rate_bp' => $item->vat_rate_bp,
            'quantity' => 1,
            'line_total_minor' => $item->unit_price_minor,
            'status' => Charge::STATUS_VALIDATED,
            'created_by' => $actor->id,
        ]);

        $invoiceIds[] = app(IssueService::class)
            ->createDraftFromCharges($patient, [$charge], $actor)
            ->id;
    }

    DB::disconnect();

    $notBefore = number_format(microtime(true) + 1.5, 6, '.', '');
    $processes = [];

    foreach ($invoiceIds as $invoiceId) {
        $processes[] = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'billing:attempt-invoice-issue',
            $tenant->id,
            $invoiceId,
            (string) $actor->id,
            '--not-before='.$notBefore,
        ], base_path(), null, null, 90);
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
    $successes = array_values(array_filter($outputs, fn (string $output): bool => str_contains($output, 'ISSUED:')));
    $numbers = Invoice::query()
        ->where('series', Invoice::SERIES_INVOICE)
        ->where('status', Invoice::STATUS_ISSUED)
        ->pluck('number')
        ->map(fn (string $number): int => (int) $number)
        ->sort()
        ->values()
        ->all();

    expect($successes)->toHaveCount(6)
        ->and($numbers)->toBe([1, 2, 3, 4, 5, 6])
        ->and(array_unique($numbers))->toHaveCount(6)
        ->and(InvoiceSequence::query()->where('series', Invoice::SERIES_INVOICE)->firstOrFail()->next_number)->toBe(7);
});
