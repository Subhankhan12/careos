<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceLine;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

/**
 * Credit notes are `series=CN` invoices referencing an original via
 * credit_note_for_invoice_id, with negative lines. READ-ONLY here (billing.view);
 * the create path is on the invoice detail through IssueService::creditNote.
 * Related rows are read through concretely-typed queries (no relation-property
 * traversal), and all money stays integer minor units — the view formats only.
 */
class CreditNoteController
{
    public function index(Request $request): Response
    {
        Gate::authorize('billing.view');
        abort_unless($request->user() instanceof User, 403);

        $creditNotes = Invoice::query()
            ->where('series', Invoice::SERIES_CREDIT_NOTE)
            ->whereNotNull('number')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $patients = Patient::query()
            ->whereIn('id', $creditNotes->pluck('patient_id')->filter()->all())
            ->get()
            ->keyBy('id');
        $originals = Invoice::query()
            ->whereIn('id', $creditNotes->pluck('credit_note_for_invoice_id')->filter()->all())
            ->get()
            ->keyBy('id');

        return Inertia::render('Billing/CreditNotes/Index', [
            'creditNotes' => $creditNotes->map(function (Invoice $cn) use ($patients, $originals): array {
                $patient = $patients->get($cn->patient_id);
                $original = $cn->credit_note_for_invoice_id !== null ? $originals->get($cn->credit_note_for_invoice_id) : null;

                return [
                    'id' => $cn->id,
                    'number' => $cn->series.'-'.$cn->number,
                    'patient' => $patient !== null ? trim($patient->first_name.' '.$patient->last_name) : null,
                    'payer_name' => $cn->payer_name,
                    'against_invoice' => $original instanceof Invoice && $original->number !== null
                        ? $original->series.'-'.$original->number
                        : null,
                    'issue_date' => $cn->issue_date?->toDateString(),
                    'currency' => $cn->currency,
                    'total_minor' => $cn->total_minor,
                    'show_url' => route('billing.credit-notes.show', $cn->id),
                ];
            })->all(),
            'invoicesUrl' => route('billing.invoices.index'),
        ]);
    }

    public function show(string $invoice, Request $request): Response
    {
        Gate::authorize('billing.view');
        // Resolve inside the action (not via implicit binding, which runs before the tenant
        // context is set); the BelongsToTenant scope makes a missing/cross-tenant id 404.
        $invoice = Invoice::query()->whereKey($invoice)->firstOrFail();
        abort_unless($invoice->series === Invoice::SERIES_CREDIT_NOTE, 404);

        $invoice->auditRead(['surface' => 'billing_credit_note']);

        $patient = Patient::query()->find($invoice->patient_id);
        $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('id')->get();
        $original = $invoice->credit_note_for_invoice_id !== null
            ? Invoice::query()->find($invoice->credit_note_for_invoice_id)
            : null;

        return Inertia::render('Billing/CreditNotes/Show', [
            'creditNote' => [
                'id' => $invoice->id,
                'number' => $invoice->number !== null ? $invoice->series.'-'.$invoice->number : null,
                'status' => $invoice->status,
                'payer_name' => $invoice->payer_name,
                'payer_type' => $invoice->payer_type,
                'patient' => $patient !== null ? [
                    'name' => trim($patient->first_name.' '.$patient->last_name),
                    'mrn' => $patient->mrn,
                ] : null,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'currency' => $invoice->currency,
                'subtotal_minor' => $invoice->subtotal_minor,
                'vat_total_minor' => $invoice->vat_total_minor,
                'total_minor' => $invoice->total_minor,
                'lines' => $lines->map(fn (InvoiceLine $line): array => [
                    'id' => $line->id,
                    'code' => $line->code,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price_minor' => $line->unit_price_minor,
                    'line_total_minor' => $line->line_total_minor,
                ])->all(),
                'against_invoice' => $original instanceof Invoice ? [
                    'number' => $original->number !== null ? $original->series.'-'.$original->number : null,
                    'total_minor' => $original->total_minor,
                    'show_url' => route('billing.invoices.show', $original->id),
                ] : null,
            ],
            'creditNotesUrl' => route('billing.credit-notes.index'),
        ]);
    }
}
