<?php

namespace Modules\Clinical\Services;

use Modules\Clinical\Contracts\VisitVitalsReader;
use Modules\Clinical\Models\Vital;
use Modules\Clinical\Support\VitalsSeries;

/**
 * Builds a patient's UNIFIED vitals history by merging the two raw stores —
 * Clinical `vitals` (staff/encounter-captured) and Nursing `visit_vitals`
 * (PWA-captured, read through the VisitVitalsReader seam) — into one time-ordered,
 * per-metric series. Every reading is source-tagged (clinic|visit) so no reading is
 * invisible and none is silently attributed to the wrong origin.
 *
 * Both stores are tenant-owned, so this is fail-closed to the current tenant. The
 * output is raw values over time ONLY — VitalsSeries adds no interpretation.
 * Read-logging/RBAC live on the callers (the chart gate-authorizes patient.view and
 * patient-scoped read-logs; the day-pack read-logs each included patient).
 */
class VitalsHistoryService
{
    public function __construct(private readonly VisitVitalsReader $visitVitals) {}

    /**
     * Per-metric unified series for a patient, most-recent-first.
     *
     * @param  int|null  $perMetricLimit  cap points per metric (e.g. the PWA recent view); null = full history
     * @return array{metrics: array<string, list<array{recorded_at: string, value: mixed, source: string}>>}
     */
    public function forPatient(string $patientId, ?int $perMetricLimit = null): array
    {
        $clinic = Vital::query()
            ->where('patient_id', $patientId)
            ->orderByDesc('recorded_at')
            ->get()
            ->map(fn (Vital $vital): array => [
                'recorded_at' => $vital->recorded_at->toDateTimeString(),
                'source' => VitalsSeries::SOURCE_CLINIC,
                'systolic' => $vital->systolic,
                'diastolic' => $vital->diastolic,
                'heart_rate' => $vital->heart_rate,
                'temperature_c' => $vital->temperature_c,
                'spo2' => $vital->spo2,
                'weight_g' => $vital->weight_g,
                'height_mm' => $vital->height_mm,
            ])
            ->all();

        $visit = array_map(
            static fn (array $reading): array => [...$reading, 'source' => VitalsSeries::SOURCE_VISIT],
            $this->visitVitals->readingsForPatient($patientId),
        );

        return [
            'metrics' => VitalsSeries::build([...$clinic, ...$visit], 'desc', $perMetricLimit),
        ];
    }
}
