<?php

namespace Modules\Billing\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceAdjustment;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * BILLAR.P1 — write-offs (bad debt) + contractual adjustments (insurer-agreed rate reconciliation)
 * as OPERATOR-GATED, APPEND-ONLY, reconciling ledger movements against an invoice.
 *
 * Every movement is an append-only {@see InvoiceAdjustment} row (integer minor units) that REDUCES
 * the invoice open balance and is refreshed into the `invoice_balances` projection via
 * {@see PaymentService::refreshInvoiceBalance()} — so the balance is `total − net allocations − net
 * adjustments` and the {@see ReconciliationEngine}'s I2/I6 tie out to the unit. Corrections are
 * reversal rows (the exact negative), never mutations.
 *
 * OPERATOR-GATED: every method requires a person holding `billing.manage` (the money-commit gate) —
 * the billing agent has no path here, so it can never write one (the fence: the agent drafts, a
 * person commits money movements). The over-adjustment guard (concurrency-safe, FOR UPDATE) forbids
 * reducing the balance below zero, exactly like the payment over-allocation guard.
 */
class AdjustmentService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
        private readonly PaymentService $payments,
    ) {}

    /** Write off (bad debt) part or all of an invoice's open balance. Reason required. */
    public function writeOff(Invoice $invoice, int $amountMinor, string $reason, User $actor): InvoiceAdjustment
    {
        return $this->post($invoice, InvoiceAdjustment::TYPE_WRITE_OFF, $amountMinor, $reason, null, $actor, 'billing.written_off');
    }

    /** Reconcile a charge to an insurer-agreed rate. Reason required; an agreement reference optional. */
    public function contractualAdjustment(Invoice $invoice, int $amountMinor, string $reason, ?string $reference, User $actor): InvoiceAdjustment
    {
        return $this->post($invoice, InvoiceAdjustment::TYPE_CONTRACTUAL, $amountMinor, $reason, $reference, $actor, 'billing.adjusted');
    }

    /**
     * Correct an adjustment by appending a reversal row (exact negative), restoring the open balance.
     * A reversal is never itself reversed and never mutates the original.
     */
    public function reverse(InvoiceAdjustment $adjustment, string $reason, User $actor): InvoiceAdjustment
    {
        $this->authorize($actor);
        $this->assertActorTenant($actor);
        $this->assertSameTenant($adjustment, 'adjustment_id');

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Reversing an adjustment requires a reason.');
        }

        // Re-read the canonical persisted row — never trust a caller-mutated in-memory instance for the
        // amount being reversed (the row is append-only, so the DB copy is authoritative).
        $adjustment = InvoiceAdjustment::query()->whereKey($adjustment->id)->firstOrFail();

        if ($adjustment->isReversal()) {
            throw new InvalidArgumentException('A reversal row cannot itself be reversed.');
        }

        $invoice = Invoice::query()->whereKey($adjustment->invoice_id)->firstOrFail();

        return DB::transaction(function () use ($adjustment, $invoice, $reason, $actor): InvoiceAdjustment {
            $balance = InvoiceBalance::query()->where('invoice_id', $invoice->id)->lockForUpdate()->firstOrFail();

            $alreadyReversed = InvoiceAdjustment::query()
                ->where('reverses_adjustment_id', $adjustment->id)
                ->lockForUpdate()
                ->exists();
            if ($alreadyReversed) {
                throw new InvalidArgumentException('This adjustment has already been reversed.');
            }

            $reversal = InvoiceAdjustment::query()->create([
                'invoice_id' => $adjustment->invoice_id,
                'type' => $adjustment->type,
                'amount_minor' => -1 * $adjustment->amount_minor,
                'reverses_adjustment_id' => $adjustment->id,
                'reason' => $reason,
                'reference' => $adjustment->reference,
                'created_by' => $actor->id,
                'adjusted_at' => now(),
            ]);

            $this->payments->refreshInvoiceBalance($invoice, $balance);

            $this->auditAdjustment('billing.adjustment_reversed', $invoice, $reversal, $actor, [
                'reverses_adjustment_id' => $adjustment->id,
                'reason' => $reason,
            ]);

            return $reversal;
        }, 5);
    }

    /**
     * The shared append-and-reconcile path for both movement types.
     */
    private function post(Invoice $invoice, string $type, int $amountMinor, string $reason, ?string $reference, User $actor, string $auditAction): InvoiceAdjustment
    {
        $this->authorize($actor);
        $this->assertActorTenant($actor);
        $this->assertSameTenant($invoice, 'invoice_id');

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A billing adjustment requires a reason.');
        }
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('An adjustment amount must be a positive integer in minor units.');
        }

        return DB::transaction(function () use ($invoice, $type, $amountMinor, $reason, $reference, $actor, $auditAction): InvoiceAdjustment {
            $balance = InvoiceBalance::query()->where('invoice_id', $invoice->id)->lockForUpdate()->firstOrFail();

            // A movement may only reduce an issued invoice with an open balance (not a draft, not one
            // already fully cancelled by a credit note).
            if (! in_array($balance->status, [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID], true)) {
                throw new InvalidArgumentException('Only issued invoices with an open balance can be written off or adjusted.');
            }

            // Over-adjustment guard: never reduce the open balance below zero (reconcile-to-the-unit).
            $open = $this->payments->openBalance($invoice);
            if ($amountMinor > $open) {
                throw new InvalidArgumentException('Cannot write off or adjust more than the invoice open balance.');
            }

            $adjustment = InvoiceAdjustment::query()->create([
                'invoice_id' => $invoice->id,
                'type' => $type,
                'amount_minor' => $amountMinor,
                'reverses_adjustment_id' => null,
                'reason' => $reason,
                'reference' => $reference,
                'created_by' => $actor->id,
                'adjusted_at' => now(),
            ]);

            $this->payments->refreshInvoiceBalance($invoice, $balance);

            $this->auditAdjustment($auditAction, $invoice, $adjustment, $actor, [
                'reason' => $reason,
                'reference' => $reference,
                'invoice_open_balance_minor' => $this->payments->openBalance($invoice),
            ]);

            return $adjustment;
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function auditAdjustment(string $action, Invoice $invoice, InvoiceAdjustment $adjustment, User $actor, array $context = []): void
    {
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => $action,
            'patient_id' => $invoice->patient_id,
            'resource_type' => 'invoice_adjustment',
            'resource_id' => $adjustment->id,
            'context' => [
                'invoice_id' => $invoice->id,
                'adjustment_id' => $adjustment->id,
                'type' => $adjustment->type,
                'amount_minor' => (int) $adjustment->amount_minor,
                ...$context,
            ],
        ]);
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
}
