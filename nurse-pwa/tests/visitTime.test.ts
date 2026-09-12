import { describe, expect, test } from 'vitest';
import { formatVisitTime, formatVisitWindow } from '../src/visitTime';

/*
| `P4-H4` (QA-FIX.12c, D-228) — the round reads the PRACTICE's clock.
|
| The audit measured 32 timestamps on this screen and all 32 were raw UTC: a nurse read `05:30` for a
| visit that happens at 07:30 in Zurich. These pin the practice's clock as a VALUE, and — just as
| importantly — pin that the DEVICE clock is never the fallback.
*/

describe('formatVisitTime', () => {
    test('renders the practice clock, not UTC', () => {
        // 05:30Z is 07:30 in Zurich (CEST) — the audit's own example, inverted into an assertion.
        expect(formatVisitTime('2026-09-06T05:30:00+00:00', 'Europe/Zurich')).toBe('07:30');
    });

    test('honours DST from the zone database rather than a fixed offset', () => {
        // January: Zurich is CET (UTC+1), so 05:30Z is 06:30, not 07:30.
        expect(formatVisitTime('2026-01-06T05:30:00+00:00', 'Europe/Zurich')).toBe('06:30');
    });

    test('follows the zone it is GIVEN, so another practice reads its own clock', () => {
        expect(formatVisitTime('2026-09-06T05:30:00+00:00', 'UTC')).toBe('05:30');
        expect(formatVisitTime('2026-09-06T05:30:00+00:00', 'Asia/Tokyo')).toBe('14:30');
    });

    test('does NOT fall back to the device clock when the pack carries no zone', () => {
        /*
         * THE PROPERTY THAT MATTERS MOST HERE. A nurse may cross a border, or carry a phone set to the
         * wrong zone, and the round is planned in the practice's clock. An older cached pack has no
         * `timezone`, and the honest answer is the raw value — visibly unformatted — rather than a
         * confident rendering in a zone nobody chose (D-176).
         */
        expect(formatVisitTime('2026-09-06T05:30:00+00:00', null)).toBe('2026-09-06T05:30:00+00:00');
        expect(formatVisitTime('2026-09-06T05:30:00+00:00', undefined)).toBe('2026-09-06T05:30:00+00:00');
        expect(formatVisitTime('2026-09-06T05:30:00+00:00', '')).toBe('2026-09-06T05:30:00+00:00');
    });

    test('returns the raw value for an unknown zone rather than the device clock', () => {
        expect(formatVisitTime('2026-09-06T05:30:00+00:00', 'Mars/Olympus')).toBe('2026-09-06T05:30:00+00:00');
    });

    test('returns empty for empty input and the raw value for anything unparseable', () => {
        expect(formatVisitTime(null, 'Europe/Zurich')).toBe('');
        expect(formatVisitTime('', 'Europe/Zurich')).toBe('');
        expect(formatVisitTime('not-a-time', 'Europe/Zurich')).toBe('not-a-time');
    });
});

describe('formatVisitWindow', () => {
    test('renders both ends in the practice clock', () => {
        expect(
            formatVisitWindow('2026-09-06T05:30:00+00:00', '2026-09-06T06:30:00+00:00', 'Europe/Zurich'),
        ).toBe('07:30 - 08:30');
    });

    test('with no zone it shows the raw values rather than a plausible wrong window', () => {
        expect(formatVisitWindow('2026-09-06T05:30:00+00:00', '2026-09-06T06:30:00+00:00', null)).toBe(
            '2026-09-06T05:30:00+00:00 - 2026-09-06T06:30:00+00:00',
        );
    });

    test('returns empty when there is no window at all', () => {
        expect(formatVisitWindow(null, null, 'Europe/Zurich')).toBe('');
    });
});

describe('payload shapes', () => {
    test('a NAIVE datetime is treated as UTC, not as the device clock', () => {
        // The day pack sends an offset today, but this is the trap that cost nine hours on the staff
        // side: `new Date('2026-09-06 05:30:00')` is the DEVICE's local time, not UTC.
        expect(formatVisitTime('2026-09-06 05:30:00', 'Europe/Zurich')).toBe('07:30');
    });

    test('an explicit offset is respected rather than re-labelled', () => {
        expect(formatVisitTime('2026-09-06T07:30:00+02:00', 'Europe/Zurich')).toBe('07:30');
    });
});
