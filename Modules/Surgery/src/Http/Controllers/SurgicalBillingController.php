<?php

namespace Modules\Surgery\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffItem;
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
    public function show(Request $request, string $case, SurgicalBillingService $billing): Response
    {
        Gate::authorize('billing.manage');
        abort_unless($request->user() instanceof User, 403);

        $record = SurgicalCase::query()->with('patient')->whereKey($case)->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        $chargeIds = SurgicalCaseCharge::query()->where('surgical_case_id', $record->id)->pluck('charge_id');
        $charges = Charge::query()->whereIn('id', $chargeIds->all())->orderBy('id')->get();
        $invoiceId = $charges->firstWhere('invoice_id', '!=', null)?->invoice_id;
        // The AUTHORITATIVE total is the issued invoice's (a Billing-owned figure we only READ). Pre-invoice,
        // the page shows a client-side estimate from quantity × rate — the FENCE keeps every money math out
        // of this module (no engine line-total is recomputed here).
        $invoice = $invoiceId === null ? null : Invoice::query()->find($invoiceId);

        return Inertia::render('Surgery/CaseBilling', [
            'surgicalCase' => [
                'id' => $record->id,
                'patient' => trim($record->patient->first_name.' '.$record->patient->last_name),
                'procedure' => $record->procedure_description,
                'status' => $record->status,
                'case_url' => route('surgery.cases.show', $record->id),
            ],
            // A charge exposes its code + quantity + the snapshotted RATE (unit price). The line/estimate math
            // is done presentationally in the Vue; the module computes no money.
            'charges' => $charges->map(fn (Charge $c): array => [
                'code' => $c->code,
                'description' => $c->description,
                'quantity' => $c->quantity,
                'unit_price_minor' => $c->unit_price_minor,
                'status' => $c->status,
            ])->all(),
            'procedures' => $billing->catalogTariffs()->where('unit', 'procedure')->map(fn (TariffItem $t): array => [
                'code' => $t->code, 'name' => $t->description,
            ])->values()->all(),
            'invoice' => $invoice === null ? null : ['id' => $invoice->id, 'url' => route('billing.invoices.show', $invoice->id), 'total_minor' => $invoice->total_minor],
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
