<?php

namespace Modules\Lab\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Clinical\Http\Controllers\OrdersReviewController;
use Modules\Clinical\Models\OrderResult;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\LabTest;
use Modules\Lab\Services\LabResultService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

/**
 * The lab "results to review" worklist (LAB.G5) — PRESENTATIONAL over {@see LabResultService::reviewWorklist}.
 * Closes the order → result → review loop: the ordering clinician's resulted lab orders, each with the raw
 * result value + the DISPLAYED reference range (LAB.G4), the recorded STAT flag, and the resulted-time. The
 * review action REUSES the EXISTING `OrderService` review flow (POST `clinical.orders.review` →
 * `markReviewed`: resulted → reviewed) — it is NOT reinvented. The analogue of the Clinical
 * {@see OrdersReviewController}, lab-scoped. Gated `order.manage` (the review
 * permission).
 *
 * ELECTRIC FENCE: the worklist shows FACTS — patient, test, the RAW result, the DISPLAYED range, the recorded
 * STAT flag, the resulted-time. The server orders by resulted-time (a fact); it computes NO priority/urgency
 * ranking, NO critical-result flag, NO "review this first" judgment. Staff MAY sort by the recorded STAT flag
 * or time client-side (a recorded fact — the ED-board precedent). The result stays raw value + displayed range
 * (G4's fence carried) — no computed abnormal/critical flag anywhere.
 */
class LabReviewController
{
    public function __invoke(Request $request, LabResultService $results): Response
    {
        Gate::authorize('order.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $rows = $results->reviewWorklist($actor)->map(function (LabOrder $labOrder): array {
            $order = $labOrder->order;
            $patient = Patient::query()->find($labOrder->patient_id);

            // The reference range is DISPLAYED reference data from the LAB.G1 catalog (G4) — a fact shown beside
            // the raw value, never a computed threshold. Keyed 1:1 to the order's orderable.
            $labTest = $order !== null
                ? LabTest::query()->where('orderable_item_id', $order->orderable_item_id)->first()
                : null;

            $orderResults = $order !== null ? $order->results : collect();
            $latestEnteredAt = $orderResults->max('entered_at');

            return [
                'order_id' => $order?->id, // for the reused review action (clinical.orders.review)
                'patient' => $patient !== null ? trim($patient->first_name.' '.$patient->last_name) : null,
                'patient_id' => $labOrder->patient_id,
                'test' => $order?->orderableItem?->name,
                'code' => $order?->orderableItem?->code,
                'priority' => $labOrder->priority,   // the LAB.G2 recorded STAT flag (a fact — sortable, not computed)
                // The DISPLAYED reference data (G4) — presentational; NO computed threshold/flag.
                'reference' => [
                    'unit' => $labTest?->unit,
                    'reference_range' => $labTest?->reference_range,
                ],
                // Raw result values — shown raw beside the range, NO abnormal/critical/flag (G4's fence carried).
                'results' => $orderResults
                    ->sortByDesc('entered_at')
                    ->values()
                    ->map(fn (OrderResult $r): array => [
                        'value' => $r->result_value,
                        'entered_at' => $r->entered_at->toIso8601String(),
                    ])->all(),
                'resulted_at' => $latestEnteredAt !== null ? $latestEnteredAt->toIso8601String() : null, // a fact
                'results_url' => route('lab.results.show', $labOrder->id),
            ];
        })->all();

        return Inertia::render('Lab/Review', [
            'orders' => $rows,
            'options' => ['priorities' => LabOrder::PRIORITIES],
            'reviewUrl' => route('clinical.orders.review'), // REUSE the existing resulted → reviewed endpoint
        ]);
    }
}
