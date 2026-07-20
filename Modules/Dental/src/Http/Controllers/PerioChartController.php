<?php

namespace Modules\Dental\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\PerioExam;
use Modules\Dental\Models\PerioMeasurement;
use Modules\Dental\Services\PerioChartService;
use Modules\Dental\Support\ToothNotation;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

/**
 * The perio charting UI (DENTAL.G6) — PRESENTATIONAL over the PerioChartService (P0D.GU). It
 * surfaces a patient's periodontal exams as RAW per-site numbers and dispatches the append-only
 * record action; it computes nothing, stages nothing, grades nothing, flags nothing.
 *
 * RECORD-NOT-JUDGE (electric fence carried into the UI): the payload carries only raw
 * measurements — pocket depth (mm), recession (mm), bleeding-on-probing (true/false), and
 * optional mobility / furcation on their raw scales. There is NO stage / grade / severity /
 * risk / classification / flag anywhere. A per-site trend is raw numbers in sequence — the
 * dentist reads them and interprets; the view never says "worsening".
 *
 * All logic (append-only recording, deterministic validation, tenant scoping, audit,
 * patient-scoped read-logging) lives in PerioChartService. String-id `{patient}` (FIX.1 / D-090).
 * show = `patient.view`, store = `dental.chart`.
 */
class PerioChartController
{
    public function show(Request $request, string $patient, PerioChartService $perio): Response
    {
        Gate::authorize('patient.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = Patient::query()->whereKey($patient)->firstOrFail();

        // The service owns the read (patient-scoped read-log) and the ordering.
        $exams = $perio->examsFor($actor, $record);

        return Inertia::render('Dental/PerioChart', [
            'patient' => [
                'id' => $record->id,
                'mrn' => $record->mrn,
                'name' => trim($record->first_name.' '.$record->last_name),
            ],
            'exams' => $exams->map(fn (PerioExam $exam): array => $this->presentExam($exam))->values()->all(),
            // The tooth universe + the 6 probing sites come from the domain, so NO tooth/site
            // logic lives in the component (P0D.GU).
            'teeth' => [
                'permanent' => ToothNotation::permanent(),
                'primary' => ToothNotation::primary(),
            ],
            'sites' => PerioMeasurement::SITES,
            'actions' => [
                'can_chart' => Gate::allows('dental.chart'),
                'store_url' => route('dental.perio.store', $record->id),
            ],
        ]);
    }

    public function store(Request $request, string $patient, PerioChartService $perio): RedirectResponse
    {
        Gate::authorize('dental.chart');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'exam_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'measurements' => ['required', 'array', 'min:1'],
            'measurements.*.tooth' => ['required', 'string', 'max:2'],
            'measurements.*.site' => ['required', 'string', 'max:20'],
            'measurements.*.pocket_depth_mm' => ['nullable', 'integer'],
            'measurements.*.recession_mm' => ['nullable', 'integer'],
            'measurements.*.bleeding_on_probing' => ['nullable', 'boolean'],
            'measurements.*.mobility' => ['nullable', 'integer'],
            'measurements.*.furcation' => ['nullable', 'integer'],
        ]);

        $record = Patient::query()->whereKey($patient)->firstOrFail();

        try {
            // Append-only recording through the tested service — the ONLY write path. The
            // model validates FDI id / site / value ranges deterministically; an invalid value
            // throws (no interpretation) and the whole exam rolls back.
            $perio->recordExam($actor, $record, $data['exam_date'], $data['measurements'], $data['note'] ?? null);
        } catch (DentalException $e) {
            return back()->withErrors(['perio' => $e->getMessage()]);
        }

        return redirect()->route('dental.perio', $record->id)->with('status', 'charted');
    }

    /**
     * A perio exam as RAW facts only — no stage/grade/severity/risk/flag anywhere.
     *
     * @return array<string, mixed>
     */
    private function presentExam(PerioExam $exam): array
    {
        return [
            'id' => $exam->id,
            'exam_date' => $exam->exam_date->toDateString(),
            'examined_by' => $exam->examined_by,
            'note' => $exam->note,
            'measurements' => $exam->measurements->map(fn (PerioMeasurement $m): array => [
                'id' => $m->id,
                'tooth' => $m->tooth,
                'site' => $m->site,
                'pocket_depth_mm' => $m->pocket_depth_mm,
                'recession_mm' => $m->recession_mm,
                'bleeding_on_probing' => $m->bleeding_on_probing,
                'mobility' => $m->mobility,
                'furcation' => $m->furcation,
            ])->values()->all(),
        ];
    }
}
