<?php

namespace Modules\Lab\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ChargeSetReader;
use Modules\Lab\Exceptions\LabBillingException;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\LabOrderCharge;
use Modules\Lab\Models\LabTest;
use Modules\Lab\Services\LabBillingService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * Lab billing (LAB.G6) — PRESENTATIONAL over `LabBillingService`. From a lab order, set the tenant-authored
 * test price, capture the charge through the EXISTING engine, and issue an outpatient invoice that reconciles-
 * to-the-unit. For an inpatient/ED patient the lab charges join the stay/episode's discharge invoice via the
 * existing flow (no order-level invoice). Gated `billing.manage` (the billing office — NOT the lab bench); the
 * patient read is read-logged. String-id (FIX.1). NO money math (the engine owns pricing/line-totals). The lab
 * fee is a plain tariff — NOT result-driven (the fence).
 */
class LabBillingController
{
    public function show(Request $request, string $labOrder, LabBillingService $billing, ChargeSetReader $chargeSet): Response
    {
        Gate::authorize('billing.manage');
        abort_unless($request->user() instanceof User, 403);

        $record = LabOrder::query()->with(['order.orderableItem'])->whereKey($labOrder)->firstOrFail();
        $order = $record->order;
        $patient = Patient::query()->findOrFail($record->patient_id);
        $patient->auditRead(); // patient-scoped read log

        $chargeIds = LabOrderCharge::query()->where('lab_order_id', $record->id)->pluck('charge_id');
        $charges = Charge::query()->whereIn('id', $chargeIds->all())->orderBy('id')->get();
        $invoiceId = $charges->firstWhere('invoice_id', '!=', null)?->invoice_id;
        // EVERY money figure on this screen is the ENGINE's, read — never recomputed here and never
        // recomputed in the Vue (QA-FIX.8a, P8-C1; the QA-FIX.6a / D-208 remedy applied to Lab).
        // `ChargeSetReader` lives in Billing precisely so this module can SHOW a stored line total
        // without NAMING the column: the Lab money fence scans every file here byte-for-byte for the
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

        return Inertia::render('Lab/Billing', [
            'labOrder' => [
                'id' => $record->id,
                'patient' => trim($patient->first_name.' '.$patient->last_name),
                'test' => $order?->orderableItem?->name,
                'code' => $order?->orderableItem?->code,   // the tariff code for this test
                'priority' => $record->priority,           // the LAB.G2 recorded flag (a fact)
                'order_status' => $order?->status,          // the reused Clinical Order lifecycle state
                'results_url' => route('lab.results.show', $record->id),
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
                'price_test_url' => route('lab.billing.price-test', $record->id),
                'charge_url' => route('lab.billing.charge', $record->id),
                'invoice_url' => route('lab.billing.invoice', $record->id),
            ],
        ]);
    }

    public function priceTest(Request $request, string $labOrder, LabBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate(['price_minor' => ['required', 'integer', 'min:1']]);

        $record = LabOrder::query()->with('order')->whereKey($labOrder)->firstOrFail();
        $labTest = LabTest::query()->where('orderable_item_id', $record->order?->orderable_item_id)->firstOrFail();

        try {
            $billing->priceTest($actor, $labTest, $data['price_minor']);
        } catch (LabBillingException $e) {
            return back()->withErrors(['lab_billing' => $e->getMessage()]);
        }

        return redirect()->route('lab.billing.show', $record->id)->with('status', 'lab-test-priced');
    }

    public function charge(Request $request, string $labOrder, LabBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = LabOrder::query()->whereKey($labOrder)->firstOrFail();

        try {
            $billing->chargeOrder($actor, $record);
        } catch (LabBillingException|CrossTenantReferenceException $e) {
            return back()->withErrors(['lab_billing' => $e->getMessage()]);
        }

        return redirect()->route('lab.billing.show', $record->id)->with('status', 'lab-order-charged');
    }

    public function invoice(Request $request, string $labOrder, LabBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = LabOrder::query()->whereKey($labOrder)->firstOrFail();

        try {
            $issued = $billing->invoiceOrder($actor, $record);
        } catch (LabBillingException|CrossTenantReferenceException $e) {
            return back()->withErrors(['lab_billing' => $e->getMessage()]);
        }

        return redirect()->route('billing.invoices.show', $issued->id)->with('status', 'lab-order-invoiced');
    }
}
