<?php

namespace Modules\Radiology\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Clinical\Services\OrderService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;
use Modules\Radiology\Contracts\ImagingConnectivity;
use Modules\Radiology\Exceptions\RadiologyOrderException;
use Modules\Radiology\Models\RadiologyExam;
use Modules\Radiology\Models\RadiologyOrder;

/**
 * Imaging order entry (RAD.G2) — REUSE-first, not new order domain. An imaging order IS a Clinical `Order`
 * (~95% reuse): this service places it through the EXISTING {@see OrderService::place} (which authorizes
 * `order.manage`, runs the `ordered → collected → in_progress → resulted → reviewed` lifecycle), then appends
 * the thin imaging {@see RadiologyOrder} overlay recording ONLY the imaging-specific placement facts Clinical's
 * `Order` doesn't carry: `modality` + `body_part` + the `priority` (routine/urgent/STAT), then calls the
 * {@see ImagingConnectivity} seam's `transmitOrder()` (the future DICOM Modality-Worklist push — the null no-op
 * today). **Clinical's `Order`/`OrderService` are REUSED, not duplicated or modified.**
 *
 * ELECTRIC FENCE (docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md §4): `priority` is a RECORDED flag the ordering
 * clinician SETS — this service computes NO priority, ranks NOTHING by a computed urgency, auto-escalates
 * NOTHING. The `transmitOrder()` seam stays the null no-op (no homemade DICOM/MWL), and there is no image yet —
 * NO computed image finding/CAD anywhere.
 */
class RadiologyOrderService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly ImagingConnectivity $imaging,
    ) {}

    /**
     * Place an imaging order. REUSES `OrderService::place` (the Clinical `Order` + its lifecycle +
     * `order.manage`), then appends the append-only imaging overlay (modality + body_part + priority) — atomic
     * (one transaction: the Order + the overlay, or neither) — then calls the `ImagingConnectivity` seam's
     * `transmitOrder()` (a null no-op today). The order ties to the patient + an OPTIONAL `Encounter` (the
     * existing linkage — an inpatient round / ED-visit encounter carries the stay/visit association). `modality`
     * defaults from the exam's orderable; `bodyPart` defaults from the exam overlay.
     *
     * @return array{order: Order, radiologyOrder: RadiologyOrder}
     */
    public function place(
        User $actor,
        Patient $patient,
        RadiologyExam $exam,
        string $priority,
        ?string $modality = null,
        ?string $bodyPart = null,
        ?Encounter $encounter = null,
        ?string $clinicalNote = null,
    ): array {
        if (! in_array($priority, RadiologyOrder::PRIORITIES, true)) {
            throw RadiologyOrderException::invalidPriority($priority);
        }

        $orderable = OrderableItem::query()->findOrFail($exam->orderable_item_id);
        $modality ??= $orderable->specimen_or_modality;
        $bodyPart ??= $exam->body_part;

        $result = DB::transaction(function () use ($actor, $patient, $orderable, $encounter, $clinicalNote, $modality, $bodyPart, $priority): array {
            // REUSE the EXISTING order placement — Clinical owns the Order lifecycle + order.manage.
            $order = $this->orders->place($patient, $encounter, $orderable, ['clinical_note' => $clinicalNote], $actor);

            // The thin imaging overlay — the placement facts Clinical's Order doesn't carry (append-only).
            $radiologyOrder = RadiologyOrder::query()->create([
                'patient_id' => $patient->id,
                'order_id' => $order->id,
                'modality' => $modality,
                'body_part' => $bodyPart,
                'priority' => $priority, // a RECORDED flag the clinician set (routine/urgent/stat), never computed
            ]);

            return ['order' => $order, 'radiologyOrder' => $radiologyOrder];
        });

        // THE IMAGING-CONNECTIVITY SEAM — the future DICOM Modality-Worklist push. A null no-op today (no PACS/
        // modality to transmit to); a certified partner fills it later WITHOUT any change here.
        $this->imaging->transmitOrder($result['order']);

        return $result;
    }

    /**
     * The patient's imaging orders (newest first) with the Clinical Order + the orderable — for the order list.
     * Read-only; the Order's lifecycle status is READ from the reused Order.
     *
     * @return Collection<int, RadiologyOrder>
     */
    public function forPatient(Patient $patient): Collection
    {
        return RadiologyOrder::query()
            ->where('patient_id', $patient->id)
            ->with(['order.orderableItem'])
            ->get()
            ->sortByDesc(fn (RadiologyOrder $r): string => (string) $r->order?->ordered_at)
            ->values();
    }
}
