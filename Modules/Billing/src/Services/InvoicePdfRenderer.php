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
            'tenants/%s/billing/invoices/%s-%s.pdf',
            $invoice->tenant_id,
            $invoice->series,
            $invoice->number,
        );

        $vatByRate = $invoiceLines
            ->groupBy('vat_rate_bp')
            ->map(fn ($lines): int => (int) $lines->sum('line_vat_minor'))
            ->sortKeys();

        $lines = [
            '%PDF-1.4',
            'CareOS EU-Generic VAT invoice',
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
