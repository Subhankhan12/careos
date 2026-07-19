<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Billing\Models\InvoiceLine;
use Modules\Billing\Services\IssueService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;
use Modules\Reporting\Services\MetricsService;

/**
 * Staff-facing invoice worklist + detail. READS are gated on `billing.view`;
 * WRITES (issue, credit note) are gated on `billing.manage` AND go exclusively
 * through the tested billing services — no invoice/VAT/numbering math lives here.
 * Money is integer minor units; the view formats, it never computes. Invoices are
 * tenant-scoped by the BelongsToTenant global scope (cross-tenant reads 404).
 */
class InvoiceController
{
    /** Invoice-lifecycle statuses that may be used as a list filter. */
    private const FILTERABLE_STATUSES = [
        Invoice::STATUS_DRAFT,
        Invoice::STATUS_ISSUED,
        Invoice::STATUS_PARTIALLY_PAID,
        Invoice::STATUS_PAID,
        Invoice::STATUS_CANCELLED_BY_CREDIT_NOTE,
    ];

    public function index(Request $request, MetricsService $metrics): Response
    {
        Gate::authorize('billing.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $status = $request->query('status');
        $status = in_array($status, self::FILTERABLE_STATUSES, true) ? $status : null;

        $invoices = Invoice::query()
            ->where('series', Invoice::SERIES_INVOICE)
            ->when($status !== null, fn ($query) => $query->whereHas('balance', fn ($b) => $b->where('status', $status)))
            ->orderByRaw('issue_date IS NULL DESC')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        // Concretely-typed side lookups (patients, live balances) keyed for the rows —
        // the same typed-query pattern PortalInvoiceController uses for balances.
        $patients = Patient::query()
            ->whereIn('id', $invoices->pluck('patient_id')->filter()->all())
            ->get()
            ->keyBy('id');
        $balances = InvoiceBalance::query()
            ->whereIn('invoice_id', $invoices->pluck('id')->all())
            ->get()
            ->keyBy('invoice_id');

        return Inertia::render('Billing/Invoices/Index', [
            'filters' => ['status' => $status],
            'invoices' => $invoices->map(fn (Invoice $invoice): array => $this->toRow(
                $invoice,
                $patients->get($invoice->patient_id),
                $balances->get($invoice->id),
            ))->all(),
            'counters' => [
                // Every figure is a single call into the tested reporting service — no
                // financial aggregation (aging totals, VAT, line sums) happens here.
                'outstanding_minor' => $metrics->outstandingBalanceMinor($actor),
                'overdue_minor' => $metrics->overdueBalanceMinor($actor, now()),
                'drafts' => $this->countByStatus(Invoice::STATUS_DRAFT),
                'paid' => $this->countByStatus(Invoice::STATUS_PAID),
                'currency' => Invoice::query()->where('series', Invoice::SERIES_INVOICE)->value('currency') ?? 'EUR',
            ],
            'agingUrl' => route('billing.aging'),
            'creditNotesUrl' => route('billing.credit-notes.index'),
            // Billing hub cross-links to the part-2 surfaces (CLINIC.W7).
            'paymentsUrl' => route('billing.payments.index'),
            'dunningUrl' => route('billing.dunning.index'),
            'newInvoiceUrl' => route('billing.invoices.create'),
            'canManage' => Gate::allows('billing.manage'),
        ]);
    }

    public function show(string $invoice, Request $request): Response
    {
        Gate::authorize('billing.view');
        // Resolve the tenant-scoped model INSIDE the action (not via implicit route-model
        // binding, which resolves before IdentifyTenantFromUser sets the context). The
        // BelongsToTenant global scope makes a missing/cross-tenant id 404, never a leak.
        $invoice = Invoice::query()->whereKey($invoice)->firstOrFail();
        abort_unless($invoice->series === Invoice::SERIES_INVOICE, 404);

        // Reading one patient's invoice is a disclosure — record a read-audit row.
        $invoice->auditRead(['surface' => 'billing_invoice']);

        // Concretely-typed reads for the document body — no relation-property traversal.
        $patient = Patient::query()->find($invoice->patient_id);
        $balance = InvoiceBalance::query()->where('invoice_id', $invoice->id)->first();
        $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('id')->get();
        $creditNotes = Invoice::query()
            ->where('series', Invoice::SERIES_CREDIT_NOTE)
            ->where('credit_note_for_invoice_id', $invoice->id)
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Billing/Invoices/Show', [
            'invoice' => $this->toDetail($invoice, $patient, $balance, $lines, $creditNotes),
            'actions' => [
                'can_manage' => Gate::allows('billing.manage'),
                'issue_url' => route('billing.invoices.issue', $invoice->id),
                'credit_note_url' => route('billing.invoices.credit-note', $invoice->id),
                'download_url' => route('billing.invoices.download', $invoice->id),
            ],
        ]);
    }

    public function issue(string $invoice, Request $request, IssueService $issueService): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        // Tenant-scoped resolution inside the action (see show() for the why); 404 fail-closed.
        $invoice = Invoice::query()->whereKey($invoice)->firstOrFail();

        // The gapless-numbering + immutability path is entirely inside IssueService.
        $issueService->issue($invoice, $actor);

        return redirect()->route('billing.invoices.show', $invoice->id);
    }

    public function creditNote(string $invoice, Request $request, IssueService $issueService): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $invoice = Invoice::query()->whereKey($invoice)->firstOrFail();

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        // Full credit (null lines): the CN document, its own gapless number, and the
        // original invoice staying untouched are all handled inside IssueService.
        $creditNote = $issueService->creditNote($invoice, null, $validated['reason'], $actor);

        return redirect()->route('billing.credit-notes.show', $creditNote->id);
    }

    public function download(string $invoice, Request $request): HttpResponse
    {
        Gate::authorize('billing.view');
        $invoice = Invoice::query()->whereKey($invoice)->firstOrFail();
        // This route serves invoice PDFs only; a credit note is fetched via its own surface.
        abort_unless($invoice->series === Invoice::SERIES_INVOICE, 404);
        abort_if($invoice->number === null || $invoice->pdf_path === null, 404);

        $invoice->auditRead(['surface' => 'billing_invoice_download']);

        $contents = Storage::disk('local')->get($invoice->pdf_path);
        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->series.'-'.$invoice->number.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function countByStatus(string $status): int
    {
        return Invoice::query()
            ->where('series', Invoice::SERIES_INVOICE)
            ->whereHas('balance', fn ($b) => $b->where('status', $status))
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Invoice $invoice, ?Patient $patient, ?InvoiceBalance $balance): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number !== null ? $invoice->series.'-'.$invoice->number : null,
            // balance.status is the LIVE lifecycle status; the frozen invoice.status is the fallback.
            'status' => $balance !== null ? $balance->status : $invoice->status,
            'payer_name' => $invoice->payer_name,
            'payer_type' => $invoice->payer_type,
            'patient' => $patient !== null ? trim($patient->first_name.' '.$patient->last_name) : null,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'currency' => $invoice->currency,
            'total_minor' => $invoice->total_minor,
            'open_balance_minor' => $balance !== null ? $balance->open_balance_minor : $invoice->open_balance_minor,
            'show_url' => route('billing.invoices.show', $invoice->id),
        ];
    }

    /**
     * @param  EloquentCollection<int, InvoiceLine>  $lines
     * @param  EloquentCollection<int, Invoice>  $creditNotes
     * @return array<string, mixed>
     */
    private function toDetail(Invoice $invoice, ?Patient $patient, ?InvoiceBalance $balance, EloquentCollection $lines, EloquentCollection $creditNotes): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number !== null ? $invoice->series.'-'.$invoice->number : null,
            'series' => $invoice->series,
            'status' => $balance !== null ? $balance->status : $invoice->status,
            'payer_name' => $invoice->payer_name,
            'payer_type' => $invoice->payer_type,
            'patient' => $patient !== null ? [
                'name' => trim($patient->first_name.' '.$patient->last_name),
                'mrn' => $patient->mrn,
                'date_of_birth' => $patient->date_of_birth->toDateString(),
            ] : null,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'currency' => $invoice->currency,
            'subtotal_minor' => $invoice->subtotal_minor,
            'vat_total_minor' => $invoice->vat_total_minor,
            'total_minor' => $invoice->total_minor,
            'open_balance_minor' => $balance !== null ? $balance->open_balance_minor : $invoice->open_balance_minor,
            'has_pdf' => $invoice->pdf_path !== null,
            'lines' => $lines->map(fn (InvoiceLine $line): array => [
                'id' => $line->id,
                'code' => $line->code,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price_minor' => $line->unit_price_minor,
                'vat_rate_bp' => $line->vat_rate_bp,
                'line_total_minor' => $line->line_total_minor,
                'line_vat_minor' => $line->line_vat_minor,
            ])->all(),
            'credit_notes' => $creditNotes->map(fn (Invoice $cn): array => [
                'id' => $cn->id,
                'number' => $cn->number !== null ? $cn->series.'-'.$cn->number : null,
                'total_minor' => $cn->total_minor,
                'show_url' => route('billing.credit-notes.show', $cn->id),
            ])->all(),
        ];
    }
}
