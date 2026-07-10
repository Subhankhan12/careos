<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Invoice;

class DunningLetterRenderer
{
    /**
     * Render a dunning reminder to private tenant-prefixed local storage and
     * return the path. No public URL is ever exposed.
     */
    public function render(Invoice $invoice, int $level, string $templateBody, int $openBalanceMinor): string
    {
        $path = sprintf(
            'tenants/%s/billing/dunning/%s-%s-L%d.pdf',
            $invoice->tenant_id,
            $invoice->series,
            $invoice->number,
            $level,
        );

        $lines = [
            '%PDF-1.4',
            'CareOS payment reminder',
            'Invoice: '.$invoice->series.'-'.$invoice->number,
            'Reminder level: '.$level,
            'Issue date: '.$invoice->issue_date?->toDateString(),
            'Due date: '.$invoice->due_date?->toDateString(),
            'Currency: '.$invoice->currency,
            'Open balance: '.$openBalanceMinor,
            '',
            $templateBody,
        ];

        Storage::disk('local')->put($path, implode("\n", $lines)."\n");

        return $path;
    }
}
