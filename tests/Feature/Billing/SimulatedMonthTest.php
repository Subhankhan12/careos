<?php

use Database\Seeders\SimulatedBillingMonthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\DunningEvent;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAllocation;
use Modules\Billing\Services\AccountingExportService;
use Modules\Billing\Services\PaymentService;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

test('simulated month: full billing cycle reconciles to the unit', function () {
    Storage::fake('local');

    // ------------------------------------------------------------------ seed
    (new SimulatedBillingMonthSeeder)->run();

    $tenant = Tenant::query()->where('slug', SimulatedBillingMonthSeeder::TENANT_SLUG)->firstOrFail();
    app(TenantContext::class)->set($tenant);
    $actor = User::query()->where('tenant_id', $tenant->id)->firstOrFail();

    // ------------------------------------------------- month composition
    expect(Patient::query()->count())->toBeGreaterThanOrEqual(3)
        ->and(Charge::query()->count())->toBeGreaterThanOrEqual(40)
        ->and(Charge::query()->whereNotNull('encounter_id')->count())->toBeGreaterThan(0)
        ->and(Charge::query()->whereNotNull('visit_id')->count())->toBeGreaterThan(0)
        ->and(
            Charge::query()->where('status', Charge::STATUS_INVOICED)
                ->distinct()->pluck('vat_rate_bp')->count()
        )->toBeGreaterThanOrEqual(2);

    // Tariff-version boundary (2026-06-15|16): same code, different snapshot
    // prices on either side — effective dating exercised end to end.
    $consBefore = Charge::query()->where('code', 'CONS')
        ->whereDate('service_date', '<=', SimulatedBillingMonthSeeder::BOUNDARY)
        ->firstOrFail();
    $consAfter = Charge::query()->where('code', 'CONS')
        ->whereDate('service_date', '>', SimulatedBillingMonthSeeder::BOUNDARY)
        ->firstOrFail();

    expect($consBefore->unit_price_minor)->toBe(5000)
        ->and($consAfter->unit_price_minor)->toBe(5500)
        ->and($consBefore->status)->toBe(Charge::STATUS_INVOICED)
        ->and($consAfter->status)->toBe(Charge::STATUS_INVOICED);

    // A real violation occurred and was corrected before invoicing: the
    // audit chain holds the charge.violation row, and the offending ADDON
    // charge ended the month invoiced.
    $violationAudits = collect(DB::select(
        "SELECT * FROM audit_events WHERE tenant_id = ? AND action = 'charge.violation'",
        [$tenant->id],
    ));
    $addonCharge = Charge::query()->where('code', 'ADDON')->firstOrFail();

    expect($violationAudits->count())->toBeGreaterThanOrEqual(1)
        ->and($addonCharge->status)->toBe(Charge::STATUS_INVOICED);

    // --------------------------------------------------------- invoices
    $invoices = Invoice::query()
        ->where('series', Invoice::SERIES_INVOICE)
        ->whereNotNull('number')
        ->get();
    $numbers = $invoices->pluck('number')->map(fn (string $n): int => (int) $n)->sort()->values()->all();

    expect($invoices)->toHaveCount(6)
        ->and($numbers)->toBe([1, 2, 3, 4, 5, 6]); // consecutive, no gaps

    $multiRate = $invoices->first(
        fn (Invoice $invoice): bool => $invoice->lines()->distinct()->pluck('vat_rate_bp')->count() >= 2,
    );
    expect($multiRate)->not->toBeNull();

    // --------------------------------------------------------- payments
    $paymentService = app(PaymentService::class);
    $inv = fn (int $n): Invoice => $invoices->firstWhere('number', (string) $n);

    expect(Payment::query()->count())->toBe(4)
        // full payment: INV-2 settled exactly
        ->and($inv(2)->balance()->firstOrFail()->status)->toBe(Invoice::STATUS_PAID)
        ->and($inv(2)->balance()->firstOrFail()->open_balance_minor)->toBe(0)
        // partial payment: INV-3 half open
        ->and($inv(3)->balance()->firstOrFail()->status)->toBe(Invoice::STATUS_PARTIALLY_PAID)
        ->and($paymentService->openBalance($inv(3)))->toBe($inv(3)->total_minor - intdiv($inv(3)->total_minor, 2))
        // overpayment: remainder stays visibly unallocated
        ->and($inv(4)->balance()->firstOrFail()->status)->toBe(Invoice::STATUS_PAID)
        ->and($paymentService->unallocated(
            Payment::query()->where('payer_reference', 'Overpayer')->firstOrFail()
        ))->toBe(2500)
        // allocation reversal: the mistake on INV-5 nets to zero
        ->and(PaymentAllocation::query()->whereNotNull('reverses_allocation_id')->count())->toBe(1)
        ->and((int) PaymentAllocation::query()->where('invoice_id', $inv(5)->id)->sum('amount_minor'))->toBe(0)
        ->and($inv(6)->balance()->firstOrFail()->status)->toBe(Invoice::STATUS_PAID);

    // ------------------------------------------------ partial credit note
    $creditNote = Invoice::query()
        ->where('series', Invoice::SERIES_CREDIT_NOTE)
        ->firstOrFail();

    expect($creditNote->credit_note_for_invoice_id)->toBe($inv(5)->id)
        ->and($creditNote->total_minor)->toBeLessThan(0)
        ->and(abs($creditNote->total_minor))->toBeLessThan($inv(5)->total_minor) // partial
        ->and($inv(5)->refresh()->total_minor)->toBe($inv(5)->total_minor); // original untouched

    // ------------------------------------------------------------ dunning
    $dunningEvent = DunningEvent::query()->firstOrFail();
    $feeCharge = Charge::query()->where('code', 'DUNFEE')->firstOrFail();

    expect(DunningEvent::query()->count())->toBe(1)
        ->and($dunningEvent->level)->toBe(1)
        ->and($dunningEvent->invoice_id)->toBe($inv(1)->id)
        ->and($feeCharge->status)->toBe(Charge::STATUS_DRAFT)
        ->and($feeCharge->service_date->toDateString())->toBe('2026-06-27')
        ->and($feeCharge->isManual())->toBeTrue();

    // ------------------------------------------- THE EXIT CRITERION
    // Every invariant ok === true AND delta_minor === 0. Exactly zero.
    $run = app(ReconciliationEngine::class)->run($tenant, SimulatedBillingMonthSeeder::PERIOD, $actor);

    expect($run->passed)->toBeTrue()
        ->and($run->report['invariants'])->toHaveCount(6);

    foreach ($run->report['invariants'] as $invariant) {
        expect($invariant['ok'] === true)->toBeTrue()
            ->and($invariant['delta_minor'] === 0)->toBeTrue()
            ->and($invariant['rows'])->toBe([]);
    }

    // ------------------------------------------------------------- export
    $path = app(AccountingExportService::class)->export($tenant, SimulatedBillingMonthSeeder::PERIOD, $actor);
    $csv = array_map('str_getcsv', array_filter(explode("\n", Storage::disk('local')->get($path))));

    $exportInvoiceGross = 0;
    $exportPaymentTotal = 0;
    foreach (array_slice($csv, 1) as $row) {
        if (($row[0] ?? '') === 'invoice') {
            $exportInvoiceGross += (int) $row[9];
        }
        if (($row[0] ?? '') === 'payment') {
            $exportPaymentTotal += (int) $row[9];
        }
    }

    $i4 = collect($run->report['invariants'])->firstWhere('invariant', 'I4');
    $i3 = collect($run->report['invariants'])->firstWhere('invariant', 'I3');

    expect($exportInvoiceGross === $i4['expected_minor'])->toBeTrue()
        ->and($exportInvoiceGross === (int) $invoices->sum('total_minor'))->toBeTrue()
        ->and($exportPaymentTotal === $i3['expected_minor'])->toBeTrue()
        ->and($exportPaymentTotal === (int) Payment::query()->sum('amount_minor'))->toBeTrue();

    // The month's audit chain remains intact end to end.
    expect(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});
