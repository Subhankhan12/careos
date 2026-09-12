// Shared date-only formatting. A DATE-ONLY value ("YYYY-MM-DD" — DOB, invoice/issue
// dates, an "as of" date) must never shift a calendar day when the viewer's timezone
// is behind UTC. `new Date("1954-03-12")` parses as UTC midnight and re-renders in the
// local zone (03/11 in America/Los_Angeles), so date-only values are parsed as LOCAL
// midnight ("…T00:00:00") — the parse and the Intl format then share the same zone and
// the day is preserved everywhere. Timestamped (datetime) values are NOT handled here;
// keep using `new Date(datetime)` for those. (QA-audit M-2; same class as the W6 isOverdue fix.)

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

function toLocalDate(value: string): Date {
    return new Date(DATE_ONLY.test(value) ? `${value}T00:00:00` : value);
}

/**
 * Format a date-only string in the viewer's locale without a timezone day-shift.
 * Returns `fallback` for empty input and the raw value for anything unparseable.
 */
export function formatDateOnly(
    value: string | null | undefined,
    locale = 'en',
    options: Intl.DateTimeFormatOptions = { day: '2-digit', month: '2-digit', year: 'numeric' },
    fallback = '—',
): string {
    if (value === null || value === undefined || value === '') {
        return fallback;
    }
    const date = toLocalDate(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }
    try {
        return new Intl.DateTimeFormat(locale, options).format(date);
    } catch {
        return value;
    }
}

/** A datetime carrying NO zone marker: `2026-08-03 09:20:00` or `2026-08-03T09:20`. */
const NAIVE_DATETIME = /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/;

/**
 * Make an instant explicit before parsing it.
 *
 * THIS EXISTS BECAUSE THE FIRST VERSION OF `formatDateTime` WAS WRONG BY NINE HOURS, and a browser
 * caught it where the unit tests could not. CareOS payloads serialise with Carbon's
 * `toDateTimeString()`, which emits `2026-08-03 09:20:00` — **no `Z`, no offset**. `new Date()` parses
 * such a string as the VIEWER's LOCAL time, so on a machine at UTC-7 it became `16:20Z` and then
 * rendered as `18:20` in Zurich: the viewer's zone was being applied twice, once silently on the way
 * in. Every test had used a `Z`-suffixed input and so proved nothing about the payloads the app
 * actually sends.
 *
 * Storage is UTC from every path (D-192), so a naive string from a CareOS payload IS UTC and is
 * labelled as such here. A value that already carries `Z` or an offset is left exactly as it is.
 */
function normaliseInstant(value: string): string {
    return NAIVE_DATETIME.test(value) ? `${value.replace(' ', 'T')}Z` : value;
}

/**
 * Format an INSTANT in an explicit IANA zone — the practice's, not the viewer's and not UTC
 * (`P2-H3`, `P4-H4`, QA-FIX.12c, D-192/D-228).
 *
 * WHY THIS EXISTS. Storage has been true UTC since D-192, and the same decision resolved the display
 * side by sharing the tenant's zone as the `timezone` Inertia prop — *"no frontend component consumes
 * the shared `timezone` prop for rendering today"*, which was still true when this was written. So
 * clinical surfaces each invented their own clock, and the audit found THREE, none of them the
 * practice's: the raw UTC string printed verbatim, `new Date(iso).toLocaleString()` (the VIEWER's
 * machine zone, in US format), and `iso.replace('T',' ').slice(0,16)` (UTC, cosmetically tidied).
 *
 * Pass `page.props.timezone`. `Intl` does the conversion, so DST is the zone database's problem and
 * never arithmetic here.
 */
export function formatDateTime(
    value: string | null | undefined,
    timeZone: string,
    locale = 'en',
    options: Intl.DateTimeFormatOptions = {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    },
    fallback = '—',
): string {
    if (value === null || value === undefined || value === '') {
        return fallback;
    }

    /*
     * A DATE-ONLY value must never come through here. `new Date('2026-03-12')` is UTC midnight, and
     * converting that to Europe/Zurich yields "12.03.2026, 01:00" — a plausible-looking wrong answer,
     * which is the exact class D-091 exists to prevent. Refuse it visibly rather than shift it.
     */
    if (DATE_ONLY.test(value)) {
        return value;
    }

    const date = new Date(normaliseInstant(value));
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    try {
        return new Intl.DateTimeFormat(locale, { ...options, timeZone }).format(date);
    } catch {
        /*
         * An unknown zone makes `Intl` throw. Falling back to the viewer's clock would be the defect
         * this helper exists to remove — so the raw value is returned instead: wrong-looking rather
         * than wrong-and-convincing (D-176).
         */
        return value;
    }
}

/** Whole years from a date-of-birth (date-only), timezone-robust; null if unparseable. */
export function ageFromDateOnly(value: string | null | undefined): number | null {
    if (!value) {
        return null;
    }
    const dob = toLocalDate(value);
    if (Number.isNaN(dob.getTime())) {
        return null;
    }
    const now = new Date();
    let age = now.getFullYear() - dob.getFullYear();
    const monthDiff = now.getMonth() - dob.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && now.getDate() < dob.getDate())) {
        age -= 1;
    }
    return age;
}
