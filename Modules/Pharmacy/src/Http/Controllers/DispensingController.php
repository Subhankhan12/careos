<?php

namespace Modules\Pharmacy\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\Patient;
use Modules\Pharmacy\Exceptions\DispensingException;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Models\MedicationOrder;
use Modules\Pharmacy\Services\DispensingService;
use Modules\Pharmacy\Services\MedicationOrderService;
use Modules\Pharmacy\Services\PharmacyBillingService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Throwable;

/**
 * Dispensing against a patient's medication orders (PHARMACY.G4) — PRESENTATIONAL over `DispensingService`.
 * The patient's active orders (with on-hand) + the dispensing history; dispense decrements stock. String-id
 * (FIX.1). Read `patient.view` (read-logged); dispensing `dispense.manage`.
 */
class DispensingController
{
    public function index(Request $request, string $patient, DispensingService $dispensing, MedicationOrderService $orders): Response
    {
        Gate::authorize('patient.view');
        abort_unless($request->user() instanceof User, 403);

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        return Inertia::render('Pharmacy/Dispensing', [
            'patient' => [
                'id' => $record->id,
                'name' => trim($record->first_name.' '.$record->last_name),
            ],
            'orders' => $orders->activeForPatient($record)->map(fn (MedicationOrder $order): array => [
                'id' => $order->id,
                'name' => $order->formularyItem->name,
                'dose' => trim($order->dose_amount.' '.$order->dose_unit),
                'on_hand' => $dispensing->onHandForItem($order->formulary_item_id),
                'dispense_url' => route('pharmacy.medication-orders.dispense', $order->id),
            ])->all(),
            'history' => $dispensing->historyForPatient($record)->map(fn (Dispense $d): array => [
                'id' => $d->id,
                'name' => $d->formularyItem->name,
                'quantity' => $d->quantity,
                'dispensed_at' => $d->dispensed_at->toIso8601String(),
            ])->all(),
            'actions' => [
                'can_dispense' => Gate::allows('dispense.manage'),
            ],
        ]);
    }

    public function dispense(Request $request, string $order, DispensingService $dispensing, PharmacyBillingService $billing): RedirectResponse
    {
        Gate::authorize('dispense.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $record = MedicationOrder::query()->whereKey($order)->firstOrFail();

        try {
            $dispense = $dispensing->dispense($actor, $record, (int) $data['quantity']);
        } catch (DispensingException|CrossTenantReferenceException $e) {
            return back()->withErrors(['dispense' => $e->getMessage()]);
        }

        // PHARMACY.G5: accrue the Billing charge for the dispense through the existing engine (a priced med
        // becomes a Charge). BEST-EFFORT + decoupled from the concurrency-critical dispense — the dispense
        // already committed; an unpriced med or a billing hiccup just leaves it uncharged (reconcilable later).
        try {
            $billing->chargeForDispense($actor, $dispense);
        } catch (Throwable) {
            // best-effort billing; a charge failure must never block the (completed) dispense.
        }

        return redirect()->route('pharmacy.patient-dispensing', $record->patient_id)->with('status', 'medication-dispensed');
    }
}
