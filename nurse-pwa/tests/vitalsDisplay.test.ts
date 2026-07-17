import { describe, expect, test } from 'vitest';
import { buildVitalsHistoryRows } from '../src/vitalsDisplay';
import type { VitalsHistory } from '../src/types';

function history(): VitalsHistory {
    return {
        systolic: [
            { recorded_at: '2026-08-02 15:00:00', value: 131, source: 'visit' },
            { recorded_at: '2026-08-01 09:00:00', value: 118, source: 'clinic' },
        ],
        diastolic: [{ recorded_at: '2026-08-01 09:00:00', value: 76, source: 'clinic' }],
        heart_rate: [],
        temperature_c: [],
        spo2: [{ recorded_at: '2026-08-02 15:00:00', value: 96, source: 'visit' }],
        weight_g: [],
        height_mm: [],
    };
}

describe('nurse PWA recent vitals history display', () => {
    test('renders one row per recorded metric with raw value + timestamp + source', () => {
        const rows = buildVitalsHistoryRows(history());

        // Only metrics with readings are shown; empty metrics are dropped.
        expect(rows.map((row) => row.key)).toEqual(['systolic', 'diastolic', 'spo2']);

        const systolic = rows[0];
        expect(systolic.points[0]).toEqual({ recorded_at: '2026-08-02 15:00:00', value: 131, source: 'visit' });
        expect(systolic.points[1].value).toBe(118);
        expect(systolic.points[1].source).toBe('clinic');
    });

    test('carries raw values ONLY — no band/flag/range/normal/abnormal/score/delta fields', () => {
        const rows = buildVitalsHistoryRows(history());
        const forbidden = ['flag', 'band', 'range', 'normal', 'abnormal', 'score', 'delta', 'trend', 'min', 'max', 'status', 'severity'];

        for (const row of rows) {
            // The row exposes only the metric key and its raw points.
            expect(Object.keys(row).sort()).toEqual(['key', 'points']);
            for (const point of row.points) {
                expect(Object.keys(point).sort()).toEqual(['recorded_at', 'source', 'value']);
                for (const key of forbidden) {
                    expect(point).not.toHaveProperty(key);
                }
            }
        }
    });

    test('handles an absent history without throwing', () => {
        expect(buildVitalsHistoryRows(undefined)).toEqual([]);
    });
});
