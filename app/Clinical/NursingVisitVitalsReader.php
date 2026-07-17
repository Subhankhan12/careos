<?php

namespace App\Clinical;

use Modules\Clinical\Contracts\VisitVitalsReader;
use Modules\Nursing\Models\VisitVital;

/**
 * App-layer implementation of the Clinical VisitVitalsReader seam. It reads the
 * Nursing `visit_vitals` store so a unified vitals history can include readings a
 * nurse captured in the home. This composition lives in app/ because it depends on
 * both Clinical (the contract) and Nursing (the model); the modules never depend on
 * each other.
 *
 * `VisitVital` is tenant-owned (BelongsToTenant), so the query is fail-closed to the
 * current tenant automatically. Raw values only — no interpretation is added here.
 */
class NursingVisitVitalsReader implements VisitVitalsReader
{
    public function readingsForPatient(string $patientId): array
    {
        return VisitVital::query()
            ->where('patient_id', $patientId)
            ->orderByDesc('recorded_at')
            ->get()
            ->map(fn (VisitVital $vital): array => [
                'recorded_at' => $vital->recorded_at->toDateTimeString(),
                'systolic' => $vital->systolic,
                'diastolic' => $vital->diastolic,
                'heart_rate' => $vital->heart_rate,
                'temperature_c' => $vital->temperature_c,
                'spo2' => $vital->spo2,
                'weight_g' => $vital->weight_g,
                'height_mm' => $vital->height_mm,
            ])
            ->all();
    }
}
