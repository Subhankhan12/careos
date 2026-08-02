<?php

namespace Modules\Radiology\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;
use Modules\Radiology\Models\ImagingStudy;
use Modules\Radiology\Models\RadiologyOrder;
use Modules\Radiology\Services\ImagingStudyService;

/**
 * The modality worklist (RAD.G3) — PRESENTATIONAL over {@see ImagingStudyService::worklist}. The radiographer's
 * "studies to acquire": imaging orders awaiting acquisition, shown as operational FACTS (patient, exam,
 * modality, body-part, the RECORDED priority flag, ordered-time). The board / LAB.G5-review idiom, lab-scoped.
 * Gated `radiology.study`. The acquire action registers + acquires the study.
 *
 * ELECTRIC FENCE: the server orders by ordered-time (a fact); it computes NO priority ranking. The recorded
 * STAT flag (from the RAD.G2 order) is shown as a fact — staff MAY sort by it client-side, never a computed
 * "acquire this first". No computed image finding/CAD (there is no image — RAD.G6 is the seam-stub).
 */
class RadiologyWorklistController
{
    public function __invoke(Request $request, ImagingStudyService $studies): Response
    {
        Gate::authorize('radiology.study');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $rows = $studies->worklist($actor)->map(function (RadiologyOrder $order): array {
            $patient = Patient::query()->find($order->patient_id);
            $study = $order->study;

            return [
                'radiology_order_id' => $order->id,
                'patient' => $patient !== null ? trim($patient->first_name.' '.$patient->last_name) : null,
                'exam' => $order->order?->orderableItem?->name,
                'code' => $order->order?->orderableItem?->code,
                'modality' => $order->modality,
                'body_part' => $order->body_part,
                'priority' => $order->priority,   // the RAD.G2 recorded flag (a fact — sortable, not computed)
                'ordered_at' => $order->order?->ordered_at?->toIso8601String(),
                'status' => $study !== null ? $study->status : ImagingStudy::STATUS_ORDERED, // 'ordered' if no study yet
                'acquire_url' => route('radiology.studies.acquire', $order->id),
                'study_url' => route('radiology.studies.show', $order->id),
            ];
        })->all();

        return Inertia::render('Radiology/Worklist', [
            'studies' => $rows,
            'actions' => ['can_acquire' => Gate::allows('radiology.study')],
        ]);
    }
}
