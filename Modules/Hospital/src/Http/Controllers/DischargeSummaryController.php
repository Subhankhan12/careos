<?php

namespace Modules\Hospital\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\Invoice;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Hospital\Models\Handover;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\StayEvent;
use Modules\Hospital\Models\WardRound;
use Modules\Hospital\Services\BedBillingService;
use Modules\Hospital\Services\BedsideChartService;
use Modules\Hospital\Services\DischargeSummaryService;
use Modules\Hospital\Services\HandoverService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * The discharge summary + closed-episode view (HOSPITAL.G7) — the Phase-1 close-out. PRESENTATIONAL over
 * `DischargeSummaryService` (sign-and-lock) + read composition of the stay's EXISTING records (the ADT
 * journey [G2], ward rounds [G4], handovers [G5], and the stay's invoice[s] [G6]). String-id {stay}
 * (FIX.1); read gate `patient.view` (read-logged); authoring `note.write`, finalizing `note.sign`.
 * ELECTRIC FENCE: length-of-stay is a DERIVED fact, the narrative is the clinician's own words — nothing
 * is computed or graded.
 */
class DischargeSummaryController
{
    public function show(
        Request $request,
        string $stay,
        DischargeSummaryService $summaries,
        BedsideChartService $charts,
        HandoverService $handovers,
        BedBillingService $billing,
    ): Response {
        Gate::authorize('patient.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = Stay::query()->whereKey($stay)->with(['patient', 'currentBed', 'currentWard', 'admittingClinician'])->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        $summary = $summaries->forStay($record);
        $events = StayEvent::query()->where('stay_id', $record->id)->orderBy('occurred_at')->orderBy('id')->get();

        return Inertia::render('Hospital/DischargeSummary', [
            'stay' => [
                'id' => $record->id,
                'patient' => trim($record->patient->first_name.' '.$record->patient->last_name),
                'status' => $record->status,
                'admission_type' => $record->admission_type,
                'admission_reason' => $record->admission_reason,
                'admitting_clinician' => trim($record->admittingClinician->first_name.' '.$record->admittingClinician->last_name),
                'bed' => $record->currentBed?->label,
                'ward' => $record->currentWard?->name,
                'admitted_at' => $record->admitted_at->toIso8601String(),
                'discharged_at' => $record->discharged_at?->toIso8601String(),
                'discharge_disposition' => $record->discharge_disposition,
                // Derived elapsed time — a FACT, never a grade (fence). Null while still admitted.
                'los_minutes' => $record->lengthOfStayMinutes(),
            ],
            'summary' => $summary === null ? null : [
                'id' => $summary->id,
                'status' => $summary->status,
                'summary' => $summary->summary,
                'instructions' => $summary->instructions,
                'is_finalized' => $summary->isFinalized(),
                'finalized_at' => $summary->finalized_at?->toIso8601String(),
            ],
            // The closed episode composes the stay's EXISTING records read-only (no recomputation).
            'episode' => [
                'journey' => $events->map(fn (StayEvent $e): array => [
                    'id' => $e->id,
                    'event_type' => $e->event_type,
                    'reason' => $e->reason,
                    'disposition' => $e->disposition,
                    'occurred_at' => $e->occurred_at->toIso8601String(),
                ])->all(),
                'rounds' => $charts->roundsForStay($record)->map(fn (WardRound $r): array => [
                    'id' => $r->id,
                    'at' => $r->created_at?->toIso8601String(),
                ])->all(),
                'handovers' => $handovers->history($record)->map(fn (Handover $h): array => [
                    'id' => $h->id,
                    'shift' => $h->shift,
                    'situation' => $h->situation,
                    'handed_over_at' => $h->handed_over_at->toIso8601String(),
                ])->all(),
                'invoices' => $billing->invoicesForStay($record)->map(fn (Invoice $i): array => [
                    'id' => $i->id,
                    'series' => $i->series,
                    'number' => $i->number,
                    'status' => $i->status,
                    'total_minor' => (int) $i->total_minor,
                    'issue_date' => $i->issue_date?->toDateString(),
                ])->all(),
            ],
            'actions' => [
                // Author while a draft (or nothing yet) exists; the finalize locks it.
                'can_author' => Gate::allows('note.write') && ! ($summary !== null && $summary->isFinalized()),
                'can_finalize' => Gate::allows('note.sign') && $summary !== null && ! $summary->isFinalized(),
                'save_url' => route('hospital.admissions.discharge-summary.save', $record->id),
                'finalize_url' => route('hospital.admissions.discharge-summary.finalize', $record->id),
            ],
        ]);
    }

    public function save(Request $request, string $stay, DischargeSummaryService $summaries): RedirectResponse
    {
        Gate::authorize('note.write');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'summary' => ['required', 'string', 'max:20000'],
            'instructions' => ['nullable', 'string', 'max:20000'],
        ]);

        $record = Stay::query()->whereKey($stay)->firstOrFail();

        try {
            $summaries->saveDraft($actor, $record, $data['summary'], $data['instructions'] ?? null);
        } catch (AdmissionException|CrossTenantReferenceException $e) {
            return back()->withErrors(['summary' => $e->getMessage()]);
        }

        return redirect()->route('hospital.admissions.discharge-summary', $record->id)->with('status', 'summary-saved');
    }

    public function finalize(Request $request, string $stay, DischargeSummaryService $summaries): RedirectResponse
    {
        Gate::authorize('note.sign');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = Stay::query()->whereKey($stay)->firstOrFail();

        try {
            $summaries->finalize($actor, $record);
        } catch (AdmissionException|CrossTenantReferenceException $e) {
            return back()->withErrors(['summary' => $e->getMessage()]);
        }

        return redirect()->route('hospital.admissions.discharge-summary', $record->id)->with('status', 'summary-finalized');
    }
}
