<?php

namespace Modules\Clinical\Support;

/**
 * Pure builder that merges raw vitals readings from more than one store into a
 * per-metric, time-ordered series. It performs NO interpretation whatsoever:
 * no ranges, bands, flags, normal/abnormal, scores, arrows, or computed deltas.
 * It only groups the raw numbers by metric and orders them by time. The clinician
 * or nurse draws the conclusion — the electric fence (D-D3) is absolute here.
 *
 * It intentionally imports no models, so it is shared safely across modules
 * (Clinical owns it; Nursing may use it).
 */
class VitalsSeries
{
    /**
     * The shared D.3 raw metric set. Order matters only for stable presentation.
     *
     * @var list<string>
     */
    public const METRICS = [
        'systolic',
        'diastolic',
        'heart_rate',
        'temperature_c',
        'spo2',
        'weight_g',
        'height_mm',
    ];

    public const SOURCE_CLINIC = 'clinic';

    public const SOURCE_VISIT = 'visit';

    /**
     * Merge a flat list of readings into a per-metric series.
     *
     * Each reading is an associative array with `recorded_at` (a sortable
     * `Y-m-d H:i:s` string), `source`, and any subset of the METRIC keys. A metric
     * that is null/absent in a reading is simply omitted from that metric's series —
     * NEVER zero-filled.
     *
     * The result is keyed by every metric (stable contract; empty list when a
     * metric was never recorded). Each entry is `{recorded_at, value, source}`.
     * Ordered most-recent-first by default so "recent history" reads naturally.
     *
     * @param  list<array<string, mixed>>  $readings
     * @param  'desc'|'asc'  $order
     * @param  int|null  $perMetricLimit  cap the number of points per metric (recent-first)
     * @return array<string, list<array{recorded_at: string, value: mixed, source: string}>>
     */
    public static function build(array $readings, string $order = 'desc', ?int $perMetricLimit = null): array
    {
        usort($readings, static function (array $a, array $b) use ($order): int {
            $left = (string) ($a['recorded_at'] ?? '');
            $right = (string) ($b['recorded_at'] ?? '');

            return $order === 'asc' ? strcmp($left, $right) : strcmp($right, $left);
        });

        /** @var array<string, list<array{recorded_at: string, value: mixed, source: string}>> $series */
        $series = array_fill_keys(self::METRICS, []);

        foreach ($readings as $reading) {
            $recordedAt = (string) ($reading['recorded_at'] ?? '');
            $source = (string) ($reading['source'] ?? '');

            foreach (self::METRICS as $metric) {
                $value = $reading[$metric] ?? null;

                // A missing metric is absent from that metric's series, never zero.
                if ($value === null) {
                    continue;
                }

                if ($perMetricLimit !== null && count($series[$metric]) >= $perMetricLimit) {
                    continue;
                }

                $series[$metric][] = [
                    'recorded_at' => $recordedAt,
                    'value' => $value,
                    'source' => $source,
                ];
            }
        }

        return $series;
    }
}
