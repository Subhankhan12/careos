<?php

namespace Modules\ED\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\ED\Contracts\TriageAcuityProvider;
use Modules\ED\Exceptions\EdVisitException;
use Modules\ED\Models\EdVisit;
use Modules\ED\Models\EdVisitEvent;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * The ED patient-flow entity (ED.G1). Registering an arrival and advancing the visit through its LEGAL-ONLY
 * lifecycle are gated `ed.manage`. Each step records a factual timestamp/outcome and writes one append-only
 * {@see EdVisitEvent}; the audit follows via the event's `created` hook (app-layer), so ED stays free of
 * Audit.
 *
 * ELECTRIC FENCE: the service records what a human enters — it computes NO acuity / priority / severity. The
 * arrival mode + disposition are operational facts the ED clinician records. Acuity is NOT recorded here at
 * all: the triage nurse ASSIGNS it in a separate triage record (ED.G2). A computed triage acuity is the fence
 * line (map §3) and lives only behind the empty {@see TriageAcuityProvider} seam.
 */
class EdVisitService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Register an ED arrival — create the visit at `arrived` and write the append-only `arrived` flow event in
     * one transaction. Gated `ed.manage`; tenant fail-closed on patient + branch; the arrival mode and
     * presenting complaint are validated (a complaint is required at arrival).
     */
    public function register(
        User $actor,
        Patient $patient,
        Branch $branch,
        string $arrivalMode,
        string $chiefComplaint,
        ?Carbon $arrivedAt = null,
    ): EdVisit {
        Gate::forUser($actor)->authorize('ed.manage');
        $this->assertSameTenant($patient->tenant_id, 'patient_id', $patient->id);
        $this->assertSameTenant($branch->tenant_id, 'branch_id', $branch->id);

        $arrivedAt ??= Carbon::now();

        return DB::transaction(function () use ($patient, $branch, $arrivalMode, $chiefComplaint, $arrivedAt, $actor): EdVisit {
            $visit = EdVisit::query()->create([
                'patient_id' => $patient->id,
                'branch_id' => $branch->id,
                'arrived_at' => $arrivedAt,
                'arrival_mode' => $arrivalMode,
                'chief_complaint' => $chiefComplaint,
            ]);

            EdVisitEvent::query()->create([
                'patient_id' => $visit->patient_id,
                'ed_visit_id' => $visit->id,
                'event_type' => EdVisitEvent::TYPE_ARRIVED,
                'reason' => null,
                'performed_by' => $actor->id,
                'occurred_at' => $arrivedAt,
            ]);

            return $visit->refresh();
        });
    }

    /**
     * Advance the visit through its LEGAL-ONLY lifecycle. Gated `ed.manage`; an illegal move throws
     * {@see EdVisitException::invalidTransition}. Moving to `dispositioned` REQUIRES a disposition
     * (admit / discharge / transfer) — a recorded operational fact (the ED→ADT admit handoff detail is ED.G5);
     * a disposition on any other transition is rejected. An append-only {@see EdVisitEvent} is written; the
     * audit follows via the event's `created` hook.
     */
    public function transition(
        User $actor,
        EdVisit $visit,
        string $toStatus,
        ?string $reason = null,
        ?string $disposition = null,
    ): EdVisit {
        Gate::forUser($actor)->authorize('ed.manage');
        $this->assertSameTenant($visit->tenant_id, 'ed_visit_id', $visit->id);

        if (! EdVisit::canTransition($visit->status, $toStatus)) {
            throw EdVisitException::invalidTransition($visit->status, $toStatus);
        }

        if ($toStatus === EdVisit::STATUS_DISPOSITIONED) {
            if ($disposition === null) {
                throw EdVisitException::dispositionRequired();
            }
            if (! in_array($disposition, EdVisit::DISPOSITIONS, true)) {
                throw EdVisitException::invalidDisposition($disposition);
            }
        } elseif ($disposition !== null) {
            throw EdVisitException::dispositionNotAllowed();
        }

        return DB::transaction(function () use ($visit, $toStatus, $reason, $disposition, $actor): EdVisit {
            $attributes = ['status' => $toStatus];
            if ($toStatus === EdVisit::STATUS_DISPOSITIONED) {
                $attributes['disposition'] = $disposition;
                $attributes['dispositioned_at'] = Carbon::now();
            }
            $visit->forceFill($attributes)->save();

            EdVisitEvent::query()->create([
                'patient_id' => $visit->patient_id,
                'ed_visit_id' => $visit->id,
                'event_type' => $toStatus, // 1:1 with the status the visit is now in
                'reason' => $reason,
                'performed_by' => $actor->id,
                'occurred_at' => Carbon::now(),
            ]);

            return $visit->refresh();
        });
    }

    /**
     * @return Collection<int, EdVisit>
     */
    public function forPatient(Patient $patient): Collection
    {
        return EdVisit::query()
            ->where('patient_id', $patient->id)
            ->orderByDesc('arrived_at')
            ->get();
    }

    /**
     * The department's ACTIVE ED visits (not yet dispositioned / not LWBS) for the tracking board (ED.G3),
     * with the patient, branch, and the latest triage (the RECORDED acuity). Ordered by ARRIVAL — a plain
     * fact; the board never computes a priority ranking (the ward-board idiom, over the EdVisit flow state).
     *
     * @return Collection<int, EdVisit>
     */
    public function activeVisits(): Collection
    {
        return EdVisit::query()
            ->whereIn('status', [
                EdVisit::STATUS_ARRIVED, EdVisit::STATUS_TRIAGED,
                EdVisit::STATUS_IN_TREATMENT, EdVisit::STATUS_AWAITING_DISPOSITION,
            ])
            ->with(['patient', 'branch', 'latestTriage.triagedBy'])
            ->orderBy('arrived_at')
            ->get();
    }

    private function assertSameTenant(?string $tenantId, string $attribute, string $id): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
