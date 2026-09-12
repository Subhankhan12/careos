<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceLine;
use Modules\Patients\Models\Patient;
use Modules\Platform\Services\SettingsService;

class InvoicePdfRenderer
{
    public function __construct(private readonly SettingsService $settings) {}

    public function render(Invoice $invoice): string
    {
        $patient = Patient::query()->whereKey($invoice->patient_id)->firstOrFail();
        $invoiceLines = InvoiceLine::query()
            ->where('invoice_id', $invoice->id)
            ->orderBy('id')
            ->get();

        $path = sprintf(
            'tenants/%s/billing/invoices/%s-%s.txt',
            $invoice->tenant_id,
            $invoice->series,
            $invoice->number,
        );

        $vatByRate = $invoiceLines
            ->groupBy('vat_rate_bp')
            ->map(fn ($lines): int => (int) $lines->sum('line_vat_minor'))
            ->sortKeys();

        /*
         * `P3-H2` (QA-FIX.12d, D-229) — THE FORGED HEADER IS GONE. This file is plain text and now says
         * so. It used to begin with the literal string `%PDF-1.4` while containing no object structure at
         * all — no `obj`, no `xref`, no `trailer`, no `stream`, no `%%EOF` — so no PDF reader could open
         * it. That is the clearest unbacked presence in the programme (D-176): a file pretending, in its
         * first bytes, to be a format it is not.
         *
         * RENDERING A REAL PDF IS A FEATURE AND IS DELIBERATELY NOT DONE HERE — CareOS has no PDF library
         * and adding one plus a laid-out invoice template is not a fix. The claim is withdrawn; the
         * capability is recorded as still missing.
         */
        $lines = [
            'CareOS EU-Generic VAT invoice (plain text — not a PDF)',
            'Seller: '.(string) $this->settings->get('billing.seller_name', 'CareOS tenant'),
            'Seller VAT ID: '.(string) $this->settings->get('billing.seller_vat_id', 'not-configured'),
            'Invoice: '.$invoice->series.'-'.$invoice->number,
            'Issue date: '.$invoice->issue_date?->toDateString(),
            'Due date: '.$invoice->due_date?->toDateString(),
            'Currency: '.$invoice->currency,
            'Patient: '.$patient->first_name.' '.$patient->last_name,
            'Payer type: '.$invoice->payer_type,
            'Payer name: '.($invoice->payer_name ?? ''),
            'Lines:',
        ];

        foreach ($invoiceLines as $line) {
            $lines[] = implode(' | ', [
                $line->code,
                $line->description,
                'qty='.$line->quantity,
                'unit='.$line->unit_price_minor,
                'vat_bp='.$line->vat_rate_bp,
                'net='.$line->line_total_minor,
                'vat='.$line->line_vat_minor,
            ]);
        }

        $lines[] = 'VAT breakdown:';
        foreach ($vatByRate as $rate => $amount) {
            $lines[] = $rate.' bp = '.$amount;
        }

        $lines[] = 'Subtotal: '.$invoice->subtotal_minor;
        $lines[] = 'VAT total: '.$invoice->vat_total_minor;
        $lines[] = 'Total: '.$invoice->total_minor;

        Storage::disk('local')->put($path, implode("\n", $lines)."\n");

        return $path;
    }
}
