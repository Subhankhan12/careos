<?php

namespace Modules\ED\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\ED\Exceptions\EdVisitException;
use Modules\ED\Models\EdVisit;
use Modules\ED\Models\EdVisitEncounter;
use Modules\ED\Services\EdDocumentationService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * ED clinical documentation (ED.G4) — the ED visit's clinical record: its treatment encounters (reused
 * Clinical `Encounter`s), the raw vitals recorded during the visit, and its orders. PRESENTATIONAL over the
 * REUSED Clinical module (P0D.GU): all logic lives in {@see EdDocumentationService} + the Clinical services;
 * this only resolves inputs and dispatches. Starting an encounter redirects into the EXISTING sign-and-lock
 * note editor (the flow is reused, not rebuilt). String-id `{visit}` (FIX.1). Reads gate on `patient.view`
 * (read-logged); the write actions carry the existing clinical gates (encounter.manage / note.write /
 * order.manage — the ED roles hold them).
 *
 * ELECTRIC FENCE: the payload carries raw vitals + note status + raw order results — NO acuity / severity /
 * deterioration score. Vitals reuse VitalsSeries (raw, no interpretation); the recorded triage acuity is G2's
 * nurse-ASSIGNED value, shown on the triage page — nothing is computed here.
 */
class EdDocumentationController
{
    public function show(Request $request, string $visit, EdDocumentationService $docs): Response
    {
        Gate::authorize('patient.view');
        abort_unless($request->user() instanceof User, 403);

        $record = EdVisit::query()->whereKey($visit)->with('patient')->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        $encounters = $docs->encountersForVisit($record);
        // The current note per treatment Encounter (latest version) — for the editor link + status.
        $notesByEncounter = ClinicalNote::query()
            ->whereIn('encounter_id', $encounters->pluck('encounter_id')->all())
            ->orderByDesc('version')
            ->get()
            ->groupBy('encounter_id');
        // The treating clinician per encounter — resolved as typed StaffProfiles (Encounter's practitioner
        // relation is untyped from this module's view; the ward-board pattern).
        $practitioners = StaffProfile::query()
            ->whereKey($encounters->map(fn (EdVisitEncounter $l): ?string => $l->encounter?->practitioner_id)->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        $canChart = Gate::allows('encounter.manage');
        $canOrder = Gate::allows('order.manage');

        return Inertia::render('ED/Documentation', [
            'visit' => [
                'id' => $record->id,
                'patient' => trim($record->patient->first_name.' '.$record->patient->last_name),
                'status' => $record->status,
                'chief_complaint' => $record->chief_complaint,
                'triage_url' => route('ed.visits.triage.show', $record->id),
            ],
            'encounters' => $encounters->map(function (EdVisitEncounter $link) use ($notesByEncounter, $practitioners): array {
                $note = $notesByEncounter->get($link->encounter_id)?->first();

                return [
                    'id' => $link->id,
                    'practitioner' => $practitioners->get($link->encounter?->practitioner_id)?->display_name,
                    'started_at' => $link->encounter?->started_at?->toIso8601String(),
                    'encounter_status' => $link->encounter?->status,
                    'note' => $note === null ? null : [
                        'id' => $note->id,
                        'status' => $note->status, // draft / signed — the sign-and-lock lifecycle
                        'edit_url' => route('clinical.notes.edit', $note->id),
                    ],
                ];
            })->all(),
            // The ONLY new affordance — the visit's RAW vitals over time (no bands/flags/scores).
            'vitals' => $docs->vitalsForVisit($record),
            'orders' => $docs->ordersForVisit($record)->map(fn (Order $order): array => [
                'id' => $order->id,
                'code' => $order->orderableItem?->code,
                'name' => $order->orderableItem?->name,
                'priority' => $order->priority,
                'status' => $order->status,
                'ordered_at' => $order->ordered_at->toIso8601String(),
                'result_count' => $order->results->count(),
            ])->all(),
            'actions' => [
                'can_chart' => $canChart,
                'can_order' => $canOrder,
                'start_encounter_url' => route('ed.visits.encounter.start', $record->id),
                'record_vital_url' => route('ed.visits.vitals', $record->id),
                'place_order_url' => route('ed.visits.orders', $record->id),
                'clinicians' => $canChart
                    ? StaffProfile::query()->orderBy('display_name')->limit(200)->get(['id', 'display_name'])
                        ->map(fn (StaffProfile $s): array => ['id' => $s->id, 'name' => (string) $s->display_name])->all()
                    : [],
                'orderable_items' => $canOrder
                    ? OrderableItem::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name'])
                        ->map(fn (OrderableItem $i): array => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name])->all()
                    : [],
            ],
        ]);
    }

    public function startEncounter(Request $request, string $visit, EdDocumentationService $docs): RedirectResponse
    {
        Gate::authorize('encounter.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'practitioner_id' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = EdVisit::query()->whereKey($visit)->firstOrFail();
        $practitioner = StaffProfile::query()->whereKey($data['practitioner_id'])->firstOrFail();

        try {
            $result = $docs->startEncounter($actor, $record, $practitioner, $data['reason'] ?? null);
        } catch (InvalidArgumentException|CrossTenantReferenceException $e) {
            // e.g. the one-open-per-practitioner invariant: an open encounter already exists for this clinician.
            return back()->withErrors(['encounter' => $e->getMessage()]);
        }

        // Reuse the EXISTING sign-and-lock note editor for the encounter's note.
        return redirect()->route('clinical.notes.edit', $result['note']->id);
    }

    public function recordVital(Request $request, string $visit, EdDocumentationService $docs): RedirectResponse
    {
        Gate::authorize('note.write');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'systolic' => ['nullable', 'integer'],
            'diastolic' => ['nullable', 'integer'],
            'heart_rate' => ['nullable', 'integer'],
            'temperature_c' => ['nullable', 'numeric'],
            'spo2' => ['nullable', 'integer'],
            'weight_g' => ['nullable', 'integer'],
            'height_mm' => ['nullable', 'integer'],
        ]);

        $record = EdVisit::query()->whereKey($visit)->firstOrFail();

        try {
            $docs->recordVital($actor, $record, $data);
        } catch (EdVisitException $e) {
            return back()->withErrors(['vital' => $e->getMessage()]);
        }

        return redirect()->route('ed.visits.record.show', $record->id)->with('status', 'ed-vital-recorded');
    }

    public function placeOrder(Request $request, string $visit, EdDocumentationService $docs): RedirectResponse
    {
        Gate::authorize('order.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'orderable_item_id' => ['required', 'string'],
            'priority' => ['nullable', 'string', 'max:20'],
            'clinical_note' => ['nullable', 'string', 'max:500'],
        ]);

        $record = EdVisit::query()->whereKey($visit)->firstOrFail();
        $item = OrderableItem::query()->whereKey($data['orderable_item_id'])->firstOrFail();

        try {
            $docs->placeOrder($actor, $record, $item, ['priority' => $data['priority'] ?? Order::PRIORITY_ROUTINE, 'clinical_note' => $data['clinical_note'] ?? null]);
        } catch (EdVisitException|InvalidArgumentException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()->route('ed.visits.record.show', $record->id)->with('status', 'ed-order-placed');
    }
}
