<?php

namespace Modules\Lab\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Modules\Clinical\Models\Encounter;
use Modules\Lab\Exceptions\LabOrderException;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\LabTest;
use Modules\Lab\Services\LabCatalogService;
use Modules\Lab\Services\LabOrderService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * Lab order entry (LAB.G2) — PRESENTATIONAL over {@see LabOrderService}. From a patient, place a lab order by
 * REUSING the Clinical `Order` (via `OrderService::place`) + append the thin lab overlay (specimen + priority);
 * list the patient's lab orders with their reused-Order lifecycle status. String-id (FIX.1). Reading is
 * `patient.view` (read-logged); placing reuses `order.manage` (the existing order permission — enforced in the
 * reused `OrderService::place`).
 *
 * ELECTRIC FENCE: the priority is the clinician's RECORDED flag (routine/urgent/STAT) — the screen records it;
 * it computes NO priority, ranks nothing by a computed urgency, auto-escalates nothing.
 */
class LabOrderController
{
    public function show(Request $request, string $patient, LabOrderService $orders, LabCatalogService $catalog): Response
    {
        Gate::authorize('patient.view');
        abort_unless($request->user() instanceof User, 403);

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        return Inertia::render('Lab/Orders', [
            'patient' => ['id' => $record->id, 'name' => trim($record->first_name.' '.$record->last_name)],
            'orders' => $orders->forPatient($record)->map(fn (LabOrder $l): array => [
                'id' => $l->id,
                'code' => $l->order?->orderableItem?->code,
                'name' => $l->order?->orderableItem?->name,
                'specimen_type' => $l->specimen_type,
                'priority' => $l->priority,                    // the RECORDED flag (routine/urgent/stat)
                'status' => $l->order?->status,                // the reused Clinical Order lifecycle state
                'ordered_at' => $l->order?->ordered_at?->toIso8601String(),
            ])->all(),
            // Active lab tests (the catalog picker) + each test's default specimen.
            'tests' => $catalog->catalog()
                ->filter(fn (LabTest $t): bool => $t->orderableItem !== null && $t->orderableItem->active)
                ->map(fn (LabTest $t): array => [
                    'id' => $t->id,
                    'code' => $t->orderableItem?->code,
                    'name' => $t->orderableItem?->name,
                    'specimen' => $t->orderableItem?->specimen_or_modality,
                ])->values()->all(),
            'options' => ['priorities' => LabOrder::PRIORITIES],
            'actions' => [
                'can_order' => Gate::allows('order.manage'),
                'store_url' => route('lab.orders.store', $record->id),
            ],
        ]);
    }

    public function store(Request $request, string $patient, LabOrderService $orders): RedirectResponse
    {
        // Placing a lab order is `order.manage` (the existing order permission — the reused OrderService::place
        // re-checks it). Gate here too so a non-ordering user is refused at the gate, not via the service.
        Gate::authorize('order.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'lab_test_id' => ['required', 'string'],
            'priority' => ['required', 'string', 'in:'.implode(',', LabOrder::PRIORITIES)],
            'specimen_type' => ['nullable', 'string', 'max:60'],
            'clinical_note' => ['nullable', 'string', 'max:500'],
            'encounter_id' => ['nullable', 'string'], // the existing linkage (inpatient round / ED-visit encounter)
        ]);

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $labTest = LabTest::query()->whereKey($data['lab_test_id'])->firstOrFail();
        $encounter = isset($data['encounter_id']) && $data['encounter_id'] !== ''
            ? Encounter::query()->whereKey($data['encounter_id'])->first()
            : null;

        try {
            $orders->place($actor, $record, $labTest, $data['priority'], $data['specimen_type'] ?? null, $encounter, $data['clinical_note'] ?? null);
        } catch (LabOrderException|CrossTenantReferenceException|InvalidArgumentException $e) {
            return back()->withErrors(['lab_order' => $e->getMessage()]);
        }

        return redirect()->route('lab.orders.show', $record->id)->with('status', 'lab-order-placed');
    }
}
