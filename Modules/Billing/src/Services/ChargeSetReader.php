<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Collection;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\TariffCatalog;

/**
 * THE one source for "what does this set of charges come to" — the {@see PatientBalanceReader} discipline
 * applied to a set of captured charges rather than to an account balance.
 *
 * WHY IT EXISTS (QA-FIX.6a, P6-C1). The surgical case-billing page received `quantity` and
 * `unit_price_minor` and did its own arithmetic: `quantity × unit_price` per line and a `.reduce()` over
 * the rows to total them. That is a SECOND derivation of a figure the engine has already computed and
 * stored — `charges.line_total_minor`, written by {@see ChargeCaptureService::capture()} — and a second
 * derivation can disagree with the first. It is the same defect `PatientBalanceReader` was written to end
 * on the patient portal, and the same rule applies here: **the engine owns every money figure, including
 * the one a page displays.**
 *
 * THE RULE IS THE ENGINE'S, NOT A NEW ONE. A line's amount is `charges.line_total_minor` exactly as the
 * engine stored it. A set's total is Σ of that column — the same aggregate `IssueService` uses to build an
 * invoice subtotal (`$lines->sum('line_total_minor')`). Nothing here multiplies, divides or rounds: the
 * only arithmetic is an integer sum of integer columns, server-side.
 *
 * THE FIGURE IS A NET (ex-VAT) SUBTOTAL, and callers must label it as such. VAT is applied by the engine
 * when an invoice is issued, so this total is NOT an invoice total and must never be presented as one. An
 * issued invoice's `total_minor` is the authoritative figure and is read straight off the invoice.
 *
 * CURRENCY IS READ, NEVER GUESSED — from the charges' own tariff catalog, which is exactly how
 * `IssueService::tenantCurrencyFromCharges()` decides an invoice's currency. An empty set has no currency
 * and says so rather than defaulting to one.
 *
 * NO JUDGMENT. This returns figures and formatted strings. It does not decide whether a total is large,
 * unusual or worth attention, and nothing downstream may colour by it (D-169).
 */
class ChargeSetReader
{
    public function __construct(private readonly PatientBalanceReader $balances) {}

    /**
     * The net total of a charge set in minor units — Σ the engine's own line totals. Integer arithmetic
     * only; never a float, never a page-side sum.
     *
     * @param  Collection<int, Charge>  $charges
     */
    public function totalMinor(Collection $charges): int
    {
        return (int) $charges->sum('line_total_minor');
    }

    /**
     * The currency this set is denominated in, taken from the charges' tariff catalog — the same source
     * `IssueService::tenantCurrencyFromCharges()` uses. Returns '' for an empty set rather than inventing
     * a default the caller would then render as fact.
     *
     * @param  Collection<int, Charge>  $charges
     */
    public function currency(Collection $charges): string
    {
        $catalog = $charges->first()?->tariffCatalog;

        return $catalog instanceof TariffCatalog ? (string) $catalog->currency : '';
    }

    /**
     * The shared formatter, exposed so a caller can present a figure the engine owns elsewhere — an issued
     * invoice's `total_minor`, say — through the SAME formatter as this set's lines, rather than dividing
     * by 100 in a template. One formatter, so two figures on one screen cannot disagree in style.
     */
    public function format(int $minor, string $currency): string
    {
        return $this->balances->format($minor, $currency);
    }

    /**
     * The set as a page should receive it: one entry per charge carrying the ENGINE's line amount already
     * formatted, plus the net total. Formatting happens here so no template divides by 100 (the
     * DENTAL-B.P4 contract, applied to charge display).
     *
     * @param  Collection<int, Charge>  $charges
     * @return array{lines: list<array{code: string, description: string|null, quantity: int, status: string, amount_minor: int, amount_formatted: string}>, total_minor: int, currency: string, total_formatted: string}
     */
    public function present(Collection $charges): array
    {
        $currency = $this->currency($charges);
        $totalMinor = $this->totalMinor($charges);

        return [
            'lines' => $charges->map(fn (Charge $c): array => [
                'code' => $c->code,
                'description' => $c->description,
                'quantity' => (int) $c->quantity,
                'status' => $c->status,
                // The engine's stored line total, read — not quantity × rate recomputed.
                'amount_minor' => (int) $c->line_total_minor,
                'amount_formatted' => $this->balances->format((int) $c->line_total_minor, $currency),
            ])->values()->all(),
            'total_minor' => $totalMinor,
            'currency' => $currency,
            'total_formatted' => $this->balances->format($totalMinor, $currency),
        ];
    }
}
