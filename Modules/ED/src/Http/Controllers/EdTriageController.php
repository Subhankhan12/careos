<?php

namespace Modules\ED\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Clinical\Models\Vital;
use Modules\ED\Contracts\TriageAcuityProvider;
use Modules\ED\Exceptions\EdVisitException;
use Modules\ED\Models\EdTriage;
use Modules\ED\Models\EdVisit;
use Modules\ED\Services\TriageService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * ED triage (ED.G2) — PRESENTATIONAL over {@see TriageService}. Records the presenting complaint, RAW vitals,
 * and the nurse-ASSIGNED acuity for an {@see EdVisit}, and shows the append-only triage history. String-id
 * (FIX.1). Viewing is `patient.view` (read-logged); recording is `triage.record` (the triage nurse).
 *
 * ELECTRIC FENCE: the acuity the nurse SELECTS is recorded verbatim (a fact, the ASA precedent) — nothing here
 * computes/ranks it. The "suggestion" area is wired to the {@see TriageAcuityProvider}
 * seam and shows NOTHING today (the null-object returns no suggestion); a certified partner would make it
 * ADVISORY + human-owned, never auto-assigning.
 */
class EdTriageController
{
    public function show(Request $request, string $visit, TriageService $triage): Response
    {
        Gate::authorize('patient.view');
        abort_unless($request->user() instanceof User, 403);

        $record = EdVisit::query()->with('patient')->whereKey($visit)->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        $suggestion = $triage->acuitySuggestion($record); // the empty seam — none() today

        return Inertia::render('ED/Triage', [
            'visit' => [
                'id' => $record->id,
                'patient' => ['id' => $record->patient_id, 'name' => trim($record->patient->first_name.' '.$record->patient->last_name)],
                'arrival_mode' => $record->arrival_mode,
                'chief_complaint' => $record->chief_complaint,
                'status' => $record->status,
                'arrived_at' => $record->arrived_at->toIso8601String(),
            ],
            'triages' => $triage->forVisit($record)->map(fn (EdTriage $t): array => [
                'id' => $t->id,
                'presenting_complaint' => $t->presenting_complaint,
                'acuity_scale' => $t->acuity_scale,
                'acuity_level' => $t->acuity_level,          // the ASSIGNED value (a recorded fact)
                'triaged_by' => $t->triagedBy?->display_name, // provenance
                'triaged_at' => $t->triaged_at->toIso8601String(),
            ])->all(),
            'vitals' => $triage->vitalsForVisit($record)->map(fn (Vital $v): array => [
                'recorded_at' => $v->recorded_at->toIso8601String(),
                'systolic' => $v->systolic,
                'diastolic' => $v->diastolic,
                'heart_rate' => $v->heart_rate,
                'temperature_c' => $v->temperature_c,
                'spo2' => $v->spo2,
            ])->all(),
            // The seam's advisory output — NULL today (CareOS computes no acuity). The UI shows an empty
            // "no automated suggestion" area; a certified partner would fill it, advisory + human-owned.
            'acuity_suggestion' => $suggestion->hasSuggestion()
                ? ['level' => $suggestion->suggestedLevel, 'scale' => $suggestion->scale]
                : null,
            'options' => [
                'scales' => EdTriage::SCALES,
                'levels' => EdTriage::LEVELS, // the ASSIGNABLE levels per scale (a closed pick-list, not a ranking)
                'nurses' => StaffProfile::query()->orderBy('display_name')->limit(200)->get()
                    ->map(fn (StaffProfile $s): array => ['id' => $s->id, 'name' => (string) $s->display_name])->all(),
            ],
            'actions' => [
                'can_record' => Gate::allows('triage.record'),
                'store_url' => route('ed.visits.triage.store', $record->id),
            ],
        ]);
    }

    public function store(Request $request, string $visit, TriageService $triage): RedirectResponse
    {
        Gate::authorize('triage.record');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'triaged_by' => ['required', 'string'],
            'presenting_complaint' => ['required', 'string', 'max:500'],
            'acuity_scale' => ['required', 'string', 'in:'.implode(',', EdTriage::SCALES)],
            'acuity_level' => ['required', 'string', 'max:32'],
            // RAW vitals — optional, integers/decimal; recorded verbatim (no bands/flags).
            'systolic' => ['nullable', 'integer'],
            'diastolic' => ['nullable', 'integer'],
            'heart_rate' => ['nullable', 'integer'],
            'temperature_c' => ['nullable', 'numeric'],
            'spo2' => ['nullable', 'integer'],
        ]);

        $record = EdVisit::query()->whereKey($visit)->firstOrFail();
        $nurse = StaffProfile::query()->whereKey($data['triaged_by'])->firstOrFail();

        try {
            $triage->record(
                $actor,
                $record,
                $nurse,
                $data['presenting_complaint'],
                $data['acuity_scale'],
                $data['acuity_level'],
                [
                    'systolic' => $data['systolic'] ?? null,
                    'diastolic' => $data['diastolic'] ?? null,
                    'heart_rate' => $data['heart_rate'] ?? null,
                    'temperature_c' => $data['temperature_c'] ?? null,
                    'spo2' => $data['spo2'] ?? null,
                ],
            );
        } catch (EdVisitException|CrossTenantReferenceException $e) {
            return back()->withErrors(['triage' => $e->getMessage()]);
        }

        return redirect()->route('ed.visits.triage.show', $record->id)->with('status', 'ed-triage-recorded');
    }
}
