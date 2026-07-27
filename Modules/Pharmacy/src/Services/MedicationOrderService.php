<?php

namespace Modules\Pharmacy\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Patients\Models\Patient;
use Modules\Pharmacy\Contracts\MedicationSafetyProvider;
use Modules\Pharmacy\Exceptions\MedicationOrderException;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Pharmacy\Models\MedicationOrder;
use Modules\Pharmacy\Models\MedicationOrderEvent;
use Modules\Pharmacy\Support\MedicationReference;
use Modules\Pharmacy\Support\SafetyContext;
use Modules\Pharmacy\Support\SafetyResult;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Places + manages medication orders (PHARMACY.G2). Gated `medication.prescribe`; tenant + patient
 * fail-closed; the mutable order + an append-only event per transition (the `AdmissionService` shape).
 *
 * THREADS THE MEDICATION-SAFETY SEAM: at placement it CALLS {@see MedicationSafetyProvider::checkOrder} —
 * today the G1 null-object, so it returns no alerts and the order proceeds. The result is ADVISORY +
 * HUMAN-OWNED: a future certified partner's alerts are SURFACED to the prescriber, NEVER auto-blocking the
 * order and NEVER auto-acting. This service adds NO interaction/dose/contraindication checking of its own —
 * the homemade judgment is a permanent non-goal. It computes no dose, suggests no med, ranks nothing.
 */
class MedicationOrderService
{
    public function __construct(
        private readonly MedicationSafetyProvider $safety,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Place a medication order for a patient (optionally within an inpatient stay). Gated
     * `medication.prescribe`. Calls the safety seam advisorily (non-blocking) and records a `placed` event.
     *
     * @param  array{dose_amount: string, dose_unit: string, route: string, frequency: string, starts_at?: string|null, stops_at?: string|null, prn?: bool, prn_reason?: string|null, note?: string|null}  $data
     */
    public function prescribe(User $actor, Patient $patient, FormularyItem $item, array $data, ?string $stayId = null): MedicationOrder
    {
        Gate::forUser($actor)->authorize('medication.prescribe');
        $this->assertSameTenant($patient, 'patient_id', $patient->id);
        $this->assertSameTenant($item, 'formulary_item_id', $item->id);

        // THE SAFETY SEAM (advisory, human-owned). Today the null-object returns none() — no alerts, the
        // order is NEVER blocked here. A certified partner would return advisory findings to SURFACE, never
        // to auto-block/auto-act. NO homemade checking is performed.
        $this->safety->checkOrder($this->contextFor($patient, new MedicationReference(
            $item->code,
            $item->name,
            trim($data['dose_amount'].' '.$data['dose_unit']),
            $data['route'],
        )));

        return DB::transaction(function () use ($actor, $patient, $item, $data, $stayId): MedicationOrder {
            $order = MedicationOrder::query()->create([
                'patient_id' => $patient->id,
                'prescribed_by' => $actor->id,
                'formulary_item_id' => $item->id,
                'stay_id' => $stayId,
                'dose_amount' => $data['dose_amount'],
                'dose_unit' => $data['dose_unit'],
                'route' => $data['route'],
                'frequency' => $data['frequency'],
                'starts_at' => $data['starts_at'] ?? Carbon::now(),
                'stops_at' => $data['stops_at'] ?? null,
                'prn' => $data['prn'] ?? false,
                'prn_reason' => $data['prn_reason'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => MedicationOrder::STATUS_ACTIVE,
            ]);

            $this->recordEvent($order, MedicationOrderEvent::TYPE_PLACED, null, $actor);

            return $order;
        });
    }

    /**
     * A legal-only lifecycle transition (hold / resume / discontinue / complete), recorded as an append-only
     * event. Gated `medication.prescribe`.
     */
    public function transition(User $actor, MedicationOrder $order, string $toStatus, ?string $reason = null): MedicationOrder
    {
        Gate::forUser($actor)->authorize('medication.prescribe');
        $this->assertSameTenant($order, 'medication_order_id', $order->id);

        if (! MedicationOrder::canTransition($order->status, $toStatus)) {
            throw MedicationOrderException::invalidTransition($order->status, $toStatus);
        }

        $eventType = match ($toStatus) {
            MedicationOrder::STATUS_HELD => MedicationOrderEvent::TYPE_HELD,
            MedicationOrder::STATUS_ACTIVE => MedicationOrderEvent::TYPE_RESUMED,
            MedicationOrder::STATUS_DISCONTINUED => MedicationOrderEvent::TYPE_DISCONTINUED,
            MedicationOrder::STATUS_COMPLETED => MedicationOrderEvent::TYPE_COMPLETED,
            default => throw MedicationOrderException::invalidStatus($toStatus),
        };

        return DB::transaction(function () use ($order, $toStatus, $reason, $eventType, $actor): MedicationOrder {
            $order->forceFill(['status' => $toStatus, 'status_reason' => $reason])->save();
            $this->recordEvent($order, $eventType, $reason, $actor);

            return $order->refresh();
        });
    }

    /**
     * @return Collection<int, MedicationOrder>
     */
    public function activeForPatient(Patient $patient): Collection
    {
        return MedicationOrder::query()
            ->where('patient_id', $patient->id)
            ->where('status', MedicationOrder::STATUS_ACTIVE)
            ->with('formularyItem')
            ->orderByDesc('starts_at')
            ->get();
    }

    /**
     * @return Collection<int, MedicationOrder>
     */
    public function historyForPatient(Patient $patient): Collection
    {
        return MedicationOrder::query()
            ->where('patient_id', $patient->id)
            ->with('formularyItem')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The advisory safety review of a patient's ACTIVE medications — the display-surface call-site of the
     * seam. Today the null-object returns none() (an empty alerts area); a certified partner would populate
     * it. Never blocks anything; asserts nothing on its own.
     */
    public function safetyReview(Patient $patient): SafetyResult
    {
        return $this->safety->checkOrder($this->contextFor($patient));
    }

    /**
     * Build the safety context from the patient's active medications (+ an optional additional med being
     * prescribed). A plain record for the partner engine — CareOS reasons about none of it.
     */
    private function contextFor(Patient $patient, ?MedicationReference $additional = null): SafetyContext
    {
        // formulary_item_id is a required FK and the relation is eager-loaded, so the item is always present.
        $refs = $this->activeForPatient($patient)
            ->map(fn (MedicationOrder $order): MedicationReference => new MedicationReference(
                $order->formularyItem->code,
                $order->formularyItem->name,
                trim($order->dose_amount.' '.$order->dose_unit),
                $order->route,
            ))
            ->all();

        if ($additional !== null) {
            $refs[] = $additional;
        }

        return new SafetyContext($patient->id, array_values($refs));
    }

    private function recordEvent(MedicationOrder $order, string $type, ?string $reason, User $actor): void
    {
        MedicationOrderEvent::query()->create([
            'patient_id' => $order->patient_id,
            'medication_order_id' => $order->id,
            'event_type' => $type,
            'reason' => $reason,
            'performed_by' => $actor->id,
            'occurred_at' => Carbon::now(),
        ]);
    }

    private function assertSameTenant(object $model, string $attribute, string $id): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
