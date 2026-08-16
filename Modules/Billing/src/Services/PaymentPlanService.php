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
use Modules\Billing\Models\PaymentPlan;
use Modules\Billing\Models\PaymentPlanInstallment;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

/**
 * ARDETAIL.P5 — OPERATOR-GATED installment payment plans over an account's REAL outstanding balance.
 *
 * THE TIE (no phantom money). A plan may only cover money that is actually owed:
 *  - `total_minor` may not exceed {@see self::accountOutstandingMinor()} — the SAME definition the
 *    ARDETAIL.P1 ledger ties to (series INV + frozen statuses, Σ of the reconciled
 *    `invoice_balances` open balances for the account), so the plan and the ledger agree by
 *    construction; and
 *  - only ONE active plan may exist per account, so two plans can never together schedule more than
 *    the balance.
 * THE PARTITION. The schedule is computed HERE, in the engine, in integer minor units: each
 * installment gets `intdiv(total, n)` and the LAST absorbs the remainder, so
 * `Σ installments === total_minor` EXACTLY (δ=0) — asserted before the plan is persisted, so a
 * schedule that did not partition its total could never reach the database.
 *
 * THE PLAN NEVER MOVES MONEY. Settling an installment ({@see self::payInstallment()}) records the
 * payment through the guarded {@see PaymentService} — `record()` then `allocate()` against the
 * account's open invoices, oldest first, each allocation capped by that invoice's open balance so
 * the service's over-allocation guard is respected rather than probed. Anything the account cannot
 * absorb stays as an unallocated remainder on the payment (the existing semantics; I3 allows it).
 * The installment then records WHICH payment settled it. Nothing here writes a balance.
 *
 * OPERATOR-GATED: every method requires a person holding `billing.manage`. The billing agent has no
 * path here — it never creates a plan and never commits an installment (D-151/D-152).
 */
class PaymentPlanService
{
    /** A sane upper bound on schedule length (a plan is a payment arrangement, not a subscription). */
    public const MAX_INSTALLMENTS = 60;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
        private readonly PaymentService $payments,
        private readonly SettingsService $settings,
    ) {}

    /**
     * The account's outstanding balance in integer minor units — the SAME population the ARDETAIL.P1
     * ledger sums (series INV, frozen statuses, the reconciled `invoice_balances` projection), so
     * `accountLedger()['account_outstanding_minor']` and this agree to the unit.
     */
    public function accountOutstandingMinor(string $patientId): int
    {
        $invoiceIds = Invoice::query()
            ->where('series', Invoice::SERIES_INVOICE)
            ->whereIn('status', [
                Invoice::STATUS_ISSUED,
                Invoice::STATUS_PAID,
                Invoice::STATUS_PARTIALLY_PAID,
                Invoice::STATUS_CANCELLED_BY_CREDIT_NOTE,
            ])
            ->where('patient_id', $patientId)
            ->pluck('id')
            ->all();

        if ($invoiceIds === []) {
            return 0;
        }

        return (int) InvoiceBalance::query()->whereIn('invoice_id', $invoiceIds)->sum('open_balance_minor');
    }

    /** The plan still running for this account, if any (at most one by invariant). */
    public function activePlanFor(string $patientId): ?PaymentPlan
    {
        return PaymentPlan::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', PaymentPlan::OPEN_STATUSES)
            ->first();
    }

    /**
     * The account's active plan, or — when none is running — its most recent one, so a closed
     * agreement stays visible rather than silently disappearing from the account.
     */
    public function currentOrLatestFor(string $patientId): ?PaymentPlan
    {
        return $this->activePlanFor($patientId) ?? PaymentPlan::query()
            ->where('patient_id', $patientId)
            ->orderByDesc('agreed_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Create a plan covering `$totalMinor` of the account's outstanding, split into
     * `$installmentCount` monthly installments from `$startDate`.
     *
     * Refuses a total above the real outstanding and a second active plan — together these mean a
     * plan can never schedule money that is not owed.
     */
    public function create(
        Patient $patient,
        int $totalMinor,
        int $installmentCount,
        CarbonInterface|string $startDate,
        User $actor,
    ): PaymentPlan {
        $this->authorize($actor);
        $this->assertActorTenant($actor);
        $this->assertSameTenant($patient, 'patient_id');

        if ($totalMinor <= 0) {
            throw new InvalidArgumentException('A payment plan total must be a positive integer in minor units.');
        }
        if ($installmentCount < 1 || $installmentCount > self::MAX_INSTALLMENTS) {
            throw new InvalidArgumentException('A payment plan must have between 1 and '.self::MAX_INSTALLMENTS.' installments.');
        }
        if ($installmentCount > $totalMinor) {
            // Every installment must carry at least one minor unit — otherwise the partition would
            // need a zero (or negative) line, which the schema forbids.
            throw new InvalidArgumentException('A payment plan cannot have more installments than minor units to divide.');
        }

        return DB::transaction(function () use ($patient, $totalMinor, $installmentCount, $startDate, $actor): PaymentPlan {
            if ($this->activePlanFor((string) $patient->id) !== null) {
                throw new InvalidArgumentException('This account already has an active payment plan.');
            }

            // THE TIE: never schedule more than the account actually owes.
            $outstanding = $this->accountOutstandingMinor((string) $patient->id);
            if ($outstanding <= 0) {
                throw new InvalidArgumentException('This account has no outstanding balance to schedule.');
            }
            if ($totalMinor > $outstanding) {
                throw new InvalidArgumentException('A payment plan cannot schedule more than the account outstanding balance.');
            }

            $start = $this->date($startDate);
            $plan = PaymentPlan::query()->create([
                'patient_id' => $patient->id,
                'total_minor' => $totalMinor,
                'currency' => (string) $this->settings->get('currency', 'EUR'),
                'installment_count' => $installmentCount,
                'start_date' => $start->toDateString(),
                'status' => PaymentPlan::STATUS_ACTIVE,
                'outstanding_at_creation_minor' => $outstanding,
                'created_by' => $actor->id,
                'agreed_at' => now(),
            ]);

            foreach ($this->partition($totalMinor, $installmentCount) as $index => $amountMinor) {
                PaymentPlanInstallment::query()->create([
                    'payment_plan_id' => $plan->id,
                    'sequence' => $index + 1,
                    'due_date' => $start->copy()->addMonthsNoOverflow($index)->toDateString(),
                    'amount_minor' => $amountMinor,
                    'status' => PaymentPlanInstallment::STATUS_PENDING,
                ]);
            }

            // Belt and braces: the persisted schedule must partition the total exactly (δ=0).
            $scheduled = (int) PaymentPlanInstallment::query()->where('payment_plan_id', $plan->id)->sum('amount_minor');
            if ($scheduled !== $totalMinor) {
                throw new InvalidArgumentException('The installment schedule does not sum to the plan total.');
            }

            $this->auditPlan('billing.payment_plan_created', $plan, $actor, [
                'installment_count' => $installmentCount,
                'scheduled_minor' => $scheduled,
                'outstanding_at_creation_minor' => $outstanding,
                'start_date' => $start->toDateString(),
            ]);

            return $plan->refresh();
        }, 5);
    }

    /**
     * Settle an installment by recording a REAL payment through the guarded PaymentService and
     * allocating it to the account's open invoices (oldest first, each capped by that invoice's open
     * balance). Returns the Payment the ledger recorded.
     */
    public function payInstallment(
        PaymentPlanInstallment $installment,
        string $method,
        CarbonInterface|string $receivedOn,
        User $actor,
        ?string $reference = null,
    ): Payment {
        $this->authorize($actor);
        $this->assertActorTenant($actor);
        $this->assertSameTenant($installment, 'installment_id');

        $plan = PaymentPlan::query()->whereKey($installment->payment_plan_id)->firstOrFail();
        if (! $plan->isActive()) {
            throw new InvalidArgumentException('Only an active payment plan can take an installment payment.');
        }

        // Re-read the persisted installment under a lock so the same one cannot be settled twice.
        $payment = DB::transaction(function () use ($installment, $plan, $method, $receivedOn, $actor, $reference): Payment {
            $locked = PaymentPlanInstallment::query()->whereKey($installment->id)->lockForUpdate()->firstOrFail();
            if ($locked->isPaid()) {
                throw new InvalidArgumentException('This installment has already been paid.');
            }

            $patient = Patient::query()->whereKey($plan->patient_id)->firstOrFail();

            // THE MONEY MOVES ONLY HERE, through the guarded service (ARDETAIL.P4).
            $payment = $this->payments->record(
                $locked->amount_minor,
                $method,
                $actor,
                $patient,
                null,
                $plan->currency,
                $receivedOn,
                $reference,
            );

            $this->allocateOldestFirst($payment, (string) $patient->id, $locked->amount_minor, $actor);

            $locked->forceFill([
                'status' => PaymentPlanInstallment::STATUS_PAID,
                'payment_id' => $payment->id,
                'paid_at' => now(),
            ])->save();

            // The plan completes when nothing is left pending.
            $pending = PaymentPlanInstallment::query()
                ->where('payment_plan_id', $plan->id)
                ->where('status', PaymentPlanInstallment::STATUS_PENDING)
                ->count();
            if ($pending === 0) {
                $plan->forceFill(['status' => PaymentPlan::STATUS_COMPLETED, 'closed_at' => now()])->save();
            }

            $this->auditPlan('billing.payment_plan_installment_paid', $plan, $actor, [
                'installment_id' => $locked->id,
                'sequence' => $locked->sequence,
                'amount_minor' => $locked->amount_minor,
                'payment_id' => $payment->id,
                'plan_status' => $plan->status,
            ]);

            return $payment;
        }, 5);

        return $payment->refresh();
    }

    /** Cancel a running plan (an agreement that ended). Reason required; audited. */
    public function cancel(PaymentPlan $plan, string $reason, User $actor): PaymentPlan
    {
        return $this->close($plan, PaymentPlan::STATUS_CANCELLED, $reason, $actor, 'billing.payment_plan_cancelled');
    }

    /** Mark a running plan defaulted (the patient stopped paying). Reason required; audited. */
    public function markDefaulted(PaymentPlan $plan, string $reason, User $actor): PaymentPlan
    {
        return $this->close($plan, PaymentPlan::STATUS_DEFAULTED, $reason, $actor, 'billing.payment_plan_defaulted');
    }

    /**
     * The plan + its schedule, presented for display. Every figure is a recorded fact or an engine
     * total — the page performs no money math over it.
     *
     * @return array<string, mixed>|null
     */
    public function present(?PaymentPlan $plan, CarbonInterface|string $asOf): ?array
    {
        if ($plan === null) {
            return null;
        }

        $asOfDate = Carbon::parse($asOf instanceof CarbonInterface ? $asOf->toDateString() : $asOf)->startOfDay();
        $installments = PaymentPlanInstallment::query()
            ->where('payment_plan_id', $plan->id)
            ->orderBy('sequence')
            ->get();

        $paidMinor = (int) $installments->where('status', PaymentPlanInstallment::STATUS_PAID)->sum('amount_minor');
        $scheduledMinor = (int) $installments->sum('amount_minor');

        return [
            'id' => $plan->id,
            'status' => $plan->status,
            'total_minor' => $plan->total_minor,
            'currency' => $plan->currency,
            'installment_count' => $plan->installment_count,
            'start_date' => $plan->start_date->toDateString(),
            'outstanding_at_creation_minor' => $plan->outstanding_at_creation_minor,
            'paid_minor' => $paidMinor,
            'remaining_minor' => $scheduledMinor - $paidMinor,
            'closed_reason' => $plan->closed_reason,
            // The schedule PARTITIONS the total exactly — surfaced so the page can show the tie
            // rather than compute one.
            'ties' => $scheduledMinor === $plan->total_minor,
            'installments' => $installments->map(fn (PaymentPlanInstallment $i): array => [
                'id' => $i->id,
                'sequence' => $i->sequence,
                'due_date' => $i->due_date->toDateString(),
                'amount_minor' => $i->amount_minor,
                'status' => $i->status,
                'overdue' => $i->isOverdue($asOfDate),
                'paid_on' => $i->paid_at?->toDateString(),
            ])->values()->all(),
        ];
    }

    /**
     * Split an integer minor total into `$count` parts: every part gets the integer quotient and the
     * LAST absorbs the remainder, so the parts sum to the total EXACTLY (no rounding drift, no
     * invented or lost minor unit).
     *
     * @return list<int>
     */
    private function partition(int $totalMinor, int $count): array
    {
        $base = intdiv($totalMinor, $count);
        $parts = array_fill(0, $count, $base);
        $parts[$count - 1] = $totalMinor - $base * ($count - 1);

        return $parts;
    }

    /**
     * Allocate a recorded payment across the account's open invoices, oldest first, each allocation
     * capped by that invoice's own open balance. The guarded service still validates every call;
     * capping means we respect the guard rather than probe it. Any amount the account cannot absorb
     * stays as an unallocated remainder on the payment.
     */
    private function allocateOldestFirst(Payment $payment, string $patientId, int $amountMinor, User $actor): void
    {
        $invoices = Invoice::query()
            ->where('series', Invoice::SERIES_INVOICE)
            ->where('patient_id', $patientId)
            ->where('currency', $payment->currency)
            ->orderBy('issue_date')
            ->orderBy('number')
            ->orderBy('id')
            ->get();

        $remaining = $amountMinor;
        foreach ($invoices as $invoice) {
            if ($remaining <= 0) {
                break;
            }

            $balance = InvoiceBalance::query()->where('invoice_id', $invoice->id)->first();
            if (! $balance instanceof InvoiceBalance
                || ! in_array($balance->status, [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID], true)) {
                continue;
            }

            $open = $this->payments->openBalance($invoice);
            if ($open <= 0) {
                continue;
            }

            $apply = min($remaining, $open);
            $this->payments->allocate($payment, $invoice, $apply, $actor);
            $remaining -= $apply;
        }
    }

    private function close(PaymentPlan $plan, string $status, string $reason, User $actor, string $auditAction): PaymentPlan
    {
        $this->authorize($actor);
        $this->assertActorTenant($actor);
        $this->assertSameTenant($plan, 'payment_plan_id');

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Closing a payment plan requires a reason.');
        }
        if (! $plan->isActive()) {
            throw new InvalidArgumentException('Only an active payment plan can be cancelled or defaulted.');
        }

        $plan->forceFill(['status' => $status, 'closed_reason' => $reason, 'closed_at' => now()])->save();

        $this->auditPlan($auditAction, $plan, $actor, ['reason' => $reason]);

        return $plan->refresh();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function auditPlan(string $action, PaymentPlan $plan, User $actor, array $context = []): void
    {
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => $action,
            'patient_id' => $plan->patient_id,
            'resource_type' => 'payment_plan',
            'resource_id' => $plan->id,
            'context' => [
                'payment_plan_id' => $plan->id,
                'total_minor' => (int) $plan->total_minor,
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

    private function date(CarbonInterface|string $date): Carbon
    {
        return ($date instanceof CarbonInterface ? Carbon::instance($date) : Carbon::parse($date))->startOfDay();
    }
}
