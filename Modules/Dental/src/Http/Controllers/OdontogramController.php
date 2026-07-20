<?php

namespace Modules\Dental\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\ToothRecord;
use Modules\Dental\Services\ToothChartService;
use Modules\Dental\Support\ToothNotation;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

/**
 * The odontogram chart UI (DENTAL.G2) — PRESENTATIONAL over the G1 ToothChartService
 * (P0D.GU). It surfaces the patient's CHARTED tooth conditions + history and dispatches
 * the charting action; it computes nothing, grades nothing, flags nothing.
 *
 * RENDER-NOT-JUDGE (electric fence carried into the UI): the payload carries only charted
 * FACTS (`condition` = the value the dentist selected) — never severity / score / grade /
 * risk / priority / flag. The view's colours are a factual charted-condition legend, not
 * a severity heatmap.
 *
 * All logic (append-only charting, deterministic validation, tenant scoping, audit,
 * patient-scoped read-logging) lives in ToothChartService — this controller only calls it.
 * String-id `{patient}` param (FIX.1 / D-090). show = `patient.view`, store = `dental.chart`.
 */
class OdontogramController
{
    public function show(Request $request, string $patient, ToothChartService $charts): Response
    {
        Gate::authorize('patient.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = Patient::query()->whereKey($patient)->firstOrFail();

        // The service owns "current = latest per (tooth, surface)" and the history trail;
        // both patient-scoped read-log inside the service.
        $chart = $charts->currentChart($actor, $record);
        $history = $charts->history($actor, $record);

        return Inertia::render('Dental/Odontogram', [
            'patient' => [
                'id' => $record->id,
                'mrn' => $record->mrn,
                'name' => trim($record->first_name.' '.$record->last_name),
                'date_of_birth' => $record->date_of_birth->toDateString(),
                'sex' => $record->sex,
            ],
            'chart' => $chart->map(fn (ToothRecord $r): array => $this->present($r))->values()->all(),
            'history' => $history->map(fn (ToothRecord $r): array => $this->present($r, withReason: true))->values()->all(),
            // The tooth UNIVERSE + surfaces + condition vocabulary all come from the domain,
            // so NO tooth/surface/condition logic lives in the component (P0D.GU).
            'teeth' => [
                'permanent' => ToothNotation::permanent(),
                'primary' => ToothNotation::primary(),
            ],
            'surfaces' => ToothNotation::SURFACES,
            'conditions' => [
                'wholeTooth' => ToothRecord::WHOLE_TOOTH_CONDITIONS,
                'surface' => ToothRecord::SURFACE_CONDITIONS,
            ],
            'actions' => [
                'can_chart' => Gate::allows('dental.chart'),
                'store_url' => route('dental.chart.store', $record->id),
            ],
        ]);
    }

    public function store(Request $request, string $patient, ToothChartService $charts): RedirectResponse
    {
        Gate::authorize('dental.chart');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'tooth' => ['required', 'string', 'max:2'],
            'surface' => ['nullable', 'string', 'max:20'],
            'condition' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = Patient::query()->whereKey($patient)->firstOrFail();

        // Empty surface ("" from a whole-tooth selection) means a whole-tooth record.
        $surface = ($data['surface'] ?? '') === '' ? null : $data['surface'];

        try {
            // Append-only charting through the tested service — the ONLY write path. The
            // service/model validate the FDI id, surface, and condition vocabulary; an
            // invalid value throws (deterministic, no interpretation) and surfaces as an error.
            $charts->chart($actor, $record, $data['tooth'], $surface, $data['condition'], $data['note'] ?? null, $data['reason'] ?? null);
        } catch (DentalException $e) {
            return back()->withErrors(['condition' => $e->getMessage()]);
        }

        return redirect()->route('dental.chart', $record->id)->with('status', 'charted');
    }

    /**
     * A charted record as FACTS only — no severity/score/grade/risk/flag anywhere.
     *
     * @return array<string, mixed>
     */
    private function present(ToothRecord $record, bool $withReason = false): array
    {
        $data = [
            'id' => $record->id,
            'tooth' => $record->tooth,
            'surface' => $record->surface,
            'condition' => $record->charted_condition,
            'note' => $record->note,
            'charted_at' => $record->charted_at->toIso8601String(),
            'charted_by' => $record->charted_by,
        ];

        if ($withReason) {
            $data['reason'] = $record->reason;
        }

        return $data;
    }
}
