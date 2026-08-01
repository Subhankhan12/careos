<?php

namespace Modules\Lab\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Lab\Exceptions\SpecimenException;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\Specimen;
use Modules\Lab\Models\SpecimenEvent;
use Modules\Lab\Services\SpecimenService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * Specimen tracking (LAB.G3) — PRESENTATIONAL over {@see SpecimenService}. From a lab order, collect a
 * specimen (accession generated) and advance its legal-only state (collected → in_lab → resulted; or
 * rejected); view the specimen + its append-only state history. String-id (FIX.1). Viewing is `patient.view`
 * (read-logged); collecting + transitioning are `lab.result` (the phlebotomist / lab tech).
 *
 * ELECTRIC FENCE (operational): the state + accession are FACTS — the screen records them; it computes NO
 * priority/urgency and auto-routes nothing. The STAT flag (shown for context) is the LAB.G2 recorded flag.
 */
class SpecimenController
{
    public function show(Request $request, string $labOrder, SpecimenService $specimens): Response
    {
        Gate::authorize('patient.view');
        abort_unless($request->user() instanceof User, 403);

        $record = LabOrder::query()->with(['order.orderableItem'])->whereKey($labOrder)->firstOrFail();
        $patient = Patient::query()->findOrFail($record->patient_id);
        $patient->auditRead(); // patient-scoped read log

        return Inertia::render('Lab/Specimens', [
            'labOrder' => [
                'id' => $record->id,
                'patient' => trim($patient->first_name.' '.$patient->last_name),
                'test' => $record->order?->orderableItem?->name,
                'code' => $record->order?->orderableItem?->code,
                'specimen_type' => $record->specimen_type,
                'priority' => $record->priority,          // the LAB.G2 recorded flag (shown as a fact)
                'order_status' => $record->order?->status, // the reused Clinical Order lifecycle state
            ],
            'specimens' => $specimens->forOrder($record)->map(fn (Specimen $s): array => [
                'id' => $s->id,
                'accession_number' => $s->accession_number, // a factual identifier
                'specimen_type' => $s->specimen_type,
                'container_type' => $s->container_type,
                'status' => $s->status,                     // where the specimen is (a fact)
                'collected_at' => $s->collected_at->toIso8601String(),
                'available_transitions' => Specimen::TRANSITIONS[$s->status] ?? [], // the FIXED legal map (record-not-judge)
                'transition_url' => route('lab.specimens.transition', $s->id),
                'events' => $s->events()->orderBy('occurred_at')->get()->map(fn (SpecimenEvent $e): array => [
                    'event_type' => $e->event_type,
                    'reason' => $e->reason,
                    'occurred_at' => $e->occurred_at->toIso8601String(),
                ])->all(),
            ])->all(),
            'options' => ['statuses' => Specimen::STATUSES],
            'actions' => [
                'can_collect' => Gate::allows('lab.result'),
                'collect_url' => route('lab.specimens.collect', $record->id),
            ],
        ]);
    }

    public function collect(Request $request, string $labOrder, SpecimenService $specimens): RedirectResponse
    {
        Gate::authorize('lab.result');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'container_type' => ['nullable', 'string', 'max:60'],
            'collection_note' => ['nullable', 'string', 'max:500'],
        ]);

        $record = LabOrder::query()->whereKey($labOrder)->firstOrFail();

        try {
            $specimens->collect($actor, $record, $data['container_type'] ?? null, $data['collection_note'] ?? null);
        } catch (SpecimenException|CrossTenantReferenceException $e) {
            return back()->withErrors(['specimen' => $e->getMessage()]);
        }

        return redirect()->route('lab.specimens.show', $record->id)->with('status', 'specimen-collected');
    }

    public function transition(Request $request, string $specimen, SpecimenService $specimens): RedirectResponse
    {
        Gate::authorize('lab.result');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', Specimen::STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = Specimen::query()->whereKey($specimen)->firstOrFail();

        try {
            $specimens->transition($actor, $record, $data['status'], $data['reason'] ?? null);
        } catch (SpecimenException|CrossTenantReferenceException $e) {
            return back()->withErrors(['specimen' => $e->getMessage()]);
        }

        return redirect()->route('lab.specimens.show', $record->lab_order_id)->with('status', 'specimen-updated');
    }
}
