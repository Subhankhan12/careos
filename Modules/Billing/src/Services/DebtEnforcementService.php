<?php

namespace Modules\Billing\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\DebtEnforcementEscalation;
use Modules\Billing\Models\DunningEvent;
use Modules\Billing\Models\Invoice;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

/**
 * ARDETAIL.P6 — debt-enforcement (Swiss *Betreibung*) escalation: the sharpest fence on the AR page.
 *
 * A Betreibung is a REAL LEGAL PROCEEDING. Four things hold, and each is enforced here rather than
 * displayed:
 *
 *  1. **OPERATOR-ONLY.** Every method gates on `billing.escalate` — deliberately NARROWER than
 *     `billing.manage`, which charge-capturing clinical roles (pharmacist, surgical scheduler, ED,
 *     lab, radiology) also hold. Only the billing office and the org admin may start a legal action.
 *  2. **EXPLICIT CONFIRMATION + REASON.** {@see self::initiate()} refuses unless the caller passes an
 *     explicit operator confirmation AND a non-empty reason; the confirmation moment is recorded. It
 *     is a deliberate human act, never a one-click default.
 *  3. **ELIGIBILITY.** Only an account whose dunning process is EXHAUSTED may be escalated: it must
 *     have reached the TERMINAL configured dunning level (the real P2 state machine's last level) on
 *     at least one invoice, and still owe money. With no dunning policy configured NOTHING is
 *     eligible — fail-closed, because you cannot exhaust a process that does not exist.
 *  4. **AGENT-EXCLUDED BY CONSTRUCTION.** There is no agent path to this service: no registered
 *     `AiTool` is an escalation capability, no AiCore code references this class, its model or its
 *     routes, and nothing schedules or automates it — the ONLY callers are the operator-gated
 *     controller actions. The agent may DRAFT dunning reminders through the existing cap/ApprovalQueue
 *     path; escalation to legal enforcement is a human act. "0 auto-escalated" is therefore structural.
 *
 * Every act is AUDITED and APPEND-ONLY: withdrawing appends a new record superseding the escalation,
 * never mutating it, so the history of a legal action can never be rewritten.
 */
class DebtEnforcementService
{
    /** The permission that may start a legal proceeding — narrower than `billing.manage` on purpose. */
    public const PERMISSION = 'billing.escalate';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
        private readonly SettingsService $settings,
        private readonly PaymentPlanService $plans,
    ) {}

    /**
     * The TERMINAL dunning level of the tenant's configured policy — the stage after which the dunning
     * process is exhausted. Null when no policy/levels are configured (nothing can then be eligible).
     */
    public function terminalDunningStage(): ?int
    {
        $policy = $this->settings->get(DunningService::SETTINGS_KEY, []);
        $levels = is_array($policy) && is_array($policy['levels'] ?? null) ? $policy['levels'] : [];

        $numbers = [];
        foreach ($levels as $level) {
            if (is_array($level) && isset($level['level'])) {
                $numbers[] = (int) $level['level'];
            }
        }

        return $numbers === [] ? null : max($numbers);
    }

    /** The highest dunning level actually reached on any of the account's invoices (0 = none). */
    public function reachedDunningStage(string $patientId): int
    {
        $invoiceIds = Invoice::query()
            ->where('series', Invoice::SERIES_INVOICE)
            ->where('patient_id', $patientId)
            ->pluck('id')
            ->all();

        if ($invoiceIds === []) {
            return 0;
        }

        return (int) DunningEvent::query()->whereIn('invoice_id', $invoiceIds)->max('level');
    }

    /** The account's live escalation (initiated and not withdrawn), if any. */
    public function currentFor(string $patientId): ?DebtEnforcementEscalation
    {
        $withdrawn = DebtEnforcementEscalation::query()
            ->where('patient_id', $patientId)
            ->where('action', DebtEnforcementEscalation::ACTION_WITHDRAWN)
            ->pluck('supersedes_escalation_id')
            ->filter()
            ->all();

        return DebtEnforcementEscalation::query()
            ->where('patient_id', $patientId)
            ->where('action', DebtEnforcementEscalation::ACTION_INITIATED)
            ->whereNotIn('id', $withdrawn === [] ? [''] : $withdrawn)
            ->orderByDesc('initiated_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Whether the account may be escalated, and the real evidence either way. Read-only.
     *
     * @return array{eligible: bool, reason: string, terminal_stage: int|null, reached_stage: int, outstanding_minor: int, already_escalated: bool}
     */
    public function eligibility(string $patientId): array
    {
        $terminal = $this->terminalDunningStage();
        $reached = $this->reachedDunningStage($patientId);
        $outstanding = $this->plans->accountOutstandingMinor($patientId);
        $already = $this->currentFor($patientId) !== null;

        $eligible = false;
        $reason = '';
        if ($already) {
            $reason = 'already_escalated';
        } elseif ($terminal === null) {
            $reason = 'no_dunning_policy';
        } elseif ($outstanding <= 0) {
            $reason = 'nothing_outstanding';
        } elseif ($reached < $terminal) {
            $reason = 'dunning_not_exhausted';
        } else {
            $eligible = true;
            $reason = 'eligible';
        }

        return [
            'eligible' => $eligible,
            'reason' => $reason,
            'terminal_stage' => $terminal,
            'reached_stage' => $reached,
            'outstanding_minor' => $outstanding,
            'already_escalated' => $already,
        ];
    }

    /**
     * Record that a HUMAN OPERATOR escalated this account to debt enforcement.
     *
     * `$confirmed` is the operator's explicit confirmation of a legal act — not a formality: without
     * it nothing is recorded. The reason is mandatory and stored verbatim.
     */
    public function initiate(
        Patient $patient,
        string $reason,
        bool $confirmed,
        User $actor,
        ?string $reference = null,
    ): DebtEnforcementEscalation {
        $this->authorize($actor);
        $this->assertActorTenant($actor);
        $this->assertSameTenant($patient, 'patient_id');

        if (! $confirmed) {
            throw new InvalidArgumentException('Starting debt enforcement requires an explicit operator confirmation.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Starting debt enforcement requires a reason.');
        }

        return DB::transaction(function () use ($patient, $reason, $reference, $actor): DebtEnforcementEscalation {
            $eligibility = $this->eligibility((string) $patient->id);
            if (! $eligibility['eligible']) {
                throw new InvalidArgumentException($this->ineligibilityMessage($eligibility['reason']));
            }

            $escalation = DebtEnforcementEscalation::query()->create([
                'patient_id' => $patient->id,
                'action' => DebtEnforcementEscalation::ACTION_INITIATED,
                'supersedes_escalation_id' => null,
                'outstanding_minor' => $eligibility['outstanding_minor'],
                'currency' => (string) $this->settings->get('currency', 'EUR'),
                'dunning_stage' => $eligibility['reached_stage'],
                'reason' => $reason,
                'reference' => $reference,
                'initiated_by' => $actor->id,
                'confirmed_at' => now(),
                'initiated_at' => now(),
            ]);

            $this->auditEscalation('billing.debt_enforcement_initiated', $escalation, $actor, [
                'dunning_stage' => $escalation->dunning_stage,
                'terminal_stage' => $eligibility['terminal_stage'],
                'outstanding_minor' => $escalation->outstanding_minor,
                'reason' => $reason,
                'reference' => $reference,
            ]);

            return $escalation->refresh();
        }, 5);
    }

    /**
     * Withdraw a live escalation by APPENDING a new record that supersedes it (never a mutation).
     */
    public function withdraw(DebtEnforcementEscalation $escalation, string $reason, User $actor): DebtEnforcementEscalation
    {
        $this->authorize($actor);
        $this->assertActorTenant($actor);
        $this->assertSameTenant($escalation, 'escalation_id');

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Withdrawing a debt-enforcement escalation requires a reason.');
        }
        if ($escalation->isWithdrawal()) {
            throw new InvalidArgumentException('A withdrawal record cannot itself be withdrawn.');
        }

        return DB::transaction(function () use ($escalation, $reason, $actor): DebtEnforcementEscalation {
            $already = DebtEnforcementEscalation::query()
                ->where('supersedes_escalation_id', $escalation->id)
                ->lockForUpdate()
                ->exists();
            if ($already) {
                throw new InvalidArgumentException('This escalation has already been withdrawn.');
            }

            $withdrawal = DebtEnforcementEscalation::query()->create([
                'patient_id' => $escalation->patient_id,
                'action' => DebtEnforcementEscalation::ACTION_WITHDRAWN,
                'supersedes_escalation_id' => $escalation->id,
                'outstanding_minor' => $escalation->outstanding_minor,
                'currency' => $escalation->currency,
                'dunning_stage' => $escalation->dunning_stage,
                'reason' => $reason,
                'reference' => $escalation->reference,
                'initiated_by' => $actor->id,
                'confirmed_at' => now(),
                'initiated_at' => now(),
            ]);

            $this->auditEscalation('billing.debt_enforcement_withdrawn', $withdrawal, $actor, [
                'supersedes_escalation_id' => $escalation->id,
                'reason' => $reason,
            ]);

            return $withdrawal->refresh();
        }, 5);
    }

    /**
     * The account's escalation state for display: the eligibility evidence, the live escalation (if
     * any) and its history. Recorded facts only — nothing is computed or predicted.
     *
     * @return array<string, mixed>
     */
    public function present(string $patientId): array
    {
        $current = $this->currentFor($patientId);
        $history = DebtEnforcementEscalation::query()
            ->where('patient_id', $patientId)
            ->orderBy('initiated_at')
            ->orderBy('id')
            ->get();

        $initiators = User::query()
            ->whereIn('id', $history->pluck('initiated_by')->unique()->all())
            ->get()
            ->keyBy('id');

        return [
            'eligibility' => $this->eligibility($patientId),
            'current' => $current === null ? null : [
                'id' => $current->id,
                'outstanding_minor' => $current->outstanding_minor,
                'dunning_stage' => $current->dunning_stage,
                'reason' => $current->reason,
                'reference' => $current->reference,
                'initiated_on' => $current->initiated_at->toDateString(),
                'initiated_by' => $initiators->get($current->initiated_by)?->name,
            ],
            'history' => $history->map(fn (DebtEnforcementEscalation $e): array => [
                'id' => $e->id,
                'action' => $e->action,
                'reason' => $e->reason,
                'dunning_stage' => $e->dunning_stage,
                'outstanding_minor' => $e->outstanding_minor,
                'initiated_on' => $e->initiated_at->toDateString(),
                'initiated_by' => $initiators->get($e->initiated_by)?->name,
            ])->values()->all(),
        ];
    }

    private function ineligibilityMessage(string $reason): string
    {
        return match ($reason) {
            'already_escalated' => 'This account is already in debt enforcement.',
            'no_dunning_policy' => 'No dunning policy is configured, so the dunning process cannot be exhausted.',
            'nothing_outstanding' => 'This account has no outstanding balance to enforce.',
            default => 'Debt enforcement requires the dunning process to be exhausted (the final reminder stage).',
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function auditEscalation(string $action, DebtEnforcementEscalation $escalation, User $actor, array $context = []): void
    {
        $this->audit->record([
            'actor_type' => 'user', // always a person: there is no agent/system path to this service
            'actor_id' => (string) $actor->id,
            'action' => $action,
            'patient_id' => $escalation->patient_id,
            'resource_type' => 'debt_enforcement_escalation',
            'resource_id' => $escalation->id,
            'context' => [
                'escalation_id' => $escalation->id,
                ...$context,
            ],
        ]);
    }

    private function authorize(User $actor): void
    {
        if (! Gate::forUser($actor)->allows(self::PERMISSION)) {
            throw new AuthorizationException('This user cannot start debt-enforcement proceedings.');
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
