<?php

namespace Modules\Radiology\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ChargeSetReader;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Radiology\Exceptions\RadiologyBillingException;
use Modules\Radiology\Models\RadiologyExam;
use Modules\Radiology\Models\RadiologyOrder;
use Modules\Radiology\Models\RadiologyOrderCharge;
use Modules\Radiology\Services\RadiologyBillingService;

/**
 * Radiology billing (RAD.G5) — PRESENTATIONAL over `RadiologyBillingService`. From an imaging order, set the
 * tenant-authored exam price, capture the charge through the EXISTING engine, and issue an outpatient invoice
 * that reconciles-to-the-unit. For an inpatient/ED patient the imaging charges join the stay/episode's discharge
 * invoice via the existing flow (no order-level invoice). Gated `billing.manage` (the billing office — NOT the
 * radiology bench); the patient read is read-logged. String-id (FIX.1). NO money math (the engine owns
 * pricing/line-totals). The exam fee is a plain tariff — NOT report-driven (the fence).
 */
class RadiologyBillingController
{
    public function show(Request $request, string $radiologyOrder, RadiologyBillingService $billing, ChargeSetReader $chargeSet): Response
    {
        Gate::authorize('billing.manage');
        abort_unless($request->user() instanceof User, 403);

        $record = RadiologyOrder::query()->with(['order.orderableItem'])->whereKey($radiologyOrder)->firstOrFail();
        $order = $record->order;
        $patient = Patient::query()->findOrFail($record->patient_id);
        $patient->auditRead(); // patient-scoped read log

        $chargeIds = RadiologyOrderCharge::query()->where('radiology_order_id', $record->id)->pluck('charge_id');
        $charges = Charge::query()->whereIn('id', $chargeIds->all())->orderBy('id')->get();
        $invoiceId = $charges->firstWhere('invoice_id', '!=', null)?->invoice_id;
        // EVERY money figure on this screen is the ENGINE's, read — never recomputed here and never
        // recomputed in the Vue (QA-FIX.8a, P8-C1; the QA-FIX.6a / D-208 remedy applied to Radiology).
        // `ChargeSetReader` lives in Billing precisely so this module can SHOW a stored line total without
        // NAMING the column: the Radiology money fence scans every file here byte-for-byte for the
        // engine's total columns, so reading one directly would redden a passing guard.
        $invoice = $invoiceId === null ? null : Invoice::query()->find($invoiceId);
        $captured = $chargeSet->present($charges);
        // The snapshotted RATE beside each line, formatted through the SAME reader so two columns on one
        // row cannot disagree in style. The rate is the tariff snapshot this module already carries; the
        // AMOUNT next to it is the engine's own stored figure and is never derived from it.
        $ordered = $charges->values();
        $lines = [];
        foreach ($captured['lines'] as $i => $line) {
            $line['rate_formatted'] = $chargeSet->format((int) $ordered[$i]->unit_price_minor, $captured['currency']);
            $lines[] = $line;
        }

        return Inertia::render('Radiology/Billing', [
            'order' => [
                'id' => $record->id,
                'patient' => trim($patient->first_name.' '.$patient->last_name),
                'exam' => $order?->orderableItem?->name,
                'code' => $order?->orderableItem?->code,   // the tariff code for this exam
                'modality' => $record->modality,
                'priority' => $record->priority,           // the RAD.G2 recorded flag (a fact)
                'order_status' => $order?->status,
                'report_url' => route('radiology.reports.show', $record->id),
            ],
            // Each line carries the ENGINE's own stored amount, already formatted. The Vue does no
            // arithmetic and never divides by 100 — it prints what the engine says.
            'charges' => $lines,
            // The NET (ex-VAT) Σ of those engine amounts. Labelled an ESTIMATE on screen because it is NOT
            // an invoice total: VAT is applied by the engine at issue, and `invoiceOrder()` gathers every
            // validated uninvoiced charge for the patient across the whole service DAY, so the invoice can
            // legitimately exceed these lines. Once issued, the invoice's own total is authoritative.
            'capturedTotal' => [
                'minor' => $captured['total_minor'],
                'currency' => $captured['currency'],
                'formatted' => $captured['total_formatted'],
            ],
            'tariffs' => $billing->catalogTariffs()->map(fn (TariffItem $t): array => [
                'code' => $t->code,
                'name' => $t->description,
                'unit_price_minor' => $t->unit_price_minor,
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
                'price_exam_url' => route('radiology.billing.price-exam', $record->id),
                'charge_url' => route('radiology.billing.charge', $record->id),
                'invoice_url' => route('radiology.billing.invoice', $record->id),
            ],
        ]);
    }

    public function priceExam(Request $request, string $radiologyOrder, RadiologyBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate(['price_minor' => ['required', 'integer', 'min:1']]);

        $record = RadiologyOrder::query()->with('order')->whereKey($radiologyOrder)->firstOrFail();
        $exam = RadiologyExam::query()->where('orderable_item_id', $record->order?->orderable_item_id)->firstOrFail();

        try {
            $billing->priceExam($actor, $exam, $data['price_minor']);
        } catch (RadiologyBillingException $e) {
            return back()->withErrors(['radiology_billing' => $e->getMessage()]);
        }

        return redirect()->route('radiology.billing.show', $record->id)->with('status', 'radiology-exam-priced');
    }

    public function charge(Request $request, string $radiologyOrder, RadiologyBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = RadiologyOrder::query()->whereKey($radiologyOrder)->firstOrFail();

        try {
            $billing->chargeOrder($actor, $record);
        } catch (RadiologyBillingException|CrossTenantReferenceException $e) {
            return back()->withErrors(['radiology_billing' => $e->getMessage()]);
        }

        return redirect()->route('radiology.billing.show', $record->id)->with('status', 'radiology-order-charged');
    }

    public function invoice(Request $request, string $radiologyOrder, RadiologyBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = RadiologyOrder::query()->whereKey($radiologyOrder)->firstOrFail();

        try {
            $issued = $billing->invoiceOrder($actor, $record);
        } catch (RadiologyBillingException|CrossTenantReferenceException $e) {
            return back()->withErrors(['radiology_billing' => $e->getMessage()]);
        }

        return redirect()->route('billing.invoices.show', $issued->id)->with('status', 'radiology-order-invoiced');
    }
}
