<?php

namespace Modules\Hospital\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Hospital\Exceptions\BedNotAvailableException;
use Modules\Hospital\Exceptions\BedStatusTransitionException;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\StayEvent;
use Modules\Hospital\Services\AdmissionService;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\User;

/**
 * The minimal ADT action surface (HOSPITAL.G2) — admit / transfer / discharge + a read view of
 * one admission and its bed journey. PRESENTATIONAL (P0D.GU): all lifecycle/atomicity/tenant/
 * audit logic lives in AdmissionService; this only resolves inputs and dispatches. The rich ward
 * board is HOSPITAL.G3. String-id params (FIX.1 → cross-tenant/missing 404). Reads gate on
 * `patient.view` (a ward nurse views an admission); writes on `admission.manage`.
 */
class AdmissionController
{
    public function show(Request $request, string $stay): Response
    {
        Gate::authorize('patient.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = Stay::query()->whereKey($stay)->with(['patient', 'currentBed', 'currentWard', 'admittingClinician'])->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        $events = StayEvent::query()->where('stay_id', $record->id)->orderBy('occurred_at')->orderBy('id')->get();

        return Inertia::render('Hospital/Admission', [
            'stay' => [
                'id' => $record->id,
                'patient' => trim($record->patient->first_name.' '.$record->patient->last_name),
                'status' => $record->status,
                'admission_type' => $record->admission_type,
                'admission_reason' => $record->admission_reason,
                'admitting_clinician' => trim($record->admittingClinician->first_name.' '.$record->admittingClinician->last_name),
                'bed' => $record->currentBed?->label,
                'ward' => $record->currentWard?->name,
                'admitted_at' => $record->admitted_at->toIso8601String(),
                'discharged_at' => $record->discharged_at?->toIso8601String(),
                'discharge_disposition' => $record->discharge_disposition,
            ],
            'journey' => $events->map(fn (StayEvent $e): array => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'bed_id' => $e->bed_id,
                'from_bed_id' => $e->from_bed_id,
                'reason' => $e->reason,
                'disposition' => $e->disposition,
                'occurred_at' => $e->occurred_at->toIso8601String(),
            ])->all(),
            'actions' => [
                'can_manage' => Gate::allows('admission.manage'),
                'transfer_url' => route('hospital.admissions.transfer', $record->id),
                'discharge_url' => route('hospital.admissions.discharge', $record->id),
            ],
        ]);
    }

    public function store(Request $request, AdmissionService $admissions): RedirectResponse
    {
        Gate::authorize('admission.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'patient_id' => ['required', 'string'],
            'bed_id' => ['required', 'string'],
            'admitting_clinician_id' => ['required', 'string'],
            'admission_type' => ['required', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $patient = Patient::query()->whereKey($data['patient_id'])->firstOrFail();
        $bed = Bed::query()->whereKey($data['bed_id'])->firstOrFail();
        $clinician = StaffProfile::query()->whereKey($data['admitting_clinician_id'])->firstOrFail();

        try {
            $stay = $admissions->admit($actor, $patient, $bed, $clinician, $data['admission_type'], $data['reason'] ?? null);
        } catch (AdmissionException|BedNotAvailableException $e) {
            return back()->withErrors(['admission' => $e->getMessage()]);
        }

        return redirect()->route('hospital.admissions.show', $stay->id)->with('status', 'admitted');
    }

    public function transfer(Request $request, string $stay, AdmissionService $admissions): RedirectResponse
    {
        Gate::authorize('admission.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'bed_id' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = Stay::query()->whereKey($stay)->firstOrFail();
        $newBed = Bed::query()->whereKey($data['bed_id'])->firstOrFail();

        try {
            $admissions->transfer($actor, $record, $newBed, $data['reason'] ?? null);
        } catch (AdmissionException|BedNotAvailableException|BedStatusTransitionException $e) {
            return back()->withErrors(['transfer' => $e->getMessage()]);
        }

        return redirect()->route('hospital.admissions.show', $record->id)->with('status', 'transferred');
    }

    public function discharge(Request $request, string $stay, AdmissionService $admissions): RedirectResponse
    {
        Gate::authorize('admission.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'disposition' => ['required', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = Stay::query()->whereKey($stay)->firstOrFail();

        try {
            $admissions->discharge($actor, $record, $data['disposition'], $data['reason'] ?? null);
        } catch (AdmissionException|BedStatusTransitionException $e) {
            return back()->withErrors(['discharge' => $e->getMessage()]);
        }

        return redirect()->route('hospital.admissions.show', $record->id)->with('status', 'discharged');
    }
}
