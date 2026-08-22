<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Billing\Services\PatientBalanceReader;
use Modules\Patients\Models\Patient;
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
    public function index(Request $request, PatientBalanceReader $balances): Response
    {
        $account = $this->account($request);

        // PT.P1 — the patient is reading their own record: one read row per render, through
        // the EXISTING auditRead() path, so this disclosure appears in their access log (PC.P5).
        Patient::query()->whereKey($account->patient_id)->firstOrFail()
            ->auditRead(['surface' => 'portal_invoices']);

        $invoices = Invoice::query()
            ->where('patient_id', $account->patient_id)
            ->whereNotNull('number')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Portal/Invoices', [
            'invoices' => $invoices->map(function (Invoice $invoice) use ($balances): array {
                $balance = InvoiceBalance::query()->where('invoice_id', $invoice->id)->first();
                $openMinor = $balance !== null ? (int) $balance->open_balance_minor : 0;

                return [
                    'id' => $invoice->id,
                    'number' => $invoice->series.'-'.$invoice->number,
                    'issue_date' => $invoice->issue_date?->toDateString(),
                    'due_date' => $invoice->due_date?->toDateString(),
                    'currency' => $invoice->currency,
                    'total_minor' => $invoice->total_minor,
                    'open_balance_minor' => $openMinor,
                    // PT.P2 — formatted HERE. The page renders these strings and performs no
                    // arithmetic of its own, not even a divide-by-100.
                    'total' => $balances->format((int) $invoice->total_minor, (string) $invoice->currency),
                    'open_balance' => $balances->format($openMinor, (string) $invoice->currency),
                    'status' => $balance !== null ? $balance->status : $invoice->status,
                    'download_url' => route('portal.invoices.download', $invoice->id),
                ];
            })->all(),
            /*
             * THE SAME FIGURE HOME SHOWS, from the SAME reader — not a re-derivation, and not a
             * sum of the rows above. The list can be filtered in the browser; this total cannot
             * drift with it, because the page never computes it.
             */
            'outstanding' => $balances->present($account->patient_id),
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
