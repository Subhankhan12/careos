<?php

namespace Modules\Lab\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Clinical\Services\OrderService;
use Modules\Lab\Exceptions\LabOrderException;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\LabTest;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

/**
 * Lab order entry (LAB.G2) — REUSE-first, not new order domain. A lab order IS a Clinical `Order`: this
 * service places it through the EXISTING {@see OrderService::place} (which authorizes `order.manage`, runs the
 * `ordered → collected → in_progress → resulted → reviewed` lifecycle, and calls the `LabConnectivity` seam's
 * `transmit()` — the manual no-op today), then appends the thin lab {@see LabOrder} overlay recording ONLY the
 * two lab-specific placement facts Clinical's `Order` doesn't carry: `specimen_type` + the lab `priority`
 * (routine/urgent/STAT). **Clinical's `Order`/`OrderService`/seam are REUSED, not duplicated or modified.**
 *
 * ELECTRIC FENCE (docs/HOSPITAL-PHASE3-LAB-MAP.md §4): `priority` is a RECORDED flag the ordering clinician
 * SETS — this service computes NO priority, ranks NOTHING by a computed urgency, auto-escalates NOTHING. The
 * seam's `transmit()` stays the manual no-op (no homemade HL7).
 */
class LabOrderService
{
    public function __construct(private readonly OrderService $orders) {}

    /**
     * Place a lab order. REUSES `OrderService::place` (the Clinical `Order` + its lifecycle + the seam +
     * `order.manage`), then appends the append-only lab overlay (specimen + priority). Atomic (one
     * transaction: the Order + the overlay, or neither). The order ties to the patient + an OPTIONAL
     * `Encounter` (the existing linkage — an inpatient ward-round / ED-visit encounter carries the stay/visit
     * association). `specimenType` defaults from the catalog item when null.
     *
     * @return array{order: Order, labOrder: LabOrder}
     */
    public function place(
        User $actor,
        Patient $patient,
        LabTest $labTest,
        string $priority,
        ?string $specimenType = null,
        ?Encounter $encounter = null,
        ?string $clinicalNote = null,
    ): array {
        if (! in_array($priority, LabOrder::PRIORITIES, true)) {
            throw LabOrderException::invalidPriority($priority);
        }

        $orderable = OrderableItem::query()->findOrFail($labTest->orderable_item_id);
        $specimenType ??= $orderable->specimen_or_modality;

        return DB::transaction(function () use ($actor, $patient, $orderable, $encounter, $clinicalNote, $specimenType, $priority): array {
            // REUSE the EXISTING order placement — Clinical owns the Order lifecycle + the seam + order.manage.
            $order = $this->orders->place($patient, $encounter, $orderable, ['clinical_note' => $clinicalNote], $actor);

            // The thin lab overlay — the two placement facts Clinical's Order doesn't carry (append-only).
            $labOrder = LabOrder::query()->create([
                'patient_id' => $patient->id,
                'order_id' => $order->id,
                'specimen_type' => $specimenType,
                'priority' => $priority, // a RECORDED flag the clinician set (routine/urgent/stat), never computed
            ]);

            return ['order' => $order, 'labOrder' => $labOrder];
        });
    }

    /**
     * The patient's lab orders (newest first) with the Clinical Order + the orderable — for the lab-order list.
     * Read-only; the Order's lifecycle status is READ from the reused Order.
     *
     * @return Collection<int, LabOrder>
     */
    public function forPatient(Patient $patient): Collection
    {
        return LabOrder::query()
            ->where('patient_id', $patient->id)
            ->with(['order.orderableItem'])
            ->get()
            ->sortByDesc(fn (LabOrder $l): string => (string) $l->order?->ordered_at)
            ->values();
    }
}
