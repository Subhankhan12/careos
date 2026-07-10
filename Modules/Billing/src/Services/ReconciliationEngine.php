<?php

namespace Modules\Billing\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Billing\Models\InvoiceLine;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAllocation;
use Modules\Billing\Models\ReconciliationRun;
use Modules\Billing\Models\Refund;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * The reconciliation engine: the invariants that make invoice-based billing
 * trustworthy, checked in EXACT integer arithmetic. A single minor unit of drift
 * in any invariant fails the run and reports the exact offending rows.
 *
 * All VAT is recomputed per D-F3 (per-line round-half-up, then summed — never
 * round a sum).
 */
class ReconciliationEngine
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
    ) {}

    /**
     * Authorize, compute the report, persist the append-only monthly-close row,
     * and audit. Returns the persisted run.
     */
    public function run(Tenant $tenant, string $period, User $actor): ReconciliationRun
    {
        $this->authorize($actor);
        $this->assertActorTenant($actor);

        if ($tenant->id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('tenant_id', (string) $tenant->id);
        }

        $report = $this->check($period);

        $run = ReconciliationRun::query()->create([
            'period' => $period,
            'ran_at' => now(),
            'passed' => $report['passed'],
            'report' => $report,
            'ran_by' => $actor->id,
        ]);

        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => 'billing.reconciled',
            'resource_type' => 'reconciliation_run',
            'resource_id' => $run->id,
            'context' => [
                'period' => $period,
                'passed' => $report['passed'],
                'failed_invariants' => array_values(array_map(
                    fn (array $inv): string => $inv['invariant'],
                    array_filter($report['invariants'], fn (array $inv): bool => ! $inv['ok']),
                )),
            ],
        ]);

        return $run->refresh();
    }

    /**
     * Pure computation of the reconciliation report for a period. No auth, no
     * persistence — relies on the current tenant context for scoping.
     *
     * @return array{period: string, passed: bool, invariants: list<array<string, mixed>>}
     */
    public function check(string $period): array
    {
        [$start, $end] = $this->periodBounds($period);

        $invariants = [
            $this->checkI1($start, $end),
            $this->checkI2($start, $end),
            $this->checkI3($start, $end),
            $this->checkI4($start, $end),
            $this->checkI5($start, $end),
            $this->checkI6($start, $end),
        ];

        $passed = array_reduce($invariants, fn (bool $carry, array $inv): bool => $carry && $inv['ok'], true);

        return [
            'period' => $period,
            'passed' => $passed,
            'invariants' => $invariants,
        ];
    }

    /**
     * I1 — For every issued invoice/credit-note: total_minor equals
     * sum(line_total_minor) + sum(per-line VAT), VAT recomputed per D-F3.
     */
    private function checkI1(string $start, string $end): array
    {
        $expected = 0;
        $actual = 0;
        $rows = [];

        foreach ($this->issuedDocuments($start, $end) as $invoice) {
            $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->get();
            $recomputed = 0;
            foreach ($lines as $line) {
                $recomputed += (int) $line->line_total_minor + $this->vatMinor((int) $line->line_total_minor, (int) $line->vat_rate_bp);
            }

            $stored = (int) $invoice->total_minor;
            $expected += $recomputed;
            $actual += $stored;

            if ($stored !== $recomputed) {
                $rows[] = [
                    'type' => 'invoice',
                    'id' => $invoice->id,
                    'ref' => $invoice->series.'-'.$invoice->number,
                    'expected_minor' => $recomputed,
                    'actual_minor' => $stored,
                    'delta_minor' => $stored - $recomputed,
                ];
            }
        }

        return $this->invariant('I1', 'issued total equals sum(line totals) + sum(per-line VAT) [D-F3]', $expected, $actual, $rows);
    }

    /**
     * I2 — For every issued invoice, the projection equals the derivation:
     * invoice_balances.open_balance_minor == total_minor − net allocations
     * (0 if cancelled by credit note), and 0 <= open <= total.
     */
    private function checkI2(string $start, string $end): array
    {
        $expected = 0;
        $actual = 0;
        $rows = [];

        foreach ($this->issuedInvoices($start, $end) as $invoice) {
            $balance = InvoiceBalance::query()->where('invoice_id', $invoice->id)->first();
            $projection = $balance !== null ? (int) $balance->open_balance_minor : 0;
            $netAllocated = (int) PaymentAllocation::query()->where('invoice_id', $invoice->id)->sum('amount_minor');

            $derived = $balance !== null && $balance->status === Invoice::STATUS_CANCELLED_BY_CREDIT_NOTE
                ? 0
                : (int) $invoice->total_minor - $netAllocated;

            $expected += $derived;
            $actual += $projection;

            $withinBounds = $projection >= 0 && $projection <= (int) $invoice->total_minor;

            if ($projection !== $derived || ! $withinBounds) {
                $rows[] = [
                    'type' => 'invoice',
                    'id' => $invoice->id,
                    'ref' => $invoice->series.'-'.$invoice->number,
                    'expected_minor' => $derived,
                    'actual_minor' => $projection,
                    'delta_minor' => $projection - $derived,
                    'total_minor' => (int) $invoice->total_minor,
                    'within_bounds' => $withinBounds,
                ];
            }
        }

        return $this->invariant('I2', 'invoice_balances projection equals derived open balance and is within [0, total]', $expected, $actual, $rows);
    }

    /**
     * I3 — For every payment: amount_minor == net allocated + refunded +
     * unallocated remainder, with remainder >= 0.
     */
    private function checkI3(string $start, string $end): array
    {
        $expected = 0;
        $actual = 0;
        $rows = [];

        $payments = Payment::query()
            ->whereBetween('received_on', [$start, $end])
            ->get();

        foreach ($payments as $payment) {
            $netAllocated = (int) PaymentAllocation::query()->where('payment_id', $payment->id)->sum('amount_minor');
            $refunded = (int) Refund::query()->where('payment_id', $payment->id)->sum('amount_minor');
            $used = $netAllocated + $refunded;
            $remainder = (int) $payment->amount_minor - $used;

            // The accounted side counts the legitimate non-negative remainder,
            // so actual equals expected exactly unless money is over-committed:
            // a clean month reconciles with delta_minor === 0.
            $expected += (int) $payment->amount_minor;
            $actual += $used + max(0, $remainder);

            if ($remainder < 0) {
                $rows[] = [
                    'type' => 'payment',
                    'id' => $payment->id,
                    'amount_minor' => (int) $payment->amount_minor,
                    'net_allocated_minor' => $netAllocated,
                    'refunded_minor' => $refunded,
                    'remainder_minor' => $remainder,
                    'delta_minor' => $remainder,
                ];
            }
        }

        return $this->invariant('I3', 'payment amount equals net allocated + refunded + remainder, remainder >= 0', $expected, $actual, $rows);
    }

    /**
     * I4 — Period totals: sum of issued (non-credit-note) invoice totals equals
     * sum of invoiced charges' line totals + VAT; every invoiced charge appears
     * on exactly one non-credit-note invoice (none double-invoiced, none lost).
     */
    private function checkI4(string $start, string $end): array
    {
        $rows = [];

        $invoices = $this->issuedInvoices($start, $end);
        $invoiceIds = $invoices->pluck('id')->all();
        $invoiceTotal = (int) $invoices->sum('total_minor');

        $charges = Charge::query()
            ->where('status', Charge::STATUS_INVOICED)
            ->whereIn('invoice_id', $invoiceIds)
            ->get();

        $chargeTotal = 0;
        $allInvoiceIds = Invoice::query()->where('series', Invoice::SERIES_INVOICE)->pluck('id')->all();

        foreach ($charges as $charge) {
            $chargeTotal += (int) $charge->line_total_minor + $this->vatMinor((int) $charge->line_total_minor, (int) $charge->vat_rate_bp);

            $lineCount = InvoiceLine::query()
                ->where('charge_id', $charge->id)
                ->whereIn('invoice_id', $allInvoiceIds)
                ->count();

            if ($lineCount !== 1) {
                $rows[] = [
                    'type' => 'charge',
                    'id' => $charge->id,
                    'reason' => $lineCount > 1 ? 'double_invoiced' : 'lost',
                    'non_credit_note_line_count' => $lineCount,
                    'delta_minor' => (int) $charge->line_total_minor,
                ];
            }
        }

        // Charges marked invoiced but with no invoice at all — money lost.
        $orphanInvoiced = Charge::query()
            ->where('status', Charge::STATUS_INVOICED)
            ->whereNull('invoice_id')
            ->get();
        foreach ($orphanInvoiced as $charge) {
            $rows[] = [
                'type' => 'charge',
                'id' => $charge->id,
                'reason' => 'invoiced_without_invoice',
                'delta_minor' => (int) $charge->line_total_minor,
            ];
        }

        if ($invoiceTotal !== $chargeTotal) {
            $rows[] = [
                'type' => 'period_total',
                'expected_minor' => $invoiceTotal,
                'actual_minor' => $chargeTotal,
                'delta_minor' => $chargeTotal - $invoiceTotal,
            ];
        }

        return $this->invariant('I4', 'issued invoice totals equal invoiced-charge totals; each invoiced charge on exactly one invoice', $invoiceTotal, $chargeTotal, $rows);
    }

    /**
     * I5 — Credit notes: each references a real original of the same tenant, and
     * the total credited against an original never exceeds the original total.
     */
    private function checkI5(string $start, string $end): array
    {
        $rows = [];
        $expected = 0;
        $actual = 0;

        $creditNotes = Invoice::query()
            ->where('series', Invoice::SERIES_CREDIT_NOTE)
            ->whereIn('status', $this->frozenStatuses())
            ->whereBetween('issue_date', [$start, $end])
            ->get();

        $originalsSeen = [];

        foreach ($creditNotes as $creditNote) {
            $original = $creditNote->credit_note_for_invoice_id !== null
                ? Invoice::query()->whereKey($creditNote->credit_note_for_invoice_id)->first()
                : null;

            if ($original === null) {
                // Orphan credits are pure drift: they inflate the actual side.
                $actual += abs((int) $creditNote->total_minor);
                $rows[] = [
                    'type' => 'credit_note',
                    'id' => $creditNote->id,
                    'ref' => $creditNote->series.'-'.$creditNote->number,
                    'reason' => 'orphan_credit_note',
                    'delta_minor' => abs((int) $creditNote->total_minor),
                ];

                continue;
            }

            if (in_array($original->id, $originalsSeen, true)) {
                continue;
            }
            $originalsSeen[] = $original->id;

            $creditedTotal = abs((int) Invoice::query()
                ->where('credit_note_for_invoice_id', $original->id)
                ->whereIn('status', $this->frozenStatuses())
                ->sum('total_minor'));

            // Drift semantics: only credit BEYOND the original counts, so a
            // legitimate partial credit reconciles with delta_minor === 0.
            $actual += max(0, $creditedTotal - (int) $original->total_minor);

            if ($creditedTotal > (int) $original->total_minor) {
                $rows[] = [
                    'type' => 'invoice',
                    'id' => $original->id,
                    'ref' => $original->series.'-'.$original->number,
                    'reason' => 'credit_exceeds_original',
                    'original_total_minor' => (int) $original->total_minor,
                    'credited_total_minor' => $creditedTotal,
                    'delta_minor' => $creditedTotal - (int) $original->total_minor,
                ];
            }
        }

        return $this->invariant('I5', 'credit notes reference a real original and never exceed it (actual counts excess credit only)', $expected, $actual, $rows);
    }

    /**
     * I6 — No orphan money: every allocation references an existing payment and
     * invoice of the same tenant; every reversal references a real, non-reversed
     * allocation; every refund references an existing payment.
     */
    private function checkI6(string $start, string $end): array
    {
        $rows = [];
        $orphanMinor = 0;

        $invoiceIds = $this->issuedDocuments($start, $end)->pluck('id')->all();

        $allocations = PaymentAllocation::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->get();

        foreach ($allocations as $allocation) {
            $paymentExists = Payment::query()->whereKey($allocation->payment_id)->exists();
            $invoiceExists = Invoice::query()->whereKey($allocation->invoice_id)->exists();

            if (! $paymentExists || ! $invoiceExists) {
                $orphanMinor += abs((int) $allocation->amount_minor);
                $rows[] = [
                    'type' => 'allocation',
                    'id' => $allocation->id,
                    'reason' => ! $paymentExists ? 'missing_payment' : 'missing_invoice',
                    'amount_minor' => (int) $allocation->amount_minor,
                    'delta_minor' => abs((int) $allocation->amount_minor),
                ];

                continue;
            }

            if ($allocation->reverses_allocation_id !== null) {
                $target = PaymentAllocation::query()->whereKey($allocation->reverses_allocation_id)->first();
                $reverserCount = PaymentAllocation::query()
                    ->where('reverses_allocation_id', $allocation->reverses_allocation_id)
                    ->count();

                if ($target === null || $target->reverses_allocation_id !== null || $reverserCount > 1) {
                    $orphanMinor += abs((int) $allocation->amount_minor);
                    $rows[] = [
                        'type' => 'reversal',
                        'id' => $allocation->id,
                        'reason' => $target === null ? 'missing_target' : ($target->reverses_allocation_id !== null ? 'target_is_reversal' : 'target_double_reversed'),
                        'reverses_allocation_id' => $allocation->reverses_allocation_id,
                        'amount_minor' => (int) $allocation->amount_minor,
                        'delta_minor' => abs((int) $allocation->amount_minor),
                    ];
                }
            }
        }

        $paymentIds = Payment::query()
            ->whereBetween('received_on', [$start, $end])
            ->pluck('id')
            ->all();

        $refunds = Refund::query()->whereIn('payment_id', $paymentIds)->get();
        foreach ($refunds as $refund) {
            if (! Payment::query()->whereKey($refund->payment_id)->exists()) {
                $orphanMinor += abs((int) $refund->amount_minor);
                $rows[] = [
                    'type' => 'refund',
                    'id' => $refund->id,
                    'reason' => 'missing_payment',
                    'amount_minor' => (int) $refund->amount_minor,
                    'delta_minor' => abs((int) $refund->amount_minor),
                ];
            }
        }

        return $this->invariant('I6', 'no orphan money: allocations, reversals, and refunds reference real same-tenant rows', 0, $orphanMinor, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function invariant(string $key, string $description, int $expected, int $actual, array $rows): array
    {
        return [
            'invariant' => $key,
            'description' => $description,
            'expected_minor' => $expected,
            'actual_minor' => $actual,
            'delta_minor' => $actual - $expected,
            'ok' => $rows === [],
            'rows' => $rows,
        ];
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function issuedInvoices(string $start, string $end)
    {
        return Invoice::query()
            ->where('series', Invoice::SERIES_INVOICE)
            ->whereIn('status', $this->frozenStatuses())
            ->whereBetween('issue_date', [$start, $end])
            ->get();
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function issuedDocuments(string $start, string $end)
    {
        return Invoice::query()
            ->whereIn('status', $this->frozenStatuses())
            ->whereBetween('issue_date', [$start, $end])
            ->get();
    }

    /**
     * @return list<string>
     */
    private function frozenStatuses(): array
    {
        return [
            Invoice::STATUS_ISSUED,
            Invoice::STATUS_PAID,
            Invoice::STATUS_PARTIALLY_PAID,
            Invoice::STATUS_CANCELLED_BY_CREDIT_NOTE,
        ];
    }

    /**
     * VAT per D-F3: per-line round-half-up on the absolute value, sign restored.
     */
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
