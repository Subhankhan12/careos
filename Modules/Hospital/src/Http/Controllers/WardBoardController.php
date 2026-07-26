<?php

namespace Modules\Hospital\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Hospital\Exceptions\BedStatusTransitionException;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\Ward;
use Modules\Hospital\Services\BedService;
use Modules\Hospital\Services\WardService;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\User;

/**
 * The ward board (HOSPITAL.G3) — the live bed-occupancy cockpit. PRESENTATIONAL over the G1
 * bed model + G2 ADT domain (P0D.GU): it READS a ward's beds + their housekeeping status and
 * the current patient per occupied bed, and it SURFACES the existing ADT/bed actions (admit /
 * transfer / discharge via AdmissionService, set-status via BedService) — it computes no ADT
 * logic itself and never bypasses the atomicity/concurrency guarantees (admit-from-the-board
 * still POSTs to the proven AdmissionService::admit).
 *
 * It reuses the day-board's tile/status idiom for LAYOUT, but the data is beds/stays (continuous
 * occupancy), NOT appointments/slots — bed occupancy never touches the scheduling slot engine.
 *
 * ELECTRIC FENCE: the payload is OPERATIONAL ONLY — bed housekeeping status (free/occupied/
 * cleaning/blocked), the occupant's name + admitted_at (LOS-so-far is plain elapsed time the
 * client renders), and a plain occupancy count. There is NO acuity / severity / risk / priority /
 * deterioration field anywhere; status is housekeeping, never a clinical judgment.
 *
 * Read = `patient.view` (inpatient clinical staff — ward nurses included); the surfaced actions
 * carry their existing gates (admit/transfer/discharge = admission.manage, bed status = bed.manage).
 * String-id `{bed}` (FIX.1). Tenant + branch scoped.
 */
class WardBoardController
{
    public function show(Request $request, WardService $wards, BedService $beds): Response
    {
        Gate::authorize('patient.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $canManageBeds = Gate::allows('bed.manage');
        $canAdmit = Gate::allows('admission.manage');

        // The current occupant of each bed = the active stay pointing at it (one query, keyed by bed).
        $activeStays = Stay::query()
            ->where('status', Stay::STATUS_ADMITTED)
            ->with('patient')
            ->get()
            ->keyBy('current_bed_id');

        $board = $wards->activeWards()->map(function (Ward $ward) use ($beds, $activeStays, $canManageBeds): array {
            $wardBeds = $beds->forWard($ward)->where('active', true)->values();
            $occupied = 0;

            $tiles = $wardBeds->map(function (Bed $bed) use ($activeStays, $canManageBeds, &$occupied): array {
                $stay = $activeStays->get($bed->id);
                if ($stay !== null) {
                    $occupied++;
                }

                return [
                    'id' => $bed->id,
                    'label' => $bed->label,
                    'bed_type' => $bed->bed_type,
                    'status' => $bed->status, // housekeeping state — NOT a clinical judgment
                    'status_url' => $canManageBeds ? route('hospital.beds.status', $bed->id) : null,
                    'occupant' => $stay === null ? null : [
                        'stay_id' => $stay->id,
                        'patient' => trim($stay->patient->first_name.' '.$stay->patient->last_name),
                        'admitted_at' => $stay->admitted_at->toIso8601String(), // LOS = plain elapsed time client-side
                        'show_url' => route('hospital.admissions.show', $stay->id),
                        'transfer_url' => route('hospital.admissions.transfer', $stay->id),
                        'discharge_url' => route('hospital.admissions.discharge', $stay->id),
                    ],
                ];
            })->all();

            return [
                'id' => $ward->id,
                'name' => $ward->name,
                'code' => $ward->code,
                'beds' => $tiles,
                // A plain count of the state — NOT a rating.
                'summary' => ['occupied' => $occupied, 'total' => $wardBeds->count()],
            ];
        })->values()->all();

        return Inertia::render('Hospital/WardBoard', [
            'wards' => $board,
            'bedStatuses' => Bed::STATUSES,
            'actions' => [
                'can_admit' => $canAdmit,
                'can_manage_beds' => $canManageBeds,
                'admit_url' => route('hospital.admissions.store'),
                'admission_types' => Stay::ADMISSION_TYPES,
                'dispositions' => Stay::DISPOSITIONS,
                // The pickers the admit form needs — only when the actor can admit.
                'patients' => $canAdmit
                    ? Patient::query()->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                        ->map(fn (Patient $p): array => ['id' => $p->id, 'name' => trim($p->first_name.' '.$p->last_name)])->all()
                    : [],
                'clinicians' => $canAdmit
                    ? StaffProfile::query()->orderBy('display_name')->get(['id', 'display_name'])
                        ->map(fn (StaffProfile $s): array => ['id' => $s->id, 'name' => $s->display_name])->all()
                    : [],
            ],
        ]);
    }

    /**
     * Set a bed's housekeeping status (free / cleaning / blocked, via the legal transitions) — the
     * board-surfaced bed action. All logic (legal-only transition, row lock, audit) is in
     * BedService::setStatus (bed.manage enforced there). String-id {bed} (FIX.1).
     */
    public function setBedStatus(Request $request, string $bed, BedService $beds): RedirectResponse
    {
        Gate::authorize('bed.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'status' => ['required', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = Bed::query()->whereKey($bed)->firstOrFail();

        try {
            $beds->setStatus($actor, $record, $data['status'], $data['reason'] ?? null);
        } catch (BedStatusTransitionException $e) {
            return back()->withErrors(['bed' => $e->getMessage()]);
        }

        return redirect()->route('hospital.wards')->with('status', 'bed-updated');
    }
}
