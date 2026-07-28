<?php

namespace Modules\ED\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffItem;
use Modules\ED\Exceptions\EdBillingException;
use Modules\ED\Models\EdVisit;
use Modules\ED\Models\EdVisitCharge;
use Modules\ED\Services\EdBillingService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * ED billing (ED.G6) — PRESENTATIONAL over `EdBillingService`. From a visit, set tenant-authored prices
 * (attendance + services), capture the charges through the EXISTING engine, and issue an invoice that
 * reconciles-to-the-unit (a DISCHARGED patient). For an ADMITTED patient the ED charges join the inpatient
 * stay's discharge invoice via the existing HOSPITAL.G6 flow (no visit-level invoice). Gated `billing.manage`
 * (the billing office — NOT the ED team); the visit read is read-logged. String-id (FIX.1). NO money math (the
 * engine owns pricing/line-totals). The attendance fee is a plain tariff — NOT acuity-driven (the fence).
 */
class EdBillingController
{
    public function show(Request $request, string $visit, EdBillingService $billing): Response
    {
        Gate::authorize('billing.manage');
        abort_unless($request->user() instanceof User, 403);

        $record = EdVisit::query()->with('patient')->whereKey($visit)->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        $chargeIds = EdVisitCharge::query()->where('ed_visit_id', $record->id)->pluck('charge_id');
        $charges = Charge::query()->whereIn('id', $chargeIds->all())->orderBy('id')->get();
        $invoiceId = $charges->firstWhere('invoice_id', '!=', null)?->invoice_id;
        // The AUTHORITATIVE total is the issued invoice's (a Billing-owned figure we only READ). Pre-invoice, the
        // page shows a client-side estimate from quantity × rate — the FENCE keeps every money math out of this
        // module (no engine line-total is recomputed here).
        $invoice = $invoiceId === null ? null : Invoice::query()->find($invoiceId);

        return Inertia::render('ED/Billing', [
            'visit' => [
                'id' => $record->id,
                'patient' => trim($record->patient->first_name.' '.$record->patient->last_name),
                'chief_complaint' => $record->chief_complaint,
                'status' => $record->status,
                'disposition' => $record->disposition,
                // Admitted → the ED charges join the inpatient stay's discharge invoice (no visit-level invoice).
                'admitted' => $record->stay_id !== null,
                'record_url' => route('ed.visits.record.show', $record->id),
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
                'unit' => $t->unit,
                'unit_price_minor' => $t->unit_price_minor,
                'is_attendance' => $t->code === EdBillingService::ATTENDANCE_CODE,
            ])->values()->all(),
            'invoice' => $invoice === null ? null : ['id' => $invoice->id, 'url' => route('billing.invoices.show', $invoice->id), 'total_minor' => $invoice->total_minor],
            'actions' => [
                'can_bill' => Gate::allows('billing.manage'),
                'attendance_code' => EdBillingService::ATTENDANCE_CODE,
                'price_attendance_url' => route('ed.billing.price-attendance', $record->id),
                'price_service_url' => route('ed.billing.price-service', $record->id),
                'charge_url' => route('ed.billing.charge', $record->id),
                'invoice_url' => route('ed.billing.invoice', $record->id),
            ],
        ]);
    }

    public function priceAttendance(Request $request, string $visit, EdBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate(['price_minor' => ['required', 'integer', 'min:1']]);
        EdVisit::query()->whereKey($visit)->firstOrFail(); // resolve for the redirect (string-id)

        try {
            $billing->priceAttendance($actor, $data['price_minor']);
        } catch (EdBillingException $e) {
            return back()->withErrors(['ed_billing' => $e->getMessage()]);
        }

        return redirect()->route('ed.billing.show', $visit)->with('status', 'ed-attendance-priced');
    }

    public function priceService(Request $request, string $visit, EdBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:120'],
            'price_minor' => ['required', 'integer', 'min:1'],
        ]);
        EdVisit::query()->whereKey($visit)->firstOrFail();

        try {
            $billing->priceService($actor, $data['code'], $data['name'], $data['price_minor']);
        } catch (EdBillingException $e) {
            return back()->withErrors(['ed_billing' => $e->getMessage()]);
        }

        return redirect()->route('ed.billing.show', $visit)->with('status', 'ed-service-priced');
    }

    public function charge(Request $request, string $visit, EdBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'attendance' => ['nullable', 'boolean'],
            'service_codes' => ['nullable', 'array'],
            'service_codes.*' => ['string', 'max:60'],
        ]);

        $record = EdVisit::query()->whereKey($visit)->firstOrFail();

        try {
            $billing->chargeVisit($actor, $record, $data['attendance'] ?? true, $data['service_codes'] ?? []);
        } catch (EdBillingException|CrossTenantReferenceException $e) {
            return back()->withErrors(['ed_billing' => $e->getMessage()]);
        }

        return redirect()->route('ed.billing.show', $record->id)->with('status', 'ed-visit-charged');
    }

    public function invoice(Request $request, string $visit, EdBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = EdVisit::query()->whereKey($visit)->firstOrFail();

        try {
            $issued = $billing->invoiceVisit($actor, $record);
        } catch (EdBillingException|CrossTenantReferenceException $e) {
            return back()->withErrors(['ed_billing' => $e->getMessage()]);
        }

        return redirect()->route('billing.invoices.show', $issued->id)->with('status', 'ed-visit-invoiced');
    }
}
