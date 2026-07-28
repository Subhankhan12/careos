<?php

namespace Modules\ED\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ED\Exceptions\EdVisitException;
use Modules\ED\Models\EdVisit;
use Modules\ED\Services\EdVisitService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * The ED tracking board (ED.G3) — the live cockpit of ED patients + their flow state. PRESENTATIONAL over the
 * G1 EdVisit flow entity + the G2 triage domain (P0D.GU): it READS the department's active ED visits with
 * their flow `status`, the RECORDED triage acuity (the nurse's assigned value from G2), and key facts, and it
 * SURFACES the existing flow action (advance the visit via {@see EdVisitService::transition} — the G1
 * legal-only transitions) — it computes no flow logic itself and never bypasses the state machine.
 *
 * It reuses the WARD-BOARD idiom (HOSPITAL.G3) for LAYOUT — a status board over a flow entity — but the data
 * is EdVisits (the ED flow state), not beds/stays.
 *
 * ELECTRIC FENCE (docs/HOSPITAL-PHASE6-ED-MAP.md §2.2/§3): the payload is OPERATIONAL FACTS + the RECORDED
 * acuity ONLY — the flow state, the patient, the presenting complaint, arrival time (elapsed/wait is plain
 * elapsed time the client renders), the nurse-assigned acuity shown as a recorded value with its scale +
 * provenance, and plain department counts. Staff MAY sort by the recorded acuity (a FACT) — but there is NO
 * computed priority ranking, NO acuity-driven "who to see next" judgment, NO wait-time-risk, NO deterioration
 * field. `available_transitions` is the FIXED legal-state map (record-not-judge), not a suggestion.
 *
 * Read = `ed.manage` (ED staff); the surfaced flow action carries the same gate. Tenant + branch scoped.
 * String-id `{visit}` (FIX.1).
 */
class EdBoardController
{
    public function index(Request $request, EdVisitService $visits): Response
    {
        Gate::authorize('ed.manage');
        abort_unless($request->user() instanceof User, 403);

        $active = $visits->activeVisits();
        $canManage = Gate::allows('ed.manage');

        $tiles = $active->map(fn (EdVisit $visit): array => [
            'id' => $visit->id,
            'patient' => trim($visit->patient->first_name.' '.$visit->patient->last_name),
            'branch' => $visit->branch?->name,
            'status' => $visit->status, // the flow state (record-not-judge)
            'arrival_mode' => $visit->arrival_mode,
            'arrived_at' => $visit->arrived_at->toIso8601String(), // wait/elapsed = plain elapsed time client-side
            'presenting_complaint' => $visit->latestTriage !== null
                ? $visit->latestTriage->presenting_complaint
                : $visit->chief_complaint,
            // The RECORDED acuity — the nurse's ASSIGNED value from G2 (a fact) with its scale + provenance.
            // NULL until triaged. This is a recorded value, NOT a computed priority/ranking/score.
            'acuity' => $visit->latestTriage === null ? null : [
                'scale' => $visit->latestTriage->acuity_scale,
                'level' => $visit->latestTriage->acuity_level,
                'by' => $visit->latestTriage->triagedBy?->display_name,
            ],
            // The legal next flow states — a FIXED map (record-not-judge), never a "next patient" suggestion.
            'available_transitions' => EdVisit::TRANSITIONS[$visit->status] ?? [],
            'triage_url' => route('ed.visits.triage.show', $visit->id),
            'transition_url' => $canManage ? route('ed.board.transition', $visit->id) : null,
        ])->all();

        return Inertia::render('ED/Board', [
            'visits' => $tiles,
            // Plain counts of the flow state — facts, NOT a rating.
            'summary' => [
                'total' => $active->count(),
                'waiting' => $active->whereIn('status', [EdVisit::STATUS_ARRIVED, EdVisit::STATUS_TRIAGED])->count(),
                'in_treatment' => $active->where('status', EdVisit::STATUS_IN_TREATMENT)->count(),
                'awaiting_disposition' => $active->where('status', EdVisit::STATUS_AWAITING_DISPOSITION)->count(),
            ],
            'statuses' => EdVisit::STATUSES,
            'actions' => ['can_manage' => $canManage],
        ]);
    }

    /**
     * Advance a visit's flow from the board — the G1 legal-only transition, through the EXISTING
     * {@see EdVisitService::transition} (no new flow logic; no disposition here — that is ED.G5). Gated
     * `ed.manage`; an illegal move throws. String-id {visit} (FIX.1).
     */
    public function transition(Request $request, string $visit, EdVisitService $visits): RedirectResponse
    {
        Gate::authorize('ed.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            // The board advances flow only — dispositioned (which requires a disposition) is ED.G5, excluded here.
            'status' => ['required', 'string', 'in:'.implode(',', [
                EdVisit::STATUS_TRIAGED, EdVisit::STATUS_IN_TREATMENT,
                EdVisit::STATUS_AWAITING_DISPOSITION, EdVisit::STATUS_LEFT_WITHOUT_BEING_SEEN,
            ])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = EdVisit::query()->whereKey($visit)->firstOrFail();

        try {
            $visits->transition($actor, $record, $data['status'], $data['reason'] ?? null);
        } catch (EdVisitException|CrossTenantReferenceException $e) {
            return back()->withErrors(['ed_visit' => $e->getMessage()]);
        }

        return redirect()->route('ed.board')->with('status', 'ed-visit-updated');
    }
}
