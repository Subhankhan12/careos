<?php

namespace Modules\Dental\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Services\AuditService;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\DentalProcedure;
use Modules\Dental\Models\TreatmentPlan;
use Modules\Dental\Models\TreatmentPlanItem;
use Modules\Dental\Models\TreatmentPlanPhase;
use Modules\Dental\Support\ToothNotation;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * The dental treatment-plan service (DENTAL.G5) — a DENTIST-AUTHORED plan: proposed procedures
 * in phases, with a fee-schedule ESTIMATE, that the patient accepts and works through.
 *
 * ESTIMATE: each item's estimated fee is the G3 tariff fee (the tenant's fee schedule), read
 * through the existing pricing store — NOT recomputed. It is SNAPSHOTTED when the plan is
 * PROPOSED (integer minor units), so a later fee-schedule edit never changes an accepted plan's
 * agreed estimate (the same snapshot discipline as charges). Phase/plan totals are SUMS of the
 * snapshotted estimates — the only arithmetic here; there is no VAT/discount math (VAT is applied
 * by the billing engine when a procedure is actually charged, G4).
 *
 * NOT BILLING: proposing/accepting a plan posts NO charge — the plan is an estimate + an
 * agreement; the charge happens only when the procedure is performed (G4). No double-charging.
 *
 * ELECTRIC FENCE: the DENTIST authors the plan. There is no auto-suggestion of procedures, no
 * severity-driven prioritisation, no AI-recommended treatment — this service only records what
 * the dentist adds and sums the fees. Lifecycle transitions are legal-only; everything is
 * audited, tenant + patient scoped, and read-logged. Managing needs `dental.chart` (clinical
 * authorship); reading needs `patient.view`.
 */
class TreatmentPlanService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
    ) {}

    public function create(User $actor, Patient $patient, ?string $title): TreatmentPlan
    {
        $this->authorizeManage($actor);
        $this->assertPatientTenant($patient);

        $plan = TreatmentPlan::query()->create([
            'patient_id' => $patient->id,
            'created_by' => $actor->id,
            'title' => $title,
            'status' => TreatmentPlan::STATUS_DRAFT,
        ]);

        $this->auditPlan($actor, 'treatment_plan.created', $plan);

        return $plan;
    }

    public function addPhase(User $actor, TreatmentPlan $plan, string $name): TreatmentPlanPhase
    {
        $this->authorizeManage($actor);
        $this->assertPlanTenant($plan);
        $this->assertDraft($plan);

        $sequence = (int) TreatmentPlanPhase::query()->where('treatment_plan_id', $plan->id)->max('sequence') + 1;

        return TreatmentPlanPhase::query()->create([
            'treatment_plan_id' => $plan->id,
            'name' => $name,
            'sequence' => $sequence,
        ]);
    }

    public function addItem(User $actor, TreatmentPlan $plan, TreatmentPlanPhase $phase, DentalProcedure $procedure, ?string $tooth, ?string $surface): TreatmentPlanItem
    {
        $this->authorizeManage($actor);
        $this->assertPlanTenant($plan);
        $this->assertDraft($plan);

        if ($procedure->tooth_scoped && ($tooth === null || $tooth === '')) {
            throw new DentalException('This procedure is tooth-scoped and needs a tooth.');
        }
        if ($tooth !== null && ! ToothNotation::isValid($tooth)) {
            throw new DentalException("Invalid FDI tooth id [{$tooth}].");
        }

        $sequence = (int) TreatmentPlanItem::query()->where('treatment_plan_phase_id', $phase->id)->max('sequence') + 1;

        // estimated_fee_minor stays null while draft — the estimate reads the LIVE fee for display;
        // it is frozen (snapshotted) when the plan is proposed.
        return TreatmentPlanItem::query()->create([
            'treatment_plan_id' => $plan->id,
            'treatment_plan_phase_id' => $phase->id,
            'dental_procedure_id' => $procedure->id,
            'tooth' => $tooth,
            'surface' => $surface,
            'estimated_fee_minor' => null,
            'sequence' => $sequence,
        ]);
    }

    /**
     * Propose the plan to the patient: SNAPSHOT each item's estimate from the current fee, then
     * transition draft → proposed. After this, the agreed estimate is frozen.
     */
    public function propose(User $actor, TreatmentPlan $plan): TreatmentPlan
    {
        $this->authorizeManage($actor);
        $this->assertPlanTenant($plan);

        return DB::transaction(function () use ($actor, $plan): TreatmentPlan {
            TreatmentPlanItem::query()
                ->where('treatment_plan_id', $plan->id)
                ->with('dentalProcedure.tariffItem')
                ->get()
                ->each(function (TreatmentPlanItem $item): void {
                    // Read the fee from the existing pricing store — no recompute.
                    $item->forceFill(['estimated_fee_minor' => (int) ($item->dentalProcedure?->tariffItem->unit_price_minor ?? 0)])->save();
                });

            return $this->transition($actor, $plan, TreatmentPlan::STATUS_PROPOSED);
        });
    }

    public function accept(User $actor, TreatmentPlan $plan): TreatmentPlan
    {
        return $this->transition($actor, $plan, TreatmentPlan::STATUS_ACCEPTED);
    }

    public function decline(User $actor, TreatmentPlan $plan): TreatmentPlan
    {
        return $this->transition($actor, $plan, TreatmentPlan::STATUS_DECLINED);
    }

    public function start(User $actor, TreatmentPlan $plan): TreatmentPlan
    {
        return $this->transition($actor, $plan, TreatmentPlan::STATUS_IN_PROGRESS);
    }

    public function complete(User $actor, TreatmentPlan $plan): TreatmentPlan
    {
        return $this->transition($actor, $plan, TreatmentPlan::STATUS_COMPLETED);
    }

    /**
     * A plan item's estimate: the snapshot once proposed, else the live fee (for a draft). Reads
     * the fee; never recomputes it.
     */
    public function itemEstimate(TreatmentPlanItem $item): int
    {
        return $item->estimated_fee_minor ?? (int) ($item->dentalProcedure?->tariffItem->unit_price_minor ?? 0);
    }

    /**
     * @return Collection<int, TreatmentPlan>
     */
    public function plansFor(User $actor, Patient $patient): Collection
    {
        Gate::forUser($actor)->authorize('patient.view');
        $this->assertPatientTenant($patient);

        // A treatment plan is clinical data → patient-scoped read log.
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => 'read',
            'resource_type' => 'treatment_plans',
            'resource_id' => (string) $patient->id,
            'patient_id' => (string) $patient->id,
            'context' => ['scope' => 'treatment_plans'],
        ]);

        return TreatmentPlan::query()
            ->where('patient_id', $patient->id)
            ->with(['phases', 'items.dentalProcedure.tariffItem'])
            ->orderByDesc('created_at')
            ->get();
    }

    private function transition(User $actor, TreatmentPlan $plan, string $toStatus): TreatmentPlan
    {
        $this->authorizeManage($actor);
        $this->assertPlanTenant($plan);

        $fromStatus = $plan->status;
        if (! $this->canTransition($fromStatus, $toStatus)) {
            throw new DentalException("Illegal treatment plan transition [{$fromStatus} -> {$toStatus}].");
        }

        $fill = ['status' => $toStatus];
        if ($toStatus === TreatmentPlan::STATUS_ACCEPTED) {
            $fill['accepted_at'] = Carbon::now();
        }

        $plan->forceFill($fill)->save();
        $this->auditPlan($actor, 'treatment_plan.'.$toStatus, $plan, ['from_status' => $fromStatus]);

        return $plan->refresh();
    }

    private function canTransition(string $fromStatus, string $toStatus): bool
    {
        return match ($fromStatus) {
            TreatmentPlan::STATUS_DRAFT => $toStatus === TreatmentPlan::STATUS_PROPOSED,
            TreatmentPlan::STATUS_PROPOSED => in_array($toStatus, [TreatmentPlan::STATUS_ACCEPTED, TreatmentPlan::STATUS_DECLINED], true),
            TreatmentPlan::STATUS_ACCEPTED => $toStatus === TreatmentPlan::STATUS_IN_PROGRESS,
            TreatmentPlan::STATUS_IN_PROGRESS => $toStatus === TreatmentPlan::STATUS_COMPLETED,
            default => false, // completed + declined are terminal
        };
    }

    private function assertDraft(TreatmentPlan $plan): void
    {
        if ($plan->status !== TreatmentPlan::STATUS_DRAFT) {
            throw new DentalException('Phases and items can only be edited while the plan is a draft.');
        }
    }

    private function authorizeManage(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('dental.chart')) {
            throw new AuthorizationException('This user cannot manage dental treatment plans.');
        }
    }

    private function assertPatientTenant(Patient $patient): void
    {
        if ($patient->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $patient->id);
        }
    }

    private function assertPlanTenant(TreatmentPlan $plan): void
    {
        if ($plan->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('treatment_plan_id', (string) $plan->id);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function auditPlan(User $actor, string $action, TreatmentPlan $plan, array $context = []): void
    {
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => $action,
            'patient_id' => $plan->patient_id,
            'resource_type' => 'treatment_plans',
            'resource_id' => $plan->id,
            'context' => ['status' => $plan->status, ...$context],
        ]);
    }
}
