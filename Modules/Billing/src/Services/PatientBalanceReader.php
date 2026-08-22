<?php

namespace Modules\Billing\Services;

use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;

/**
 * THE one source for "what this patient owes".
 *
 * Portal Home and Portal Invoices both showed an open balance and derived it INDEPENDENTLY — Home
 * summed the projection server-side, while the invoices page ran a `.reduce()` over the rows it had
 * been sent and, separately, excluded credit notes. Two derivations of one figure on two screens a
 * patient sees minutes apart: they could disagree, and with a credit note on the account they did.
 * This class exists so there is nothing left to disagree about.
 *
 * THE RULE IS THE ENGINE'S, NOT A NEW ONE. `MetricsService::accountLedger()` defines an account's
 * outstanding as **Σ `invoice_balances.open_balance_minor`** and asserts the tie (δ=0) against its
 * own running balance. This reader computes the same sum over the same projection, so the portal
 * figure ties to the AR account detail staff see for the same patient. A credit note is one of the
 * account's invoices and its balance participates like any other — that is the engine's semantics,
 * and the portal now follows it rather than inventing a second opinion.
 *
 * ISSUED INVOICES ONLY, matching what the portal is allowed to show at all: a draft invoice has no
 * number and never reaches a patient, so it cannot contribute to what they are told they owe.
 *
 * NO JUDGMENT. This returns a figure and a formatted string. It does not decide whether a balance
 * is overdue, large, urgent or worrying, and nothing downstream may colour by it (D-169).
 */
class PatientBalanceReader
{
    /**
     * The patient's outstanding balance in minor units — Σ the projection's open balances over the
     * patient's ISSUED invoices. Integer arithmetic only; never a float, never a page-side sum.
     */
    public function outstandingMinorFor(string $patientId): int
    {
        $invoiceIds = Invoice::query()
            ->where('patient_id', $patientId)
            ->whereNotNull('number')
            ->pluck('id');

        return (int) InvoiceBalance::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->sum('open_balance_minor');
    }

    /**
     * The currency the patient's invoices are denominated in. Read from the invoices themselves —
     * never guessed, never defaulted to a hardcoded code.
     */
    public function currencyFor(string $patientId): string
    {
        return (string) (Invoice::query()
            ->where('patient_id', $patientId)
            ->whereNotNull('number')
            ->orderByDesc('issue_date')
            ->value('currency') ?? '');
    }

    /**
     * The figure as a page should receive it: the integer, the currency, and the ALREADY-FORMATTED
     * string. Formatting happens here so no portal template divides by 100 (the DENTAL-B.P4
     * contract, applied patient-side).
     *
     * @return array{minor: int, currency: string, formatted: string}
     */
    public function present(string $patientId): array
    {
        $minor = $this->outstandingMinorFor($patientId);
        $currency = $this->currencyFor($patientId);

        return [
            'minor' => $minor,
            'currency' => $currency,
            'formatted' => $this->format($minor, $currency),
        ];
    }

    /**
     * Swiss-style formatting, matching the treatment-plan surface (DENTAL-B.P4): apostrophe
     * grouping, two decimals, currency first.
     */
    public function format(int $minor, string $currency): string
    {
        $amount = number_format($minor / 100, 2, '.', "'");

        return $currency === '' ? $amount : $currency.' '.$amount;
    }
}
