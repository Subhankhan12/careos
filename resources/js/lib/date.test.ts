import { describe, expect, it } from 'vitest';
import { ageFromDateOnly, formatDateOnly } from '@/lib/date';

const iso: Intl.DateTimeFormatOptions = { year: 'numeric', month: '2-digit', day: '2-digit' };

describe('formatDateOnly', () => {
    it('runs in a behind-UTC timezone where the naive parse shifts a day', () => {
        // vitest.config.ts pins TZ to America/Los_Angeles so this regression is real.
        expect(process.env.TZ).toBe('America/Los_Angeles');
        // The buggy pattern (`new Date("1954-03-12")` = UTC midnight) renders the 11th here.
        expect(new Intl.DateTimeFormat('en-CA', iso).format(new Date('1954-03-12'))).toBe('1954-03-11');
    });

    it('preserves the stored calendar day for a date-only value (M-2 regression)', () => {
        // Same input, behind-UTC zone — the helper keeps the 12th, not the 11th.
        expect(formatDateOnly('1954-03-12', 'en-CA', iso)).toBe('1954-03-12');
        expect(formatDateOnly('2026-07-19', 'en-CA', iso)).toBe('2026-07-19');
    });

    it('parses a date-only as local midnight (day preserved in any timezone)', () => {
        const d = new Date('2026-07-19T00:00:00');
        expect([d.getFullYear(), d.getMonth() + 1, d.getDate()]).toEqual([2026, 7, 19]);
    });

    it('returns the fallback for empty input and the raw value when unparseable', () => {
        expect(formatDateOnly(null)).toBe('—');
        expect(formatDateOnly(undefined)).toBe('—');
        expect(formatDateOnly('', 'en', iso, 'n/a')).toBe('n/a');
        expect(formatDateOnly('not-a-date', 'en', iso)).toBe('not-a-date');
    });

    it('passes a full datetime through as-is (does not treat it as date-only)', () => {
        expect(formatDateOnly('2026-07-19T12:00:00', 'en-CA', iso)).toBe('2026-07-19');
    });
});

describe('ageFromDateOnly', () => {
    it('computes an integer age from a date-only DOB', () => {
        const age = ageFromDateOnly('1990-06-15');
        expect(age).toBeTypeOf('number');
        expect(Number.isInteger(age)).toBe(true);
        expect(age as number).toBeGreaterThan(0);
    });

    it('returns null for empty or unparseable input', () => {
        expect(ageFromDateOnly(null)).toBeNull();
        expect(ageFromDateOnly(undefined)).toBeNull();
        expect(ageFromDateOnly('not-a-date')).toBeNull();
    });
});
