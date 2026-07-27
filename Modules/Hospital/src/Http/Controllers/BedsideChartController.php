<?php

namespace Modules\Hospital\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\WardRound;
use Modules\Hospital\Services\BedsideChartService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\User;

/**
 * Bedside charting for an inpatient stay (HOSPITAL.G4) — the stay's chart: its ward rounds (reused
 * Clinical Encounters), the raw vitals recorded over the stay, and its orders. PRESENTATIONAL over
 * the reused Clinical module (P0D.GU): all logic lives in BedsideChartService + the Clinical services;
 * this only resolves inputs and dispatches. Starting a round redirects into the EXISTING sign-and-lock
 * note editor (the flow is reused, not rebuilt). String-id {stay} (FIX.1). Reads gate on `patient.view`
 * (read-logged); the write actions carry the existing clinical gates (encounter.manage / note.write /
 * order.manage — the inpatient roles hold them).
 *
 * ELECTRIC FENCE: the payload carries raw vitals + note status + raw order results — NO acuity /
 * severity / early-warning score. Vitals reuse VitalsSeries (raw, no interpretation).
 */
class BedsideChartController
{
    public function show(Request $request, string $stay, BedsideChartService $chart): Response
    {
        Gate::authorize('patient.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = Stay::query()->whereKey($stay)->with(['patient', 'currentBed', 'currentWard'])->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        $rounds = $chart->roundsForStay($record);
        // The current note per round Encounter (latest version) — for the editor link + status.
        $notesByEncounter = ClinicalNote::query()
            ->whereIn('encounter_id', $rounds->pluck('encounter_id')->all())
            ->orderByDesc('version')
            ->get()
            ->groupBy('encounter_id');
        // The rounding clinician per round (the round Encounter's practitioner) — resolved as typed
        // StaffProfiles (Encounter's practitioner relation is untyped from this module's view).
        $practitioners = StaffProfile::query()
            ->whereKey($rounds->map(fn (WardRound $r): ?string => $r->encounter?->practitioner_id)->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        $canChart = Gate::allows('encounter.manage');
        $canOrder = Gate::allows('order.manage');

        return Inertia::render('Hospital/StayChart', [
            'stay' => [
                'id' => $record->id,
                'patient' => trim($record->patient->first_name.' '.$record->patient->last_name),
                'status' => $record->status,
                'bed' => $record->currentBed?->label,
                'ward' => $record->currentWard?->name,
            ],
            'rounds' => $rounds->map(function (WardRound $round) use ($notesByEncounter, $practitioners): array {
                $note = $notesByEncounter->get($round->encounter_id)?->first();

                return [
                    'id' => $round->id,
                    'practitioner' => $practitioners->get($round->encounter?->practitioner_id)?->display_name,
                    'started_at' => $round->encounter?->started_at?->toIso8601String(),
                    'encounter_status' => $round->encounter?->status,
                    'note' => $note === null ? null : [
                        'id' => $note->id,
                        'status' => $note->status, // draft / signed — the sign-and-lock lifecycle
                        'edit_url' => route('clinical.notes.edit', $note->id),
                    ],
                ];
            })->all(),
            // The ONLY new affordance — the stay's RAW vitals over time (no bands/flags/scores).
            'vitals' => $chart->vitalsForStay($record),
            'orders' => $chart->ordersForStay($record)->map(fn (Order $order): array => [
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
                'start_round_url' => route('hospital.admissions.rounds.start', $record->id),
                'record_vital_url' => route('hospital.admissions.vitals', $record->id),
                'place_order_url' => route('hospital.admissions.orders', $record->id),
                'orderable_items' => $canOrder
                    ? OrderableItem::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name'])
                        ->map(fn (OrderableItem $i): array => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name])->all()
                    : [],
            ],
        ]);
    }

    public function startRound(Request $request, string $stay, BedsideChartService $chart): RedirectResponse
    {
        Gate::authorize('encounter.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $record = Stay::query()->whereKey($stay)->firstOrFail();

        try {
            $result = $chart->startRound($actor, $record, $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            // e.g. the one-open-per-practitioner invariant: a round is already open for this stay.
            return back()->withErrors(['round' => $e->getMessage()]);
        }

        // Reuse the EXISTING sign-and-lock note editor for the round's note.
        return redirect()->route('clinical.notes.edit', $result['note']->id);
    }

    public function recordVital(Request $request, string $stay, BedsideChartService $chart): RedirectResponse
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

        $record = Stay::query()->whereKey($stay)->firstOrFail();

        try {
            $chart->recordVital($actor, $record, $data);
        } catch (AdmissionException $e) {
            return back()->withErrors(['vital' => $e->getMessage()]);
        }

        return redirect()->route('hospital.admissions.chart', $record->id)->with('status', 'vital-recorded');
    }

    public function placeOrder(Request $request, string $stay, BedsideChartService $chart): RedirectResponse
    {
        Gate::authorize('order.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'orderable_item_id' => ['required', 'string'],
            'priority' => ['nullable', 'string', 'max:20'],
            'clinical_note' => ['nullable', 'string', 'max:500'],
        ]);

        $record = Stay::query()->whereKey($stay)->firstOrFail();
        $item = OrderableItem::query()->whereKey($data['orderable_item_id'])->firstOrFail();

        try {
            $chart->placeOrder($actor, $record, $item, ['priority' => $data['priority'] ?? Order::PRIORITY_ROUTINE, 'clinical_note' => $data['clinical_note'] ?? null]);
        } catch (AdmissionException|InvalidArgumentException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()->route('hospital.admissions.chart', $record->id)->with('status', 'order-placed');
    }
}
