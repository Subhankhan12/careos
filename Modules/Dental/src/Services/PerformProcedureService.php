<?php

namespace Modules\Dental\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Services\AuditService;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\DentalProcedure;
use Modules\Dental\Models\PerformedProcedure;
use Modules\Dental\Models\ToothRecord;
use Modules\Dental\Models\TreatmentPlanItem;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * The perform-a-procedure workflow (DENTAL.G4) — one coherent action that records a
 * procedure the dentist performed, captures its charge, and updates the odontogram, ATOMICALLY.
 *
 * It REUSES the existing pieces and adds NO new billing or charting logic:
 *  - {@see DentalChargeService::capture()} (G3) captures the charge through the tested billing
 *    engine (tariff snapshot → invoice/reconciliation pipeline; billing.manage enforced there);
 *  - a {@see PerformedProcedure} row records the clinical fact, tied to that charge;
 *  - {@see ToothChartService::chart()} (G1, append-only) records the resulting tooth-state
 *    change — the dentist STATES the factual consequence (e.g. extraction → `missing`, filling →
 *    `restoration` on the surface); the system charts exactly that value (validated against the
 *    G1 vocabulary), it infers nothing and judges nothing.
 *
 * CONSISTENCY GUARANTEE: all three writes happen inside ONE DB transaction — a performed
 * procedure never leaves a charge without its clinical record (or vice-versa), and a failure in
 * any step rolls back all of them (no orphan). RBAC: the clinical gate `dental.chart` is checked
 * up front; the charge enforces `billing.manage` — so performing-and-charging needs BOTH (the
 * dentist-owner holds them via org_admin). Tenant + patient scoped, fail-closed.
 */
class PerformProcedureService
{
    public function __construct(
        private readonly DentalChargeService $charges,
        private readonly ToothChartService $charts,
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
    ) {}

    public function perform(
        User $actor,
        Patient $patient,
        Branch $branch,
        DentalProcedure $procedure,
        ?string $tooth = null,
        ?string $surface = null,
        ?string $note = null,
        ?string $toothState = null,
        int $quantity = 1,
        ?TreatmentPlanItem $planItem = null,
    ): PerformedProcedure {
        Gate::forUser($actor)->authorize('dental.chart'); // clinical gate; billing.manage is enforced by the charge
        $this->assertActorTenant($actor);
        $this->assertPatientTenant($patient);
        $this->assertProcedureTenant($procedure);

        if ($procedure->tooth_scoped && ($tooth === null || $tooth === '')) {
            throw new DentalException('This procedure is tooth-scoped and needs a tooth.');
        }

        // The OPTIONAL plan-item link (G5) — a performed procedure may complete a planned item, but
        // only one belonging to the SAME tenant + patient. G4's atomic workflow is otherwise unchanged.
        if ($planItem !== null) {
            if ($planItem->tenant_id !== $this->tenantContext->id()) {
                throw CrossTenantReferenceException::forAttribute('treatment_plan_item_id', (string) $planItem->id);
            }
            if ($planItem->treatmentPlan?->patient_id !== $patient->id) {
                throw new DentalException('That treatment-plan item belongs to a different patient.');
            }
        }

        return DB::transaction(function () use ($actor, $patient, $branch, $procedure, $tooth, $surface, $note, $toothState, $quantity, $planItem): PerformedProcedure {
            // 1. Charge — the EXISTING G3 path (resolves + snapshots the fee; billing.manage enforced inside).
            $charge = $this->charges->capture($actor, $patient, $branch, $procedure, $tooth, $surface, $quantity);

            // 2. The clinical fact, tied to the charge.
            $performed = PerformedProcedure::query()->create([
                'patient_id' => $patient->id,
                'dental_procedure_id' => $procedure->id,
                'treatment_plan_item_id' => $planItem?->id,
                'charge_id' => $charge->id,
                'tooth' => $tooth,
                'surface' => $surface,
                'performed_by' => $actor->id,
                'performed_at' => Carbon::now(),
                'note' => $note,
                'status' => PerformedProcedure::STATUS_COMPLETED,
            ]);

            // 3. The resulting tooth-state change — the EXISTING G1 append-only charting. A
            //    whole-tooth condition charts whole-tooth; a surface condition charts on the
            //    performed surface. An invalid value throws here → the whole transaction rolls back.
            if ($toothState !== null && $toothState !== '' && $tooth !== null) {
                $chartSurface = in_array($toothState, ToothRecord::SURFACE_CONDITIONS, true) ? $surface : null;
                $this->charts->chart($actor, $patient, $tooth, $chartSurface, $toothState, 'Performed: '.$procedure->tariffItem->code);
            }

            $this->audit->record([
                'actor_type' => 'user',
                'actor_id' => (string) $actor->id,
                'action' => 'dental.procedure.performed',
                'patient_id' => (string) $patient->id,
                'resource_type' => 'performed_procedures',
                'resource_id' => $performed->id,
                'context' => [
                    'code' => $procedure->tariffItem->code,
                    'tooth' => $tooth,
                    'surface' => $surface,
                    'charge_id' => $charge->id,
                    'tooth_state' => $toothState,
                ],
            ]);

            return $performed;
        });
    }

    private function assertActorTenant(User $actor): void
    {
        if ($actor->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('actor_id', (string) $actor->id);
        }
    }

    private function assertPatientTenant(Patient $patient): void
    {
        if ($patient->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $patient->id);
        }
    }

    private function assertProcedureTenant(DentalProcedure $procedure): void
    {
        if ($procedure->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('dental_procedure_id', (string) $procedure->id);
        }
    }
}
