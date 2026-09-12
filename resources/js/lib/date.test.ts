import { describe, expect, it } from 'vitest';
import { ageFromDateOnly, formatDateOnly, formatDateTime } from '@/lib/date';

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

describe('formatDateTime', () => {
    /*
     * THE DEFECT, PINNED AS A VALUE (`P2-H3`, `P4-H4`). The audit measured one instant rendered three
     * ways, none of them the practice's clock. These assert the practice's clock explicitly, so a
     * regression to any of the three shows up as a wrong STRING rather than as a missing helper.
     */
    it('renders an instant in the practice zone, not UTC and not the viewer machine', () => {
        // 17:04:51 UTC is 19:04 in Zurich (CEST, UTC+2) on this date — the audit's own example.
        expect(formatDateTime('2026-09-05T17:04:51Z', 'Europe/Zurich', 'de-CH')).toContain('19:04');
        expect(formatDateTime('2026-09-05T17:04:51Z', 'Europe/Zurich', 'de-CH')).not.toContain('17:04');
    });

    it('honours DST from the zone database rather than a fixed offset', () => {
        // Same wall-clock UTC in January: Zurich is CET (UTC+1), so 17:04Z is 18:04, not 19:04.
        expect(formatDateTime('2026-01-05T17:04:51Z', 'Europe/Zurich', 'de-CH')).toContain('18:04');
    });

    it('follows the zone it is GIVEN, so a different tenant reads its own clock', () => {
        expect(formatDateTime('2026-09-05T17:04:51Z', 'UTC', 'en-GB')).toContain('17:04');
        expect(formatDateTime('2026-09-05T17:04:51Z', 'America/Los_Angeles', 'en-US')).toContain('10:04');
    });

    it('REFUSES a date-only value rather than shifting it a day', () => {
        // `new Date('2026-03-12')` is UTC midnight; in Zurich that is 01:00 on the 12th — and in a
        // zone behind UTC it would be the 11th. D-091 owns date-only values; this returns it raw.
        expect(formatDateTime('2026-03-12', 'Europe/Zurich')).toBe('2026-03-12');
    });

    it('returns the fallback for empty input and the raw value for anything unparseable', () => {
        expect(formatDateTime(null, 'Europe/Zurich')).toBe('—');
        expect(formatDateTime('', 'Europe/Zurich')).toBe('—');
        expect(formatDateTime('not-a-date', 'Europe/Zurich')).toBe('not-a-date');
    });

    it('returns the raw value for an unknown zone rather than silently using the viewer clock', () => {
        expect(formatDateTime('2026-09-05T17:04:51Z', 'Mars/Olympus')).toBe('2026-09-05T17:04:51Z');
    });
});

describe('formatDateTime with the payload shapes the app actually sends', () => {
    /*
     * THE NINE-HOUR BUG, PINNED. CareOS payloads serialise with Carbon's `toDateTimeString()`, which
     * emits no zone marker at all. The first version of this helper handed that straight to `new Date()`,
     * which parsed it as the VIEWER's local time — so a vital stored at 09:20 UTC rendered as `18:20` in
     * Zurich on a UTC-7 machine: the viewer's zone applied twice, once silently. Every test above uses a
     * `Z`-suffixed input and so could not see it; a browser could, and did.
     */
    it('treats a NAIVE datetime as UTC, because that is what CareOS stores (D-192)', () => {
        // The exact value the chart sends for Erika Baumgartner's first vital.
        expect(formatDateTime('2026-08-03 09:20:00', 'Europe/Zurich', 'de-CH')).toContain('11:20');
        expect(formatDateTime('2026-08-03 09:20:00', 'Europe/Zurich', 'de-CH')).not.toContain('18:20');
    });

    it('treats a naive value the same whichever separator it uses', () => {
        expect(formatDateTime('2026-08-03T09:20:00', 'Europe/Zurich', 'de-CH')).toContain('11:20');
        expect(formatDateTime('2026-08-03T09:20', 'Europe/Zurich', 'de-CH')).toContain('11:20');
    });

    it('leaves an EXPLICIT instant alone — a Z or an offset is respected, not re-labelled', () => {
        expect(formatDateTime('2026-08-03T09:20:00Z', 'Europe/Zurich', 'de-CH')).toContain('11:20');
        // 11:20+02:00 is 09:20Z, so Zurich reads it back as 11:20 — unchanged by normalisation.
        expect(formatDateTime('2026-08-03T11:20:00+02:00', 'Europe/Zurich', 'de-CH')).toContain('11:20');
        // An offset that is NOT the practice's must still be converted, not assumed.
        expect(formatDateTime('2026-08-03T09:20:00-07:00', 'Europe/Zurich', 'de-CH')).toContain('18:20');
    });
});
