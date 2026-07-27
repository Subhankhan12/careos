<?php

namespace Modules\Hospital\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Hospital\Exceptions\HandoverException;
use Modules\Hospital\Models\Handover;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Services\HandoverService;
use Modules\Platform\Models\User;

/**
 * Nursing shift handover for an inpatient stay (HOSPITAL.G5) — the SBAR handover the outgoing nurse
 * authors + the stay's shift-by-shift history. PRESENTATIONAL (P0D.GU): all logic lives in
 * HandoverService; this resolves inputs and dispatches. String-id {stay} (FIX.1). Read = patient.view
 * (read-logged); record = note.write (the nursing roles hold it). Record-not-judge: nurse-authored
 * only, nothing computed.
 */
class HandoverController
{
    public function show(Request $request, string $stay, HandoverService $handovers): Response
    {
        Gate::authorize('patient.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = Stay::query()->whereKey($stay)->with(['patient', 'currentBed', 'currentWard'])->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        return Inertia::render('Hospital/Handover', [
            'stay' => [
                'id' => $record->id,
                'patient' => trim($record->patient->first_name.' '.$record->patient->last_name),
                'status' => $record->status,
                'bed' => $record->currentBed?->label,
                'ward' => $record->currentWard?->name,
            ],
            // The raw shift trail — nurse-authored SBAR, newest first. No judgment field.
            'handovers' => $handovers->history($record)->map(fn (Handover $h): array => [
                'id' => $h->id,
                'shift' => $h->shift,
                'situation' => $h->situation,
                'background' => $h->background,
                'assessment' => $h->assessment,
                'recommendation' => $h->recommendation,
                'reason' => $h->reason,
                'handed_over_at' => $h->handed_over_at->toIso8601String(),
            ])->all(),
            'shifts' => Handover::SHIFTS,
            'actions' => [
                'can_record' => Gate::allows('note.write'),
                'store_url' => route('hospital.admissions.handover.store', $record->id),
            ],
        ]);
    }

    public function store(Request $request, string $stay, HandoverService $handovers): RedirectResponse
    {
        Gate::authorize('note.write');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'shift' => ['required', 'string', 'max:20'],
            'situation' => ['required', 'string', 'max:5000'],
            'background' => ['nullable', 'string', 'max:5000'],
            'assessment' => ['nullable', 'string', 'max:5000'],
            'recommendation' => ['nullable', 'string', 'max:5000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = Stay::query()->whereKey($stay)->firstOrFail();

        try {
            $handovers->record($actor, $record, $data['shift'], [
                'situation' => $data['situation'],
                'background' => $data['background'] ?? null,
                'assessment' => $data['assessment'] ?? null,
                'recommendation' => $data['recommendation'] ?? null,
            ], $data['reason'] ?? null);
        } catch (HandoverException $e) {
            return back()->withErrors(['handover' => $e->getMessage()]);
        }

        return redirect()->route('hospital.admissions.handover', $record->id)->with('status', 'handover-recorded');
    }
}
