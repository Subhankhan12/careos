<?php

namespace Modules\Clinical\Contracts;

/**
 * The seam that lets Clinical read Nursing-captured visit vitals WITHOUT depending
 * on the Nursing module (the module boundary forbids Clinical -> Nursing). The
 * implementation lives in the application layer, which may depend on both modules.
 *
 * It returns RAW readings only (recorded_at + the D.3 metric set); merging,
 * ordering, and per-metric grouping happen in VitalsSeries. No interpretation.
 */
interface VisitVitalsReader
{
    /**
     * Raw visit-vitals readings for a patient, tenant-scoped by the implementation.
     *
     * @return list<array{recorded_at: string, systolic: int|null, diastolic: int|null, heart_rate: int|null, temperature_c: string|null, spo2: int|null, weight_g: int|null, height_mm: int|null}>
     */
    public function readingsForPatient(string $patientId): array;
}
