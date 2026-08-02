<?php

namespace Modules\Lab\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderResult;
use Modules\Lab\Exceptions\LabResultException;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\LabResult;
use Modules\Lab\Models\LabTest;
use Modules\Lab\Models\Specimen;
use Modules\Lab\Services\LabResultService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * Manual lab result entry (LAB.G4) — PRESENTATIONAL over {@see LabResultService}. THE FENCE GATE. From a lab
 * order's specimens, a lab tech enters a result (a raw value) REUSING the Clinical `OrderResult`; the append-only
 * result history is shown WITH the reference range DISPLAYED beside each raw value. String-id (FIX.1). Viewing is
 * `patient.view` (read-logged); entering a result is `lab.result`.
 *
 * ELECTRIC FENCE (the sharpest in lab): the reference range (`unit` + `reference_range`) is RECORDED REFERENCE
 * DATA read from the LAB.G1 {@see LabTest} catalog and shown beside the raw value — the screen records the value
 * and DISPLAYS the range; it computes NO abnormal/high/low/critical flag, does NOT colour-by-abnormal, does NOT
 * delta-check, does NOT interpret. The payload carries the raw value + the displayed range and NO
 * computed-judgment field. The clinician reads value-vs-range.
 */
class LabResultController
{
    public function show(Request $request, string $labOrder): Response
    {
        Gate::authorize('patient.view');
        abort_unless($request->user() instanceof User, 403);

        $record = LabOrder::query()->with(['order.orderableItem', 'order.results'])->whereKey($labOrder)->firstOrFail();
        $order = $record->order;
        $orderResults = $order !== null ? $order->results : collect();
        $patient = Patient::query()->findOrFail($record->patient_id);
        $patient->auditRead(); // patient-scoped read log

        // The reference range is RECORDED REFERENCE DATA from the LAB.G1 catalog — DISPLAYED beside the value,
        // never a computed threshold. Keyed 1:1 to the order's orderable.
        $labTest = $order !== null
            ? LabTest::query()->where('orderable_item_id', $order->orderable_item_id)->first()
            : null;

        // Which specimen produced each result (the LAB.G4 overlay link) — a fact shown for traceability.
        $accessionByResult = LabResult::query()
            ->whereIn('order_result_id', $orderResults->pluck('id'))
            ->with('specimen')
            ->get()
            ->mapWithKeys(fn (LabResult $l): array => [$l->order_result_id => $l->specimen?->accession_number])
            ->all();

        return Inertia::render('Lab/Results', [
            'labOrder' => [
                'id' => $record->id,
                'patient' => trim($patient->first_name.' '.$patient->last_name),
                'test' => $order?->orderableItem?->name,
                'code' => $order?->orderableItem?->code,
                'priority' => $record->priority,        // the LAB.G2 recorded flag (a fact)
                'order_status' => $order?->status,       // the reused Clinical Order lifecycle state
            ],
            // The DISPLAYED reference data — unit + range. Presentational; NO computed threshold/flag.
            'reference' => [
                'unit' => $labTest?->unit,
                'reference_range' => $labTest?->reference_range,
            ],
            // Specimens for this order — a result is entered against one (only non-terminal ones can be resulted).
            'specimens' => Specimen::query()
                ->where('lab_order_id', $record->id)
                ->orderByDesc('collected_at')
                ->get()
                ->map(fn (Specimen $s): array => [
                    'id' => $s->id,
                    'accession_number' => $s->accession_number,
                    'status' => $s->status,
                    'can_result' => ! in_array($s->status, [Specimen::STATUS_RESULTED, Specimen::STATUS_REJECTED], true),
                    'result_url' => route('lab.results.store', $s->id),
                ])->all(),
            // The append-only result history — the RAW value + when + source. The range is shown beside (above),
            // NOT baked into a computed verdict. NO abnormal/flag/critical/high/low/delta/interpretation field.
            'results' => $orderResults
                ->sortByDesc('entered_at')
                ->values()
                ->map(fn (OrderResult $r): array => [
                    'id' => $r->id,
                    'result_value' => $r->result_value, // the RAW recorded value (a fact)
                    'source' => $r->source,             // manual (the seam's manual path)
                    'entered_at' => $r->entered_at->toIso8601String(),
                    'accession_number' => $accessionByResult[$r->id] ?? null, // which specimen produced it
                ])->all(),
            'actions' => [
                'can_result' => Gate::allows('lab.result'),
            ],
        ]);
    }

    public function store(Request $request, string $specimen, LabResultService $results): RedirectResponse
    {
        Gate::authorize('lab.result');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'value' => ['nullable', 'string', 'max:500'],
            'document_id' => ['nullable', 'string'],
        ]);

        $record = Specimen::query()->whereKey($specimen)->firstOrFail();

        try {
            $results->record($actor, $record, [
                'value' => $data['value'] ?? null,
                'document_id' => $data['document_id'] ?? null,
            ]);
        } catch (LabResultException|CrossTenantReferenceException|InvalidArgumentException $e) {
            return back()->withErrors(['lab_result' => $e->getMessage()]);
        }

        return redirect()->route('lab.results.show', $record->lab_order_id)->with('status', 'lab-result-recorded');
    }
}
