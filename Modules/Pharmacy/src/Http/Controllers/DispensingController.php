<?php

namespace Modules\Pharmacy\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\Patient;
use Modules\Pharmacy\Exceptions\DispensingException;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Models\MedicationOrder;
use Modules\Pharmacy\Services\DispensingService;
use Modules\Pharmacy\Services\MedicationOrderService;
use Modules\Pharmacy\Services\PharmacyBillingService;
use Modules\Pharmacy\Support\PatientSafetyRecord;
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
    public function index(Request $request, string $patient, DispensingService $dispensing, MedicationOrderService $orders, PatientSafetyRecord $safety): Response
    {
        Gate::authorize('patient.view');
        abort_unless($request->user() instanceof User, 403);

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $record->auditRead(); // patient-scoped read log — ONE per render; the safety record adds none

        return Inertia::render('Pharmacy/Dispensing', [
            'patient' => [
                'id' => $record->id,
                'name' => trim($record->first_name.' '.$record->last_name),
            ],
            // QA-FIX.5a (P5-C1): the RECORDED allergies and the medication-safety seam, beside the
            // action that releases the drug. Facts only — nothing here compares the list against what
            // is being dispensed, because that comparison is the certified-partner judgment the fence
            // forbids. The seam renders even when the list is empty, so an absence of records can
            // never read as a clearance.
            ...$safety->forPatient($record),
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
                // QA-FIX.5b (P5-C2 / P5-M4): whether this dispense carries a billing charge. A FACT
                // read from the ledger, not a judgment about why — an unpriced medication and a
                // not-permitted actor both land here, and the screen says only that it is unbilled.
                'charged' => $d->charge !== null,
            ])->all(),
            // The count of this patient's dispenses with no charge, so an unbilled dispense is visible
            // where it happened instead of being discoverable only by a database query.
            'uncharged_count' => Dispense::query()
                ->where('patient_id', $record->id)
                ->uncharged()
                ->count(),
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

        // PHARMACY.G5: accrue the Billing charge through the existing engine (a priced med becomes a
        // Charge). Still BEST-EFFORT and still decoupled from the concurrency-critical dispense: the
        // dispense has committed, the drug has physically left the shelf, and a billing problem must
        // never unwind it. That property is deliberate and is asserted by a test.
        //
        // WHAT CHANGED (QA-FIX.5b, P5-C2, D-207): this used to be `catch (Throwable) { }` — an EMPTY
        // catch that swallowed an AUTHORIZATION failure exactly like a transient hiccup. A
        // `pharmacy_technician` deliberately lacks `billing.manage`, so EVERY dispense by the role whose
        // primary job is dispensing produced no charge, with nothing recorded and nothing on screen.
        //
        // The two failures are now distinguished and BOTH are recorded rather than discarded. Neither
        // blocks the dispense, and the uncharged dispense itself is findable via
        // `Dispense::query()->uncharged()`, surfaced on this screen.
        try {
            $billing->chargeForDispense($actor, $dispense);
        } catch (AuthorizationException $e) {
            // EXPECTED for a role without `billing.manage` — a policy outcome, not a fault. Recorded so
            // the unbilled dispense has a trace; the permission boundary itself is left intact
            // deliberately (see D-207 for the branch decision and what changing it would require).
            Log::info('pharmacy.dispense.uncharged.not_permitted', [
                'dispense_id' => $dispense->id,
                'actor_id' => $actor->id,
                'reason' => 'actor lacks billing.manage',
            ]);
        } catch (Throwable $e) {
            // A genuine transient/unexpected billing failure. Still must not unwind the dispense — but
            // it is no longer discarded.
            Log::warning('pharmacy.dispense.uncharged.billing_failed', [
                'dispense_id' => $dispense->id,
                'actor_id' => $actor->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return redirect()->route('pharmacy.patient-dispensing', $record->patient_id)->with('status', 'medication-dispensed');
    }
}
