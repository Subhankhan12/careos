<?php

namespace Modules\Pharmacy\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\Patients\Models\Patient;
use Modules\Pharmacy\Contracts\MedicationSafetyProvider;
use Modules\Pharmacy\Models\MedicationAdministration;
use Modules\Pharmacy\Models\MedicationOrder;
use Modules\Pharmacy\Support\MedicationReference;
use Modules\Pharmacy\Support\SafetyContext;
use Modules\Pharmacy\Support\SafetyResult;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * The eMAR (PHARMACY.G3) — records that a nurse administered / held / refused a dose against a G2 order,
 * APPEND-ONLY. Gated `note.write` (the nursing clinical-write permission the ward nurse holds — the G5
 * handover precedent; no new permission). Tenant + patient fail-closed.
 *
 * THREADS THE MEDICATION-SAFETY SEAM AT THE ADMINISTRATION POINT: `record` CALLS
 * {@see MedicationSafetyProvider::checkAdministration} — today the G1 null-object, so it returns no alerts
 * and the administration is NEVER blocked. The result is ADVISORY + HUMAN-OWNED: a future certified
 * partner's findings are SURFACED to the nurse, NEVER auto-blocking the administration and NEVER auto-acting.
 * This service adds NO interaction/dose/contraindication/timing checking of its own (a permanent non-goal);
 * it computes no safety verdict and does not judge "late/missed" — the outcome is the nurse's own fact.
 */
class MedicationAdministrationService
{
    public function __construct(
        private readonly MedicationSafetyProvider $safety,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Record an administration against a medication order. Gated `note.write`. Calls the safety seam at the
     * administration point (advisory, non-blocking), then appends the immutable MAR row.
     *
     * @param  array{outcome: string, dose_amount?: string|null, dose_unit?: string|null, reason?: string|null, administered_at?: string|null, scheduled_at?: string|null}  $data
     */
    public function record(User $actor, MedicationOrder $order, array $data): MedicationAdministration
    {
        Gate::forUser($actor)->authorize('note.write');
        $this->assertSameTenant($order);

        // THE SAFETY SEAM at the ADMINISTRATION point (advisory, human-owned). Today the null-object returns
        // none() — no alerts, the administration is NEVER blocked. A certified partner would return advisory
        // findings to SURFACE, never to auto-block/auto-act. NO homemade checking is performed.
        $this->safety->checkAdministration($this->contextFor($order->patient_id));

        $isGiven = $data['outcome'] === MedicationAdministration::OUTCOME_GIVEN;

        return MedicationAdministration::query()->create([
            'patient_id' => $order->patient_id,
            'medication_order_id' => $order->id,
            'administered_by' => $actor->id,
            'outcome' => $data['outcome'],
            'administered_at' => $data['administered_at'] ?? Carbon::now(),
            'scheduled_at' => $data['scheduled_at'] ?? null,
            // The dose GIVEN defaults from the order; held/refused record no dose given.
            'dose_amount' => $data['dose_amount'] ?? ($isGiven ? $order->dose_amount : null),
            'dose_unit' => $data['dose_unit'] ?? ($isGiven ? $order->dose_unit : null),
            'reason' => $data['reason'] ?? null,
            'stay_id' => $order->stay_id,
        ]);
    }

    /**
     * The patient's due medications — a FACTUAL worklist: the ACTIVE medication orders (G2). Not a computed
     * priority/acuity; just what is active, for the nurse to record against.
     *
     * @return Collection<int, MedicationOrder>
     */
    public function dueForPatient(Patient $patient): Collection
    {
        return MedicationOrder::query()
            ->where('patient_id', $patient->id)
            ->where('status', MedicationOrder::STATUS_ACTIVE)
            ->with('formularyItem')
            ->orderByDesc('starts_at')
            ->get();
    }

    /**
     * @return Collection<int, MedicationAdministration>
     */
    public function forPatient(Patient $patient): Collection
    {
        return MedicationAdministration::query()
            ->where('patient_id', $patient->id)
            ->with('order.formularyItem')
            ->orderByDesc('administered_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The advisory safety review at the administration layer — the display-surface call-site. Today the
     * null-object returns none() (an empty alerts area). Never blocks anything; asserts nothing on its own.
     */
    public function safetyReview(Patient $patient): SafetyResult
    {
        return $this->safety->checkAdministration($this->contextFor($patient->id));
    }

    private function contextFor(string $patientId): SafetyContext
    {
        $refs = MedicationOrder::query()
            ->where('patient_id', $patientId)
            ->where('status', MedicationOrder::STATUS_ACTIVE)
            ->with('formularyItem')
            ->get()
            ->map(fn (MedicationOrder $order): MedicationReference => new MedicationReference(
                $order->formularyItem->code,
                $order->formularyItem->name,
                trim($order->dose_amount.' '.$order->dose_unit),
                $order->route,
            ))
            ->all();

        return new SafetyContext($patientId, $refs);
    }

    private function assertSameTenant(MedicationOrder $order): void
    {
        if ($order->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('medication_order_id', (string) $order->id);
        }
    }
}
