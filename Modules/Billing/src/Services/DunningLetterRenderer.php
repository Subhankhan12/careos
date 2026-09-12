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
            'tenants/%s/billing/dunning/%s-%s-L%d.txt',
            $invoice->tenant_id,
            $invoice->series,
            $invoice->number,
            $level,
        );

        /*
         * `P3-H2` (QA-FIX.12d, D-229) — the forged header is gone here too. This finding named BOTH the
         * invoice download and "every dunning letter written by a reminder", and the letter carried the
         * same literal `%PDF-1.4` first line over the same plain text. A reminder is a document sent to a
         * patient about money they owe; claiming a format it does not have is the same defect (D-176),
         * and rendering a real PDF is the same refused feature.
         */
        $lines = [
            'CareOS payment reminder (plain text — not a PDF)',
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
