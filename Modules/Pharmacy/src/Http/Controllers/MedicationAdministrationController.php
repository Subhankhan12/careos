<?php

namespace Modules\Pharmacy\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\Patient;
use Modules\Pharmacy\Exceptions\MedicationAdministrationException;
use Modules\Pharmacy\Models\MedicationAdministration;
use Modules\Pharmacy\Models\MedicationOrder;
use Modules\Pharmacy\Services\MedicationAdministrationService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * The eMAR (PHARMACY.G3) — PRESENTATIONAL over `MedicationAdministrationService`. From a patient, the due
 * medications (a FACTUAL worklist of active G2 orders) + the administration history (the MAR); record each
 * dose as given / held / refused. String-id params (FIX.1). Read `patient.view` (read-logged); recording
 * `note.write` (the nursing clinical-write permission the ward nurse holds — the G5 handover precedent).
 *
 * The `alerts` block is wired to the safety seam's `SafetyResult` at the ADMINISTRATION point — EMPTY today
 * (the null-object asserts nothing); a future certified partner would populate it, ADVISORY + human-owned.
 */
class MedicationAdministrationController
{
    public function index(Request $request, string $patient, MedicationAdministrationService $emar): Response
    {
        Gate::authorize('patient.view');
        abort_unless($request->user() instanceof User, 403);

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        return Inertia::render('Pharmacy/Emar', [
            'patient' => [
                'id' => $record->id,
                'name' => trim($record->first_name.' '.$record->last_name),
            ],
            // The FACTUAL due worklist: the patient's ACTIVE orders (not a computed priority).
            'due' => $emar->dueForPatient($record)->map(fn (MedicationOrder $order): array => [
                'id' => $order->id,
                'name' => $order->formularyItem->name,
                'dose' => trim($order->dose_amount.' '.$order->dose_unit),
                'route' => $order->route,
                'frequency' => $order->frequency,
                'prn' => $order->prn,
                'administer_url' => route('pharmacy.medication-orders.administer', $order->id),
            ])->all(),
            // The MAR — every recorded administration, newest first (a raw record, no computed grade).
            'history' => $emar->forPatient($record)->map(fn (MedicationAdministration $a): array => [
                'id' => $a->id,
                'name' => $a->order->formularyItem->name,
                'outcome' => $a->outcome,
                'dose' => trim(($a->dose_amount ?? '').' '.($a->dose_unit ?? '')),
                'scheduled_at' => $a->scheduled_at?->toIso8601String(),
                'administered_at' => $a->administered_at->toIso8601String(),
                'reason' => $a->reason,
            ])->all(),
            // The safety seam's advisory result at administration — EMPTY today (null-object).
            'alerts' => $emar->safetyReview($record)->alerts,
            'outcomes' => MedicationAdministration::OUTCOMES,
            'actions' => [
                'can_administer' => Gate::allows('note.write'),
            ],
        ]);
    }

    public function record(Request $request, string $order, MedicationAdministrationService $emar): RedirectResponse
    {
        Gate::authorize('note.write');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'outcome' => ['required', 'string', 'in:'.implode(',', MedicationAdministration::OUTCOMES)],
            'dose_amount' => ['nullable', 'string', 'max:60'],
            'dose_unit' => ['nullable', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:500'],
            'scheduled_at' => ['nullable', 'date'],
            'administered_at' => ['nullable', 'date'],
        ]);

        $record = MedicationOrder::query()->whereKey($order)->firstOrFail();

        try {
            $emar->record($actor, $record, $data);
        } catch (MedicationAdministrationException|CrossTenantReferenceException $e) {
            return back()->withErrors(['administration' => $e->getMessage()]);
        }

        return redirect()->route('pharmacy.patient-emar', $record->patient_id)->with('status', 'medication-administered');
    }
}
