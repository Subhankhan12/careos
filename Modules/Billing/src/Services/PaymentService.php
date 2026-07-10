<?php

namespace Modules\Billing\Services;

use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAllocation;
use Modules\Billing\Models\Refund;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

/**
 * Payments, their allocation against invoices, allocation reversal, and refunds.
 *
 * Every money movement is an APPEND-ONLY row. Balances are never stored as a
 * drifting counter: unallocated(payment) and openBalance(invoice) are derived by
 * exact integer arithmetic over the append-only rows. The mutable
 * `invoice_balances` projection (F.4) is refreshed to that derived value for
 * querying/status; the frozen legal `invoices` row is NEVER touched.
 */
class PaymentService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Append a payment. An overpayment simply leaves a larger unallocated
     * remainder — money is never silently absorbed or auto-applied.
     */
    public function record(
        int $amountMinor,
        string $method,
        User $actor,
        ?Patient $patient = null,
        ?string $payerReference = null,
        ?string $currency = null,
        CarbonInterface|string|null $receivedOn = null,
        ?string $reference = null,
    ): Payment {
        $this->authorize($actor);
        $this->assertActorTenant($actor);

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('A payment amount must be a positive integer in minor units.');
        }

        if (! in_array($method, Payment::METHODS, true)) {
            throw new InvalidArgumentException('Unsupported payment method.');
        }

        if ($patient !== null) {
            $this->assertSameTenant($patient, 'patient_id');
        }

        $payment = Payment::query()->create([
            'patient_id' => $patient?->id,
            'payer_reference' => $payerReference,
            'method' => $method,
            'amount_minor' => $amountMinor,
            'currency' => $currency ?: (string) $this->settings->get('currency', 'EUR'),
            'received_on' => $this->date($receivedOn ?? now()),
            'reference' => $reference,
            'recorded_by' => $actor->id,
        ]);

        $this->auditPayment('payment.recorded', $payment, $actor, [
            'amount_minor' => $payment->amount_minor,
            'method' => $payment->method,
            'currency' => $payment->currency,
        ]);

        return $payment->refresh();
    }

    /**
     * Append an allocation of a payment against an invoice. Serializes on the
     * invoice_balances row (and the payment row) with FOR UPDATE so concurrent
     * allocations can never overshoot the invoice open balance or the payment
     * remainder.
     */
    public function allocate(Payment $payment, Invoice $invoice, int $amountMinor, User $actor): PaymentAllocation
    {
        $this->authorize($actor);
        $this->assertActorTenant($actor);
        $this->assertSameTenant($payment, 'payment_id');
        $this->assertSameTenant($invoice, 'invoice_id');

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('An allocation amount must be a positive integer in minor units.');
        }

        if ($payment->currency !== $invoice->currency) {
            throw new InvalidArgumentException('Payment and invoice currencies must match to allocate.');
        }

        return DB::transaction(function () use ($payment, $invoice, $amountMinor, $actor): PaymentAllocation {
            [$lockedPayment, $balance] = $this->lockPaymentAndBalance($payment, $invoice);

            if (! in_array($balance->status, [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID], true)) {
                throw new InvalidArgumentException('Only issued invoices with an open balance can receive allocations.');
            }

            $open = $this->openBalance($invoice);
            $remainder = $this->unallocated($lockedPayment);

            if ($amountMinor > $open) {
                throw new InvalidArgumentException('Cannot allocate more than the invoice open balance.');
            }

            if ($amountMinor > $remainder) {
                throw new InvalidArgumentException('Cannot allocate more than the payment unallocated remainder.');
            }

            $allocation = PaymentAllocation::query()->create([
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount_minor' => $amountMinor,
                'reverses_allocation_id' => null,
                'reason' => null,
                'allocated_by' => $actor->id,
                'allocated_at' => now(),
            ]);

            $this->refreshInvoiceBalance($invoice, $balance);

            $this->auditPayment('payment.allocated', $payment, $actor, [
                'invoice_id' => $invoice->id,
                'allocation_id' => $allocation->id,
                'amount_minor' => $amountMinor,
                'invoice_open_balance_minor' => $this->openBalance($invoice),
            ], $invoice->patient_id);

            return $allocation;
        }, 5);
    }

    /**
     * De-allocate by appending a reversal row (exact negative of the original),
     * restoring both the payment remainder and the invoice open balance.
     */
    public function reverseAllocation(PaymentAllocation $allocation, string $reason, User $actor): PaymentAllocation
    {
        $this->authorize($actor);
        $this->assertActorTenant($actor);
        $this->assertSameTenant($allocation, 'allocation_id');

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Reversing an allocation requires a reason.');
        }

        if ($allocation->isReversal()) {
            throw new InvalidArgumentException('A reversal row cannot itself be reversed.');
        }

        $payment = Payment::query()->whereKey($allocation->payment_id)->firstOrFail();
        $invoice = Invoice::query()->whereKey($allocation->invoice_id)->firstOrFail();

        return DB::transaction(function () use ($allocation, $payment, $invoice, $reason, $actor): PaymentAllocation {
            [, $balance] = $this->lockPaymentAndBalance($payment, $invoice);

            $alreadyReversed = PaymentAllocation::query()
                ->where('reverses_allocation_id', $allocation->id)
                ->lockForUpdate()
                ->exists();

            if ($alreadyReversed) {
                throw new InvalidArgumentException('This allocation has already been reversed.');
            }

            $reversal = PaymentAllocation::query()->create([
                'payment_id' => $allocation->payment_id,
                'invoice_id' => $allocation->invoice_id,
                'amount_minor' => -1 * $allocation->amount_minor,
                'reverses_allocation_id' => $allocation->id,
                'reason' => $reason,
                'allocated_by' => $actor->id,
                'allocated_at' => now(),
            ]);

            $this->refreshInvoiceBalance($invoice, $balance);

            $this->auditPayment('payment.allocation_reversed', $payment, $actor, [
                'invoice_id' => $invoice->id,
                'reversed_allocation_id' => $allocation->id,
                'reversal_id' => $reversal->id,
                'amount_minor' => $reversal->amount_minor,
                'reason' => $reason,
                'invoice_open_balance_minor' => $this->openBalance($invoice),
            ], $invoice->patient_id);

            return $reversal;
        }, 5);
    }

    /**
     * Append a refund row. Refunds may draw only on the payment's unallocated
     * remainder; to refund money already applied to an invoice, reverse that
     * allocation first (an explicit, audited step) so the invoice balance and the
     * refund can never silently disagree.
     */
    public function refund(Payment $payment, int $amountMinor, string $reason, User $actor): Refund
    {
        $this->authorize($actor);
        $this->assertActorTenant($actor);
        $this->assertSameTenant($payment, 'payment_id');

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A refund requires a reason.');
        }

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('A refund amount must be a positive integer in minor units.');
        }

        return DB::transaction(function () use ($payment, $amountMinor, $reason, $actor): Refund {
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($amountMinor > $this->unallocated($lockedPayment)) {
                throw new InvalidArgumentException('Cannot refund more than the payment unallocated remainder.');
            }

            $refund = Refund::query()->create([
                'payment_id' => $payment->id,
                'amount_minor' => $amountMinor,
                'reason' => $reason,
                'refunded_by' => $actor->id,
                'refunded_at' => now(),
            ]);

            $this->auditPayment('payment.refunded', $payment, $actor, [
                'refund_id' => $refund->id,
                'amount_minor' => $amountMinor,
                'reason' => $reason,
                'unallocated_remainder_minor' => $this->unallocated($lockedPayment),
            ]);

            return $refund;
        }, 5);
    }

    /**
     * Spendable remainder of a payment: amount minus net allocations (reversals
     * net out) minus refunds. Derived, exact, never stored-and-drifting.
     */
    public function unallocated(Payment $payment): int
    {
        $netAllocated = (int) PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->sum('amount_minor');

        $refunded = (int) Refund::query()
            ->where('payment_id', $payment->id)
            ->sum('amount_minor');

        return $payment->amount_minor - $netAllocated - $refunded;
    }

    /**
     * Open balance of an invoice: its economic total minus net allocations
     * applied to it (reversals net out). Derived, exact, never drifting.
     */
    public function openBalance(Invoice $invoice): int
    {
        $netAllocated = (int) PaymentAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->sum('amount_minor');

        return $invoice->total_minor - $netAllocated;
    }

    /**
     * @return array{0: Payment, 1: InvoiceBalance}
     */
    private function lockPaymentAndBalance(Payment $payment, Invoice $invoice): array
    {
        $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
        $balance = InvoiceBalance::query()->where('invoice_id', $invoice->id)->lockForUpdate()->firstOrFail();

        return [$lockedPayment, $balance];
    }

    private function refreshInvoiceBalance(Invoice $invoice, InvoiceBalance $balance): void
    {
        $open = $this->openBalance($invoice);

        $status = match (true) {
            $open <= 0 => Invoice::STATUS_PAID,
            $open >= $invoice->total_minor => Invoice::STATUS_ISSUED,
            default => Invoice::STATUS_PARTIALLY_PAID,
        };

        $balance->forceFill([
            'open_balance_minor' => $open,
            'status' => $status,
        ])->save();
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

    private function assertSameTenant(object $model, string $attribute): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }

    private function date(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface
            ? Carbon::instance($date)->toDateString()
            : Carbon::parse($date)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function auditPayment(string $action, Payment $payment, User $actor, array $context = [], ?string $patientId = null): void
    {
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => $action,
            'patient_id' => $patientId ?? $payment->patient_id,
            'resource_type' => 'payment',
            'resource_id' => $payment->id,
            'context' => [
                'payment_id' => $payment->id,
                ...$context,
            ],
        ]);
    }
}
