/**
 * Swiss money formatting — DISPLAY ONLY.
 *
 * The billing engine is the single source of truth for money (integer minor units); these
 * helpers only FORMAT an already-computed minor figure for display. They perform no money
 * math — no summing, no ratios — just split an integer into major/cents and group the major
 * part with the Swiss apostrophe (CHF 4'820.00). The grouping is done by hand (not
 * `toLocaleString`) so the separator is a deterministic straight apostrophe regardless of the
 * runtime's ICU data.
 */

/** Group an integer string with the Swiss apostrophe every three digits (1234 → 1'234). */
function groupSwiss(digits: string): string {
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, "'");
}

/** Format integer minor units as a Swiss-grouped amount with two decimals (123450 → 1'234.50). */
export function formatSwissAmount(minor: number): string {
    const negative = minor < 0;
    const abs = Math.abs(Math.trunc(minor));
    const major = Math.floor(abs / 100);
    const cents = abs % 100;

    return `${negative ? '-' : ''}${groupSwiss(String(major))}.${String(cents).padStart(2, '0')}`;
}

/** Format integer minor units as a currency amount (e.g. "CHF 4'820.00"). */
export function formatSwissMoney(minor: number, currency: string): string {
    return `${currency} ${formatSwissAmount(minor)}`;
}
