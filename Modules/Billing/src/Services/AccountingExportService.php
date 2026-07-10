<?php

namespace Modules\Billing\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceLine;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAllocation;
use Modules\Billing\Models\ReconciliationRun;
use Modules\Billing\Models\Refund;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use RuntimeException;

/**
 * Accounting CSV export of a period's financial rows in a generic,
 * ledger-friendly format (DATEV-style columns arrive with the DE pack later).
 *
 * The export REFUSES to run unless the period's most recent reconciliation run
 * passed: you cannot hand your accountant unreconciled numbers.
 */
class AccountingExportService
{
    private const COLUMNS = [
        'record_type', 'date', 'reference', 'description', 'counterparty',
        'currency', 'net_minor', 'vat_minor', 'vat_rate_bp', 'gross_minor',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
    ) {}

    public function export(Tenant $tenant, string $period, User $actor): string
    {
        $this->authorize($actor);
        $this->assertActorTenant($actor);

        if ($tenant->id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('tenant_id', (string) $tenant->id);
        }

        $latest = ReconciliationRun::query()
            ->where('period', $period)
            ->orderByDesc('ran_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            throw new RuntimeException("No reconciliation exists for {$period}; run billing:reconcile before exporting.");
        }

        if (! $latest->passed) {
            throw new RuntimeException("The most recent reconciliation for {$period} did not pass; the export is blocked.");
        }

        [$start, $end] = $this->periodBounds($period);

        $rows = [self::COLUMNS];
        foreach ($this->financialRows($start, $end) as $row) {
            $rows[] = $this->orderRow($row);
        }

        $path = sprintf('tenants/%s/billing/exports/%s.csv', $tenant->id, $period);
        Storage::disk('local')->put($path, $this->encode($rows));

        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => 'billing.exported',
            'resource_type' => 'reconciliation_run',
            'resource_id' => $latest->id,
            'context' => [
                'period' => $period,
                'path' => $path,
                'reconciliation_run_id' => $latest->id,
            ],
        ]);

        return $path;
    }

    /**
     * @return list<array<string, string|int>>
     */
    private function financialRows(string $start, string $end): array
    {
        $rows = [];

        $invoices = Invoice::query()
            ->whereBetween('issue_date', [$start, $end])
            ->whereNotNull('number')
            ->orderBy('series')
            ->orderBy('number')
            ->get();

        foreach ($invoices as $invoice) {
            $recordType = $invoice->series === Invoice::SERIES_CREDIT_NOTE ? 'credit_note' : 'invoice';
            $rows[] = [
                'record_type' => $recordType,
                'date' => (string) $invoice->issue_date?->toDateString(),
                'reference' => $invoice->series.'-'.$invoice->number,
                'description' => $recordType === 'credit_note' ? 'Credit note' : 'Invoice',
                'counterparty' => (string) ($invoice->payer_name ?? $invoice->payer_type),
                'currency' => $invoice->currency,
                'net_minor' => (int) $invoice->subtotal_minor,
                'vat_minor' => (int) $invoice->vat_total_minor,
                'vat_rate_bp' => '',
                'gross_minor' => (int) $invoice->total_minor,
            ];

            $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('id')->get();
            foreach ($lines as $line) {
                $vat = $this->vatMinor((int) $line->line_total_minor, (int) $line->vat_rate_bp);
                $rows[] = [
                    'record_type' => $recordType.'_line',
                    'date' => (string) $invoice->issue_date?->toDateString(),
                    'reference' => $invoice->series.'-'.$invoice->number,
                    'description' => $line->code.' '.$line->description,
                    'counterparty' => (string) ($invoice->payer_name ?? $invoice->payer_type),
                    'currency' => $invoice->currency,
                    'net_minor' => (int) $line->line_total_minor,
                    'vat_minor' => $vat,
                    'vat_rate_bp' => (int) $line->vat_rate_bp,
                    'gross_minor' => (int) $line->line_total_minor + $vat,
                ];
            }
        }

        $payments = Payment::query()->whereBetween('received_on', [$start, $end])->orderBy('id')->get();
        foreach ($payments as $payment) {
            $rows[] = [
                'record_type' => 'payment',
                'date' => (string) $payment->received_on->toDateString(),
                'reference' => $payment->id,
                'description' => 'Payment '.$payment->method,
                'counterparty' => (string) ($payment->payer_reference ?? ''),
                'currency' => $payment->currency,
                'net_minor' => (int) $payment->amount_minor,
                'vat_minor' => 0,
                'vat_rate_bp' => '',
                'gross_minor' => (int) $payment->amount_minor,
            ];
        }

        $allocations = PaymentAllocation::query()->whereBetween('allocated_at', [$start.' 00:00:00', $end.' 23:59:59'])->orderBy('id')->get();
        foreach ($allocations as $allocation) {
            $rows[] = [
                'record_type' => $allocation->reverses_allocation_id !== null ? 'allocation_reversal' : 'allocation',
                'date' => (string) $allocation->allocated_at->toDateString(),
                'reference' => $allocation->payment_id.'->'.$allocation->invoice_id,
                'description' => 'Allocation',
                'counterparty' => '',
                'currency' => '',
                'net_minor' => (int) $allocation->amount_minor,
                'vat_minor' => 0,
                'vat_rate_bp' => '',
                'gross_minor' => (int) $allocation->amount_minor,
            ];
        }

        $refunds = Refund::query()->whereBetween('refunded_at', [$start.' 00:00:00', $end.' 23:59:59'])->orderBy('id')->get();
        foreach ($refunds as $refund) {
            $rows[] = [
                'record_type' => 'refund',
                'date' => (string) $refund->refunded_at->toDateString(),
                'reference' => $refund->payment_id,
                'description' => 'Refund',
                'counterparty' => '',
                'currency' => '',
                'net_minor' => (int) $refund->amount_minor,
                'vat_minor' => 0,
                'vat_rate_bp' => '',
                'gross_minor' => (int) $refund->amount_minor,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, string|int>  $row
     * @return list<string|int>
     */
    private function orderRow(array $row): array
    {
        return array_map(fn (string $column): string|int => $row[$column] ?? '', self::COLUMNS);
    }

    /**
     * @param  list<list<string|int>>  $rows
     */
    private function encode(array $rows): string
    {
        $lines = array_map(function (array $row): string {
            return implode(',', array_map(function (string|int $value): string {
                $value = (string) $value;

                return str_contains($value, ',') || str_contains($value, '"')
                    ? '"'.str_replace('"', '""', $value).'"'
                    : $value;
            }, $row));
        }, $rows);

        return implode("\n", $lines)."\n";
    }

    private function vatMinor(int $lineTotalMinor, int $vatRateBp): int
    {
        $rounded = intdiv(abs($lineTotalMinor) * $vatRateBp + 5000, 10000);

        return $lineTotalMinor < 0 ? -1 * $rounded : $rounded;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function periodBounds(string $period): array
    {
        $start = Carbon::parse($period.'-01')->startOfMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    private function authorize(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('billing.manage')) {
            throw new AuthorizationException('This user cannot manage billing.');
        }
    }

    private function assertActorTenant(User $actor): void
    {
        if ($actor->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('actor_id', (string) $actor->id);
        }
    }
}
