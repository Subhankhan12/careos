<?php

namespace Modules\Pharmacy\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\Patient;
use Modules\Pharmacy\Exceptions\MedicationOrderException;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Pharmacy\Models\MedicationOrder;
use Modules\Pharmacy\Services\MedicationOrderService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * Medication orders for a patient (PHARMACY.G2) — PRESENTATIONAL over `MedicationOrderService`. Place an
 * order (clinician-authored dose/route/frequency/PRN), see active orders + history, hold/discontinue.
 * String-id params (FIX.1). Read `patient.view` (read-logged); prescribing/transitions `medication.prescribe`.
 *
 * The `alerts` block is wired to the safety seam's `SafetyResult` — EMPTY today (the null-object asserts
 * nothing); a future certified partner would populate it, ADVISORY + human-owned, never auto-blocking.
 */
class MedicationOrderController
{
    public function index(Request $request, string $patient, MedicationOrderService $orders): Response
    {
        Gate::authorize('patient.view');
        abort_unless($request->user() instanceof User, 403);

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        return Inertia::render('Pharmacy/MedicationOrders', [
            'patient' => [
                'id' => $record->id,
                'name' => trim($record->first_name.' '.$record->last_name),
            ],
            'active' => $orders->activeForPatient($record)->map($this->present(...))->all(),
            'history' => $orders->historyForPatient($record)->map($this->present(...))->all(),
            // The safety seam's advisory result — EMPTY today (null-object). Wired for a future partner.
            'alerts' => $orders->safetyReview($record)->alerts,
            'formulary' => FormularyItem::query()->where('active', true)->orderBy('name')->get()
                ->map(fn (FormularyItem $item): array => [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'strength' => $item->strength,
                ])->all(),
            'routes' => MedicationOrder::ROUTES,
            'actions' => [
                'can_prescribe' => Gate::allows('medication.prescribe'),
                'store_url' => route('pharmacy.patient-medications.store', $record->id),
            ],
        ]);
    }

    public function store(Request $request, string $patient, MedicationOrderService $orders): RedirectResponse
    {
        Gate::authorize('medication.prescribe');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'formulary_item_id' => ['required', 'string'],
            'dose_amount' => ['required', 'string', 'max:60'],
            'dose_unit' => ['required', 'string', 'max:40'],
            'route' => ['required', 'string', 'in:'.implode(',', MedicationOrder::ROUTES)],
            'frequency' => ['required', 'string', 'max:60'],
            'starts_at' => ['nullable', 'date'],
            'stops_at' => ['nullable', 'date'],
            'prn' => ['boolean'],
            'prn_reason' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:2000'],
            'stay_id' => ['nullable', 'string'],
        ]);

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $item = FormularyItem::query()->whereKey($data['formulary_item_id'])->firstOrFail();
        if (! $item->active) {
            return back()->withErrors(['formulary_item_id' => 'That formulary item is inactive.']);
        }

        try {
            $orders->prescribe($actor, $record, $item, $data, $data['stay_id'] ?? null);
        } catch (MedicationOrderException|CrossTenantReferenceException $e) {
            return back()->withErrors(['medication_order' => $e->getMessage()]);
        }

        return redirect()->route('pharmacy.patient-medications', $record->id)->with('status', 'medication-ordered');
    }

    public function transition(Request $request, string $order, MedicationOrderService $orders): RedirectResponse
    {
        Gate::authorize('medication.prescribe');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', MedicationOrder::STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = MedicationOrder::query()->whereKey($order)->firstOrFail();

        try {
            $orders->transition($actor, $record, $data['status'], $data['reason'] ?? null);
        } catch (MedicationOrderException|CrossTenantReferenceException $e) {
            return back()->withErrors(['medication_order' => $e->getMessage()]);
        }

        return redirect()->route('pharmacy.patient-medications', $record->patient_id)->with('status', 'medication-updated');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MedicationOrder $order): array
    {
        return [
            'id' => $order->id,
            'code' => $order->formularyItem?->code,
            'name' => $order->formularyItem?->name,
            'dose' => trim($order->dose_amount.' '.$order->dose_unit),
            'route' => $order->route,
            'frequency' => $order->frequency,
            'prn' => $order->prn,
            'prn_reason' => $order->prn_reason,
            'note' => $order->note,
            'status' => $order->status,
            'status_reason' => $order->status_reason,
            'starts_at' => $order->starts_at->toIso8601String(),
            'stops_at' => $order->stops_at?->toIso8601String(),
            'transition_url' => route('pharmacy.medication-orders.transition', $order->id),
        ];
    }
}
