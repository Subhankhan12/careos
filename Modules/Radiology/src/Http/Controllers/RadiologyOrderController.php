<?php

namespace Modules\Radiology\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Modules\Clinical\Models\Encounter;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Radiology\Exceptions\RadiologyOrderException;
use Modules\Radiology\Models\RadiologyExam;
use Modules\Radiology\Models\RadiologyOrder;
use Modules\Radiology\Services\RadiologyCatalogService;
use Modules\Radiology\Services\RadiologyOrderService;

/**
 * Imaging order entry (RAD.G2) — PRESENTATIONAL over {@see RadiologyOrderService}. From a patient, place an
 * imaging order by REUSING the Clinical `Order` (via `OrderService::place`) + append the thin imaging overlay
 * (modality/body-part + priority); list the patient's imaging orders with their reused-Order lifecycle status.
 * String-id (FIX.1). Reading is `patient.view` (read-logged); placing reuses `order.manage` (the existing order
 * permission — enforced in the reused `OrderService::place`).
 *
 * ELECTRIC FENCE: the priority is the clinician's RECORDED flag (routine/urgent/STAT) — the screen records it;
 * it computes NO priority, ranks nothing by a computed urgency, auto-escalates nothing. No image yet — no
 * computed image finding/CAD.
 */
class RadiologyOrderController
{
    public function show(Request $request, string $patient, RadiologyOrderService $orders, RadiologyCatalogService $catalog): Response
    {
        Gate::authorize('patient.view');
        abort_unless($request->user() instanceof User, 403);

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        return Inertia::render('Radiology/Orders', [
            'patient' => ['id' => $record->id, 'name' => trim($record->first_name.' '.$record->last_name)],
            'orders' => $orders->forPatient($record)->map(fn (RadiologyOrder $r): array => [
                'id' => $r->id,
                'code' => $r->order?->orderableItem?->code,
                'name' => $r->order?->orderableItem?->name,
                'modality' => $r->modality,
                'body_part' => $r->body_part,
                'priority' => $r->priority,                    // the RECORDED flag (routine/urgent/stat)
                'status' => $r->order?->status,                // the reused Clinical Order lifecycle state
                'ordered_at' => $r->order?->ordered_at?->toIso8601String(),
            ])->all(),
            // Active imaging exams (the catalog picker) + each exam's default modality/body-part.
            'exams' => $catalog->catalog()
                ->filter(fn (RadiologyExam $e): bool => $e->orderableItem !== null && $e->orderableItem->active)
                ->map(fn (RadiologyExam $e): array => [
                    'id' => $e->id,
                    'code' => $e->orderableItem?->code,
                    'name' => $e->orderableItem?->name,
                    'modality' => $e->orderableItem?->specimen_or_modality,
                    'body_part' => $e->body_part,
                ])->values()->all(),
            'options' => ['priorities' => RadiologyOrder::PRIORITIES],
            'actions' => [
                'can_order' => Gate::allows('order.manage'),
                'store_url' => route('radiology.orders.store', $record->id),
            ],
        ]);
    }

    public function store(Request $request, string $patient, RadiologyOrderService $orders): RedirectResponse
    {
        // Placing an imaging order is `order.manage` (the existing order permission — the reused
        // OrderService::place re-checks it). Gate here too so a non-ordering user is refused at the gate.
        Gate::authorize('order.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'radiology_exam_id' => ['required', 'string'],
            'priority' => ['required', 'string', 'in:'.implode(',', RadiologyOrder::PRIORITIES)],
            'modality' => ['nullable', 'string', 'max:40'],
            'body_part' => ['nullable', 'string', 'max:60'],
            'clinical_note' => ['nullable', 'string', 'max:500'],
            'encounter_id' => ['nullable', 'string'], // the existing linkage (inpatient round / ED-visit encounter)
        ]);

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $exam = RadiologyExam::query()->whereKey($data['radiology_exam_id'])->firstOrFail();
        $encounter = isset($data['encounter_id']) && $data['encounter_id'] !== ''
            ? Encounter::query()->whereKey($data['encounter_id'])->first()
            : null;

        try {
            $orders->place($actor, $record, $exam, $data['priority'], $data['modality'] ?? null, $data['body_part'] ?? null, $encounter, $data['clinical_note'] ?? null);
        } catch (RadiologyOrderException|CrossTenantReferenceException|InvalidArgumentException $e) {
            return back()->withErrors(['radiology_order' => $e->getMessage()]);
        }

        return redirect()->route('radiology.orders.show', $record->id)->with('status', 'radiology-order-placed');
    }
}
