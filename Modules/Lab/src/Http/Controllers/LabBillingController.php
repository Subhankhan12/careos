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
    public function show(Request $request, string $labOrder, LabBillingService $billing): Response
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
        // The AUTHORITATIVE total is the issued invoice's (a Billing-owned figure we only READ). Pre-invoice, the
        // page shows a client-side estimate from quantity × rate — the FENCE keeps every money math out of Lab.
        $invoice = $invoiceId === null ? null : Invoice::query()->find($invoiceId);

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
            // A charge exposes its code + quantity + the snapshotted RATE (unit price). Line/estimate math is
            // done presentationally in the Vue; the module computes no money.
            'charges' => $charges->map(fn (Charge $c): array => [
                'code' => $c->code,
                'description' => $c->description,
                'quantity' => $c->quantity,
                'unit_price_minor' => $c->unit_price_minor,
                'status' => $c->status,
            ])->all(),
            'tariffs' => $billing->catalogTariffs()->map(fn (TariffItem $t): array => [
                'code' => $t->code,
                'name' => $t->description,
                'unit_price_minor' => $t->unit_price_minor,
            ])->values()->all(),
            'invoice' => $invoice === null ? null : ['id' => $invoice->id, 'url' => route('billing.invoices.show', $invoice->id), 'total_minor' => $invoice->total_minor],
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
