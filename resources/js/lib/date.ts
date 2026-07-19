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
