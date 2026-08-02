<?php

namespace Modules\Radiology\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Radiology\Exceptions\ImagingStudyException;
use Modules\Radiology\Models\ImagingStudy;
use Modules\Radiology\Models\ImagingStudyEvent;
use Modules\Radiology\Models\RadiologyOrder;
use Modules\Radiology\Services\ImagingStudyService;

/**
 * Imaging-study tracking (RAD.G3) — PRESENTATIONAL over {@see ImagingStudyService}. From an imaging order,
 * acquire the study (accession generated) and advance its legal-only state (ordered → acquired → reported; or
 * cancelled); view the study + its append-only state history. String-id (FIX.1). Viewing is `patient.view`
 * (read-logged); acquiring + transitioning are `radiology.study` (the radiographer).
 *
 * ELECTRIC FENCE (operational): the state + accession are FACTS — the screen records them; it computes NO image
 * finding/CAD/abnormality and NO priority. **THE STUDY IS METADATA, NOT THE IMAGE** — the DICOM image
 * (storage / a diagnostic viewer / PACS) is the SEAM-STUBBED RAD.G6, NOT built. (An optional uploaded still via
 * the dental `DocumentService` — a limited manual export, NOT a diagnostic viewer — is deferred to a later gate.)
 */
class ImagingStudyController
{
    public function show(Request $request, string $radiologyOrder, ImagingStudyService $studies): Response
    {
        Gate::authorize('patient.view');
        abort_unless($request->user() instanceof User, 403);

        $record = RadiologyOrder::query()->with(['order.orderableItem'])->whereKey($radiologyOrder)->firstOrFail();
        $patient = Patient::query()->findOrFail($record->patient_id);
        $patient->auditRead(); // patient-scoped read log

        $study = $studies->forOrder($record);

        return Inertia::render('Radiology/Study', [
            'order' => [
                'id' => $record->id,
                'patient' => trim($patient->first_name.' '.$patient->last_name),
                'exam' => $record->order?->orderableItem?->name,
                'code' => $record->order?->orderableItem?->code,
                'modality' => $record->modality,
                'body_part' => $record->body_part,
                'priority' => $record->priority,          // the RAD.G2 recorded flag (shown as a fact)
                'order_status' => $record->order?->status, // the reused Clinical Order lifecycle state
            ],
            'study' => $study === null ? null : [
                'id' => $study->id,
                'accession_number' => $study->accession_number, // a factual identifier
                'modality' => $study->modality,
                'status' => $study->status,                     // where the study is (a fact)
                'acquired_at' => $study->acquired_at?->toIso8601String(),
                'available_transitions' => ImagingStudy::TRANSITIONS[$study->status] ?? [], // the FIXED legal map
                'transition_url' => route('radiology.studies.transition', $study->id),
                'events' => $study->events()->orderBy('occurred_at')->get()->map(fn (ImagingStudyEvent $e): array => [
                    'event_type' => $e->event_type,
                    'reason' => $e->reason,
                    'occurred_at' => $e->occurred_at->toIso8601String(),
                ])->all(),
            ],
            // No image is stored/viewed here — the DICOM image path is the partner-gated RAD.G6 seam-stub.
            'image' => ['available' => false, 'note' => 'seam-stubbed'],
            'actions' => [
                'can_acquire' => Gate::allows('radiology.study'),
                'acquire_url' => route('radiology.studies.acquire', $record->id),
            ],
        ]);
    }

    public function acquire(Request $request, string $radiologyOrder, ImagingStudyService $studies): RedirectResponse
    {
        Gate::authorize('radiology.study');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = RadiologyOrder::query()->whereKey($radiologyOrder)->firstOrFail();

        try {
            $studies->acquire($actor, $record);
        } catch (ImagingStudyException|CrossTenantReferenceException $e) {
            return back()->withErrors(['imaging_study' => $e->getMessage()]);
        }

        return redirect()->route('radiology.studies.show', $record->id)->with('status', 'imaging-study-acquired');
    }

    public function transition(Request $request, string $study, ImagingStudyService $studies): RedirectResponse
    {
        Gate::authorize('radiology.study');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', ImagingStudy::STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = ImagingStudy::query()->whereKey($study)->firstOrFail();

        try {
            $studies->transition($actor, $record, $data['status'], $data['reason'] ?? null);
        } catch (ImagingStudyException|CrossTenantReferenceException $e) {
            return back()->withErrors(['imaging_study' => $e->getMessage()]);
        }

        return redirect()->route('radiology.studies.show', $record->radiology_order_id)->with('status', 'imaging-study-updated');
    }
}
