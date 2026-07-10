<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Patients\Models\PortalAccount;

/**
 * Portal invoices: the patient's OWN issued invoices only. Open balances come
 * from the mutable invoice_balances projection (the frozen legal row is never
 * touched). PDF downloads stream from the PRIVATE disk through this authorized
 * controller with a patient-scoped read audit row — no public URLs.
 * NO payment processing here: Stripe/PSP stays DEFERRED.
 */
class PortalInvoiceController
{
    public function index(Request $request): Response
    {
        $account = $this->account($request);

        $invoices = Invoice::query()
            ->where('patient_id', $account->patient_id)
            ->whereNotNull('number')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Portal/Invoices', [
            'invoices' => $invoices->map(function (Invoice $invoice): array {
                $balance = InvoiceBalance::query()->where('invoice_id', $invoice->id)->first();

                return [
                    'id' => $invoice->id,
                    'number' => $invoice->series.'-'.$invoice->number,
                    'issue_date' => $invoice->issue_date?->toDateString(),
                    'due_date' => $invoice->due_date?->toDateString(),
                    'currency' => $invoice->currency,
                    'total_minor' => $invoice->total_minor,
                    'open_balance_minor' => $balance !== null ? $balance->open_balance_minor : 0,
                    'status' => $balance !== null ? $balance->status : $invoice->status,
                    'download_url' => route('portal.invoices.download', $invoice->id),
                ];
            })->all(),
        ]);
    }

    public function download(string $invoice, Request $request): HttpResponse
    {
        $account = $this->account($request);

        $record = Invoice::query()
            ->whereKey($invoice)
            ->where('patient_id', $account->patient_id)
            ->whereNotNull('number')
            ->whereNotNull('pdf_path')
            ->firstOrFail();

        // Patient-scoped read audit row for the disclosure.
        $record->auditRead(['surface' => 'portal_invoice_download']);

        $contents = Storage::disk('local')->get($record->pdf_path);
        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$record->series.'-'.$record->number.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function account(Request $request): PortalAccount
    {
        $account = $request->user('patient');
        abort_unless($account instanceof PortalAccount, 401);

        return $account;
    }
}
