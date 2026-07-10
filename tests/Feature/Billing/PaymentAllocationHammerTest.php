<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAllocation;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PaymentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

test('parallel hammer allocates one invoice once and the open balance never goes negative', function () {
    Storage::fake('local');

    $tenant = Tenant::query()->create([
        'name' => 'Hammer Pay',
        'slug' => 'hammer-pay',
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
        'last_name' => 'Pay',
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
        'code' => 'HAMPAY',
        'description' => 'Hammer payable',
        'unit_price_minor' => 2270,
        'vat_rate_bp' => 0,
        'unit' => 'session',
        'active' => true,
    ]);
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

    $service = app(IssueService::class);
    $invoice = $service->issue(
        $service->createDraftFromCharges($patient, [$charge], $actor),
        $actor,
    );

    // Six independent payments, each large enough to fully settle the invoice,
    // all racing to allocate the full 2270 against the single invoice.
    $paymentIds = [];
    for ($i = 0; $i < 6; $i++) {
        $paymentIds[] = app(PaymentService::class)
            ->record(2270, Payment::METHOD_BANK_TRANSFER, $actor, $patient)
            ->id;
    }

    DB::disconnect();

    $notBefore = number_format(microtime(true) + 1.5, 6, '.', '');
    $processes = [];

    foreach ($paymentIds as $paymentId) {
        $processes[] = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'billing:attempt-payment-allocation',
            $tenant->id,
            $paymentId,
            $invoice->id,
            '2270',
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
    $successes = array_values(array_filter($outputs, fn (string $output): bool => str_contains($output, 'ALLOCATED:')));

    $allocationRows = PaymentAllocation::query()->where('invoice_id', $invoice->id)->get();
    $balance = $invoice->balance()->firstOrFail();

    expect($successes)->toHaveCount(1)
        ->and($allocationRows)->toHaveCount(1)
        ->and($allocationRows->first()->amount_minor)->toBe(2270)
        ->and(app(PaymentService::class)->openBalance($invoice))->toBe(0)
        ->and($balance->open_balance_minor)->toBe(0)
        ->and($balance->open_balance_minor)->toBeGreaterThanOrEqual(0)
        ->and($balance->status)->toBe(Invoice::STATUS_PAID);
});
