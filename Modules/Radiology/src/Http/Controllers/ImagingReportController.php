<?php

namespace Modules\Radiology\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Radiology\Exceptions\RadiologyReportException;
use Modules\Radiology\Models\ImagingStudy;
use Modules\Radiology\Models\RadiologyOrder;
use Modules\Radiology\Services\ImagingStudyService;
use Modules\Radiology\Services\RadiologyReportService;

/**
 * The radiologist's report (RAD.G4) — PRESENTATIONAL over {@see RadiologyReportService}. THE FENCE GATE. From a
 * study, the radiologist AUTHORS a report (findings + impression as PROSE) via the reused sign-and-lock
 * `ClinicalNote` (write → sign → read-only → amend → version); signing files it (study → reported, Order →
 * resulted) and the EXISTING order → review flow routes it to the ordering clinician. String-id (FIX.1).
 * Viewing is `patient.view` (read-logged); authoring is `note.write`; signing is `note.sign`.
 *
 * ELECTRIC FENCE: the report is the radiologist's AUTHORED prose — this screen records it; it computes NO image
 * finding/CAD/abnormality/confidence/auto-read/suggested-diagnosis, and NOTHING auto-populates the report. AI
 * radiology is a hard non-goal. The image itself is the PACS partner's (RAD.G6) — not a diagnostic viewer here.
 */
class ImagingReportController
{
    public function show(Request $request, string $radiologyOrder, RadiologyReportService $reports, ImagingStudyService $studies): Response
    {
        Gate::authorize('patient.view');
        abort_unless($request->user() instanceof User, 403);

        $record = RadiologyOrder::query()->with(['order.orderableItem'])->whereKey($radiologyOrder)->firstOrFail();
        $patient = Patient::query()->findOrFail($record->patient_id);
        $patient->auditRead(); // patient-scoped read log

        $study = $studies->forOrder($record);
        $versions = $study !== null ? $reports->versionsFor($study) : collect();

        return Inertia::render('Radiology/Report', [
            'order' => [
                'id' => $record->id,
                'patient' => trim($patient->first_name.' '.$patient->last_name),
                'exam' => $record->order?->orderableItem?->name,
                'code' => $record->order?->orderableItem?->code,
                'modality' => $record->modality,
                'body_part' => $record->body_part,
                'priority' => $record->priority,
                'order_status' => $record->order?->status,
                'study_url' => route('radiology.studies.show', $record->id),
            ],
            'study' => $study === null ? null : [
                'id' => $study->id,
                'accession_number' => $study->accession_number,
                'status' => $study->status,
            ],
            // The report versions — AUTHORED prose (findings = objective, impression = assessment). No computed field.
            'versions' => $versions->map(fn (ClinicalNote $n): array => [
                'id' => $n->id,
                'version' => $n->version,
                'status' => $n->status,
                'findings' => $n->objective,       // authored prose
                'impression' => $n->assessment,    // authored prose
                'amendment_reason' => $n->amendment_reason,
                'signed_at' => $n->signed_at?->toIso8601String(),
            ])->all(),
            'actions' => [
                'can_write' => Gate::allows('note.write'),
                'can_sign' => Gate::allows('note.sign'),
                'save_url' => route('radiology.reports.save', $record->id),
                'sign_url' => route('radiology.reports.sign', $record->id),
                'amend_url' => route('radiology.reports.amend', $record->id),
                'review_worklist_url' => route('clinical.orders.worklist'), // the EXISTING order → review flow
            ],
        ]);
    }

    public function store(Request $request, string $radiologyOrder, RadiologyReportService $reports, ImagingStudyService $studies): RedirectResponse
    {
        Gate::authorize('note.write');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'findings' => ['nullable', 'string', 'max:5000'],
            'impression' => ['nullable', 'string', 'max:5000'],
        ]);

        [$study, $radiologist] = $this->resolve($request, $radiologyOrder, $studies);
        if (! $radiologist instanceof StaffProfile) {
            return $this->unidentifiedAuthor(); // refuse rather than guess (D-216)
        }

        try {
            $reports->saveDraft($actor, $study, $radiologist, $data['findings'] ?? null, $data['impression'] ?? null);
        } catch (RadiologyReportException|CrossTenantReferenceException|InvalidArgumentException $e) {
            return back()->withErrors(['radiology_report' => $e->getMessage()]);
        }

        return redirect()->route('radiology.reports.show', $radiologyOrder)->with('status', 'radiology-report-saved');
    }

    public function sign(Request $request, string $radiologyOrder, RadiologyReportService $reports, ImagingStudyService $studies): RedirectResponse
    {
        Gate::authorize('note.sign');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        [$study] = $this->resolve($request, $radiologyOrder, $studies);

        try {
            $reports->sign($actor, $study);
        } catch (RadiologyReportException|CrossTenantReferenceException|InvalidArgumentException $e) {
            return back()->withErrors(['radiology_report' => $e->getMessage()]);
        }

        return redirect()->route('radiology.reports.show', $radiologyOrder)->with('status', 'radiology-report-signed');
    }

    public function amend(Request $request, string $radiologyOrder, RadiologyReportService $reports, ImagingStudyService $studies): RedirectResponse
    {
        Gate::authorize('note.write');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'findings' => ['nullable', 'string', 'max:5000'],
            'impression' => ['nullable', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        [$study, $radiologist] = $this->resolve($request, $radiologyOrder, $studies);
        if (! $radiologist instanceof StaffProfile) {
            return $this->unidentifiedAuthor(); // refuse rather than guess (D-216)
        }

        try {
            $reports->amend($actor, $study, $radiologist, $data['findings'] ?? null, $data['impression'] ?? null, $data['reason']);
        } catch (RadiologyReportException|CrossTenantReferenceException|InvalidArgumentException $e) {
            return back()->withErrors(['radiology_report' => $e->getMessage()]);
        }

        return redirect()->route('radiology.reports.show', $radiologyOrder)->with('status', 'radiology-report-amended');
    }

    /**
     * Resolve the study (must be acquired) + the acting radiologist's StaffProfile (the report author).
     *
     * A PERSON IS NEVER RESOLVED BY CONVENIENCE (QA-FIX.8b, P8-C2, D-216). This used to end in
     * `?? StaffProfile::query()->orderBy('display_name')->firstOrFail()` — so when the acting user had no
     * linked profile, the report was authored by whoever sorted first alphabetically. Driven in Phase 8: a
     * report written by `miriam.lang` was stored as **Beat Suter**, a coordinator, and the same profile
     * also became the report ENCOUNTER's practitioner. There is no dropdown in this module, so nothing on
     * screen could have shown the operator that it had happened.
     *
     * The second element is therefore NULLABLE: {@see StaffProfile::forUser()} returns null rather than
     * guessing (the QA-FIX.2a / D-195 rule), and a caller that needs an author REFUSES — exactly as
     * `NoteEditorController::amend` and `OpenEncounterFromAppointmentController` already do. `sign()` does
     * NOT need one (a signature is the acting USER, `clinical_notes.signed_by`), so it stays reachable for
     * an account without a profile; only authoring refuses.
     *
     * @return array{0: ImagingStudy, 1: StaffProfile|null}
     */
    private function resolve(Request $request, string $radiologyOrder, ImagingStudyService $studies): array
    {
        $order = RadiologyOrder::query()->whereKey($radiologyOrder)->firstOrFail();
        $study = $studies->forOrder($order);
        abort_if($study === null, 404);

        return [$study, StaffProfile::forUser($request->user())];
    }

    /**
     * The refusal a caller gets when the acting user cannot be identified as a clinician. Authoring a
     * clinical report in somebody else's name is the defect being closed, so a caller we cannot identify
     * gets no report — the wording follows the two Clinical controllers that already refuse this way.
     */
    private function unidentifiedAuthor(): RedirectResponse
    {
        return back()->withErrors([
            'radiology_report' => 'Your user account has no staff profile, so a report cannot record who wrote it.',
        ]);
    }
}
