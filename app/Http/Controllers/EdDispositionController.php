<?php

namespace App\Http\Controllers;

use App\Services\EdDispositionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ED\Exceptions\EdVisitException;
use Modules\ED\Models\EdVisit;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Hospital\Exceptions\BedNotAvailableException;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\Ward;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * ED disposition + the ED→ADT handoff (ED.G5) — an APP-LAYER controller (composes the ED flow + inpatient
 * admission). PRESENTATIONAL over {@see EdDispositionService}: it records the clinician's disposition
 * decision (admit / discharge / transfer). ADMIT reuses the EXISTING AdmissionService to create an inpatient
 * Stay (admission_type=emergency) — atomically with the disposition. String-id `{visit}` (FIX.1).
 *
 * Read = `ed.manage` (ED staff); the disposition write = `ed.manage`, and ADMIT additionally requires
 * `admission.manage` (enforced in the service + AdmissionService). ELECTRIC FENCE: the disposition is the
 * clinician's recorded DECISION — nothing is computed/suggested; nothing auto-decides.
 */
class EdDispositionController extends Controller
{
    public function show(Request $request, string $visit): Response
    {
        Gate::authorize('ed.manage');
        abort_unless($request->user() instanceof User, 403);

        $record = EdVisit::query()->whereKey($visit)->with('patient')->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        $canAdmit = Gate::allows('admission.manage');

        // The free beds (admit target) — resolved with a typed Ward map for the label (Bed's ward relation is
        // untyped from here; the ward-board pattern).
        $freeBeds = $canAdmit
            ? Bed::query()->where('status', Bed::STATUS_FREE)->where('active', true)->orderBy('label')->get()
            : Bed::query()->whereRaw('1 = 0')->get();
        $wardNames = Ward::query()->whereKey($freeBeds->pluck('ward_id')->filter()->unique()->all())->get()->keyBy('id');

        // The linked inpatient Stay, if this visit was admitted (a SOFT ref — loaded app-layer).
        $stay = $record->stay_id === null ? null : Stay::query()->with(['currentBed', 'currentWard'])->find($record->stay_id);

        return Inertia::render('ED/Disposition', [
            'visit' => [
                'id' => $record->id,
                'patient' => trim($record->patient->first_name.' '.$record->patient->last_name),
                'status' => $record->status,
                'chief_complaint' => $record->chief_complaint,
                'disposition' => $record->disposition,
                'dispositioned_at' => $record->dispositioned_at?->toIso8601String(),
            ],
            'can_dispose' => $record->status === EdVisit::STATUS_AWAITING_DISPOSITION,
            'dispositions' => EdVisit::DISPOSITIONS,
            'stay' => $stay === null ? null : [
                'id' => $stay->id,
                'ward' => $stay->currentWard?->name,
                'bed' => $stay->currentBed?->label,
                'admitted_at' => $stay->admitted_at->toIso8601String(),
                'admission_type' => $stay->admission_type,
            ],
            'actions' => [
                'can_admit' => $canAdmit,
                'dispose_url' => route('ed.visits.disposition.store', $record->id),
                'record_url' => route('ed.visits.record.show', $record->id),
                // The free beds (admit target) + clinicians — only when the actor can admit.
                'beds' => $freeBeds->map(function (Bed $b) use ($wardNames): array {
                    $ward = $wardNames->get($b->ward_id);
                    $wardName = $ward === null ? '' : $ward->name;

                    return ['id' => $b->id, 'label' => trim($wardName.' · '.$b->label)];
                })->all(),
                'clinicians' => $canAdmit
                    ? StaffProfile::query()->orderBy('display_name')->limit(200)->get(['id', 'display_name'])
                        ->map(fn (StaffProfile $s): array => ['id' => $s->id, 'name' => (string) $s->display_name])->all()
                    : [],
            ],
        ]);
    }

    public function store(Request $request, string $visit, EdDispositionService $disposition): RedirectResponse
    {
        Gate::authorize('ed.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'disposition' => ['required', 'string', 'in:'.implode(',', EdVisit::DISPOSITIONS)],
            'note' => ['nullable', 'string', 'max:500'],
            // Required only for ADMIT.
            'bed_id' => ['nullable', 'string'],
            'clinician_id' => ['nullable', 'string'],
        ]);

        $record = EdVisit::query()->whereKey($visit)->firstOrFail();

        try {
            if ($data['disposition'] === EdVisit::DISPOSITION_ADMIT) {
                $request->validate([
                    'bed_id' => ['required', 'string'],
                    'clinician_id' => ['required', 'string'],
                ]);
                $bed = Bed::query()->whereKey($data['bed_id'])->firstOrFail();
                $clinician = StaffProfile::query()->whereKey($data['clinician_id'])->firstOrFail();
                $disposition->admit($actor, $record, $bed, $clinician, $data['note'] ?? null);
            } elseif ($data['disposition'] === EdVisit::DISPOSITION_DISCHARGE) {
                $disposition->discharge($actor, $record, $data['note'] ?? null);
            } else {
                $disposition->transferOut($actor, $record, $data['note'] ?? null);
            }
        } catch (EdVisitException|AdmissionException|BedNotAvailableException|CrossTenantReferenceException $e) {
            return back()->withErrors(['disposition' => $e->getMessage()]);
        }

        return redirect()->route('ed.visits.disposition.show', $record->id)->with('status', 'ed-dispositioned');
    }
}
