<?php

namespace Modules\Surgery\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Exceptions\TariffNotFoundForDateException;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ChargeSetReader;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Surgery\Exceptions\SurgicalBillingException;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalCaseCharge;
use Modules\Surgery\Services\SurgicalBillingService;

/**
 * Surgical case billing (SURGERY.G5) — PRESENTATIONAL over `SurgicalBillingService`. From a case, capture the
 * charges (procedure + theatre-time + the G4 consumables/implants) through the EXISTING engine and issue an
 * invoice that reconciles-to-the-unit. Gated `billing.manage` (the billing office); the case read is
 * read-logged. String-id (FIX.1). NO money math (the engine owns pricing/line-totals).
 */
class SurgicalBillingController
{
    public function show(Request $request, string $case, SurgicalBillingService $billing, ChargeSetReader $chargeSet): Response
    {
        Gate::authorize('billing.manage');
        abort_unless($request->user() instanceof User, 403);

        $record = SurgicalCase::query()->with('patient')->whereKey($case)->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        $chargeIds = SurgicalCaseCharge::query()->where('surgical_case_id', $record->id)->pluck('charge_id');
        $charges = Charge::query()->with('tariffCatalog')->whereIn('id', $chargeIds->all())->orderBy('id')->get();
        $invoiceId = $charges->firstWhere('invoice_id', '!=', null)?->invoice_id;
        // EVERY money figure on this screen is the ENGINE's, read — never recomputed here and never
        // recomputed in the Vue (QA-FIX.6a, P6-C1). `ChargeSetReader` returns each line's stored engine
        // total and their net Σ, already formatted with the charges' own currency; the AUTHORITATIVE total
        // of an ISSUED invoice is read straight off the invoice. Surgery names no money column at all —
        // the fence test asserts that literally.
        $invoice = $invoiceId === null ? null : Invoice::query()->find($invoiceId);
        $captured = $chargeSet->present($charges);

        return Inertia::render('Surgery/CaseBilling', [
            'surgicalCase' => [
                'id' => $record->id,
                'patient' => trim($record->patient->first_name.' '.$record->patient->last_name),
                'procedure' => $record->procedure_description,
                'status' => $record->status,
                'case_url' => route('surgery.cases.show', $record->id),
            ],
            // Each line carries the ENGINE's own stored amount, already formatted. The Vue does no
            // arithmetic and never divides by 100 — it prints what the engine says.
            'charges' => $captured['lines'],
            // The NET (ex-VAT) Σ of those engine amounts. Labelled an ESTIMATE on screen because it is
            // not an invoice total: VAT is applied by the engine at issue, and more charges may still be
            // captured. Once an invoice exists, its own `total_minor` is the authoritative figure.
            'capturedTotal' => [
                'minor' => $captured['total_minor'],
                'currency' => $captured['currency'],
                'formatted' => $captured['total_formatted'],
            ],
            'procedures' => $billing->catalogTariffs()->where('unit', 'procedure')->map(fn (TariffItem $t): array => [
                'code' => $t->code, 'name' => $t->description,
            ])->values()->all(),
            // The AUTHORITATIVE figure once issued — the engine's own invoice total, formatted through the
            // same formatter as the lines above so the two can never disagree in style.
            'invoice' => $invoice === null ? null : [
                'id' => $invoice->id,
                'url' => route('billing.invoices.show', $invoice->id),
                'total_formatted' => $chargeSet->format((int) $invoice->total_minor, (string) $invoice->currency),
            ],
            'actions' => [
                'can_bill' => Gate::allows('billing.manage'),
                'charge_url' => route('surgery.cases.billing.charge', $record->id),
                'invoice_url' => route('surgery.cases.billing.invoice', $record->id),
            ],
        ]);
    }

    public function charge(Request $request, string $case, SurgicalBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'procedure_code' => ['nullable', 'string', 'max:60'],
            'theatre_minutes' => ['nullable', 'integer', 'min:1'],
        ]);

        $record = SurgicalCase::query()->whereKey($case)->firstOrFail();

        try {
            $billing->chargeCase($actor, $record, $data['procedure_code'] ?? null, $data['theatre_minutes'] ?? null);
        } catch (SurgicalBillingException|CrossTenantReferenceException $e) {
            return back()->withErrors(['surgical_billing' => $e->getMessage()]);
        } catch (TariffNotFoundForDateException $e) {
            // QA-FIX.6c (P6-C3): a REACHABLE refusal that used to escape as an uncaught 500. Ask for
            // theatre minutes before theatre time has been priced — the minutes box is a free numeric
            // input and the price is nullable on a fresh tenant — and the engine correctly refuses to
            // invent a rate. That refusal is now shown; it was not a crash, it was a message with
            // nowhere to go. NOTHING IS WEAKENED: `chargeCase()` is transactional (D-208) and already
            // leaves nothing behind, so this changes only what the operator is told, from a 500 page to
            // the engine's own sentence. Deliberately narrow — the tariff exception only, not Throwable.
            return back()->withErrors(['surgical_billing' => $e->getMessage()]);
        }

        return redirect()->route('surgery.cases.billing', $record->id)->with('status', 'surgical-case-charged');
    }

    public function invoice(Request $request, string $case, SurgicalBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = SurgicalCase::query()->whereKey($case)->firstOrFail();

        try {
            $invoice = $billing->invoiceCase($actor, $record);
        } catch (SurgicalBillingException|CrossTenantReferenceException $e) {
            return back()->withErrors(['surgical_billing' => $e->getMessage()]);
        }

        return redirect()->route('billing.invoices.show', $invoice->id)->with('status', 'surgical-case-invoiced');
    }
}
