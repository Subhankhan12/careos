import type { VitalsHistory, VitalsPoint } from './types';

/**
 * The raw D.3 metric set, in display order. Units are labels only — there is NO
 * conversion, and there are NO reference ranges anywhere in this module.
 */
export const VITALS_METRICS = [
    'systolic',
    'diastolic',
    'heart_rate',
    'temperature_c',
    'spo2',
    'weight_g',
    'height_mm',
] as const;

export type VitalsMetricKey = (typeof VITALS_METRICS)[number];

export interface VitalsMetricRow {
    key: VitalsMetricKey;
    points: VitalsPoint[];
}

/**
 * Shape the unified vitals history for rendering: one row per metric that has at
 * least one reading, each row carrying the raw points (value + timestamp + source).
 *
 * This deliberately returns ONLY raw values over time. It never computes or attaches
 * a band, range, flag, normal/abnormal marker, score, arrow, or delta — the electric
 * fence is absolute. The nurse reads the numbers and draws the conclusion.
 */
export function buildVitalsHistoryRows(history: VitalsHistory | undefined): VitalsMetricRow[] {
    if (!history) {
        return [];
    }

    return VITALS_METRICS
        .map((key) => ({ key, points: history[key] ?? [] }))
        .filter((row) => row.points.length > 0);
}
