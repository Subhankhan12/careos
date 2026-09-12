/** A datetime carrying NO zone marker — Carbon's `toDateTimeString()` shape. */
const NAIVE_DATETIME = /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/;

/**
 * Label a naive datetime as UTC before parsing it. The day pack sends `toIso8601String()` today, which
 * carries an offset — but a naive string handed to `new Date()` is parsed as the DEVICE's local time,
 * which on the staff side produced a NINE-HOUR error before a browser caught it. Storage is UTC from
 * every path (D-192), so a naive CareOS value is UTC; anything already carrying a zone is untouched.
 */
function normaliseInstant(value: string): string {
    return NAIVE_DATETIME.test(value) ? `${value.replace(' ', 'T')}Z` : value;
}

/**
 * `P4-H4` (QA-FIX.12c, D-228) — render a visit time in the PRACTICE's zone, never the device's.
 *
 * WHY THE PWA NEEDS ITS OWN. The staff app resolves the tenant zone from the `timezone` Inertia prop
 * (D-192); this is a separate application that receives no Inertia props, so the zone travels inside
 * the day pack instead and is read from there. Every time in the pack is a UTC instant, and the screen
 * used to print the raw ISO — the audit measured 32 timestamps on this screen, all 32 raw UTC, so a
 * nurse read `05:30` for a visit that happens at 07:30 in Zurich.
 *
 * THE DEVICE CLOCK IS DELIBERATELY NOT THE FALLBACK. A nurse may cross a border, or carry a phone set
 * to the wrong zone; the round is planned in the practice's clock and must read in it. When the pack
 * carries no zone — an older cached pack from before this field existed — the raw value is returned
 * rather than a confident local rendering of an unknown zone (D-176).
 */
export function formatVisitTime(
    value: string | null | undefined,
    timeZone: string | null | undefined,
    locale = 'de-CH',
    options: Intl.DateTimeFormatOptions = { hour: '2-digit', minute: '2-digit', hour12: false },
): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    if (timeZone === null || timeZone === undefined || timeZone === '') {
        return value;
    }

    const date = new Date(normaliseInstant(value));
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    try {
        return new Intl.DateTimeFormat(locale, { ...options, timeZone }).format(date);
    } catch {
        return value;
    }
}

/** The visit window as one string — the form the round list and the detail header both show. */
export function formatVisitWindow(
    start: string | null | undefined,
    end: string | null | undefined,
    timeZone: string | null | undefined,
    locale = 'de-CH',
): string {
    const from = formatVisitTime(start, timeZone, locale);
    const to = formatVisitTime(end, timeZone, locale);

    if (from === '' && to === '') {
        return '';
    }

    return `${from} - ${to}`;
}
