<?php

namespace Modules\Dental\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\Diagnosis;
use Modules\Dental\Models\DiagnosisTerm;
use Modules\Dental\Services\DiagnosisService;
use Modules\Dental\Support\ToothNotation;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

/**
 * The diagnosis UI (DENTAL.G7) — PRESENTATIONAL over the DiagnosisService (P0D.GU), and the
 * sharpest fence in the vertical. The DENTIST authors the diagnosis; this screen records it and
 * reads it back. It proposes NOTHING, ranks NOTHING, suggests NOTHING, and auto-populates NOTHING.
 *
 * ELECTRIC FENCE carried into the UI: the payload carries only what the dentist entered — the
 * label they wrote/picked, the status THEY set, an optional tooth, and their findings. There is
 * NO suggested / proposed / differential / likelihood / confidence / ranked / ai / recommended
 * field, and no "suggested diagnosis" panel. The tenant term list is a plain alphabetical
 * pick-list of the tenant's own terms (never ranked).
 *
 * All logic (append-only recording, deterministic validation, tenant scoping, audit, patient-
 * scoped read-logging) lives in DiagnosisService. String-id `{patient}` (FIX.1 / D-090).
 * show = `patient.view`, store = `dental.chart`.
 */
class DiagnosisController
{
    public function show(Request $request, string $patient, DiagnosisService $diagnoses): Response
    {
        Gate::authorize('patient.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = Patient::query()->whereKey($patient)->firstOrFail();

        // The service owns the read (patient-scoped read-log) and the ordering.
        $list = $diagnoses->diagnosesFor($actor, $record);

        return Inertia::render('Dental/Diagnoses', [
            'patient' => [
                'id' => $record->id,
                'mrn' => $record->mrn,
                'name' => trim($record->first_name.' '.$record->last_name),
            ],
            'diagnoses' => $list->map(fn (Diagnosis $d): array => $this->present($d))->values()->all(),
            // The tenant's OWN pick-list — a plain alphabetical list, never ranked/suggested.
            'terms' => $diagnoses->terms($actor)->map(fn (DiagnosisTerm $t): array => [
                'id' => $t->id,
                'label' => $t->label,
            ])->values()->all(),
            // The statuses the DENTIST may set + the FDI tooth universe (facts, no suggestion).
            'statuses' => Diagnosis::STATUSES,
            'teeth' => [
                'permanent' => ToothNotation::permanent(),
                'primary' => ToothNotation::primary(),
            ],
            'surfaces' => ToothNotation::SURFACES,
            'actions' => [
                'can_record' => Gate::allows('dental.chart'),
                'store_url' => route('dental.diagnoses.store', $record->id),
                'term_url' => route('dental.diagnosis-terms.store'),
            ],
        ]);
    }

    public function store(Request $request, string $patient, DiagnosisService $diagnoses): RedirectResponse
    {
        Gate::authorize('dental.chart');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:40'],
            'diagnosis_term_id' => ['nullable', 'string'],
            'tooth' => ['nullable', 'string', 'max:2'],
            'surface' => ['nullable', 'string', 'max:20'],
            'findings' => ['nullable', 'string', 'max:4000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = Patient::query()->whereKey($patient)->firstOrFail();

        $tooth = ($data['tooth'] ?? '') === '' ? null : $data['tooth'];
        $surface = ($data['surface'] ?? '') === '' ? null : $data['surface'];
        $termId = ($data['diagnosis_term_id'] ?? '') === '' ? null : $data['diagnosis_term_id'];

        try {
            // Append-only recording through the tested service — the ONLY write path. The dentist
            // authored every field; the service/model validate them deterministically (no
            // suggestion, no inference) and an invalid value throws.
            $diagnoses->record($actor, $record, $data['label'], $data['status'], $tooth, $surface, $data['findings'] ?? null, $termId, $data['reason'] ?? null);
        } catch (DentalException $e) {
            return back()->withErrors(['diagnosis' => $e->getMessage()]);
        }

        return redirect()->route('dental.diagnoses', $record->id)->with('status', 'recorded');
    }

    /**
     * Add a term to the tenant's own diagnosis pick-list (dental.chart). A tenant-authored
     * convenience — never a licensed code set, never a suggestion.
     */
    public function storeTerm(Request $request, DiagnosisService $diagnoses): RedirectResponse
    {
        Gate::authorize('dental.chart');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate(['label' => ['required', 'string', 'max:255']]);

        try {
            $diagnoses->addTerm($actor, $data['label']);
        } catch (DentalException $e) {
            return back()->withErrors(['term' => $e->getMessage()]);
        }

        return back()->with('status', 'term_added');
    }

    /**
     * A diagnosis as the dentist authored it — no suggestion/ranking/likelihood field anywhere.
     *
     * @return array<string, mixed>
     */
    private function present(Diagnosis $diagnosis): array
    {
        return [
            'id' => $diagnosis->id,
            'label' => $diagnosis->label,
            'status' => $diagnosis->status,
            'tooth' => $diagnosis->tooth,
            'surface' => $diagnosis->surface,
            'findings' => $diagnosis->findings,
            'reason' => $diagnosis->reason,
            'is_free_text' => $diagnosis->diagnosis_term_id === null,
            'diagnosed_by' => $diagnosis->diagnosed_by,
            'diagnosed_at' => $diagnosis->diagnosed_at->toIso8601String(),
        ];
    }
}
