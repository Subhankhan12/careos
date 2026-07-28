<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\ED\Models\EdVisit;
use Modules\ED\Services\EdVisitService;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Services\AdmissionService;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\User;

/**
 * ED disposition + the ED→ADT handoff (ED.G5) — an APP-LAYER composer. It lives in app/ (NOT in Modules\ED)
 * because it composes TWO verticals: the ED flow ({@see EdVisitService}, Modules\ED) and inpatient admission
 * ({@see AdmissionService}, Modules\Hospital). The arch boundary keeps Modules\ED independent of Hospital; the
 * cross-vertical composition is app-layer (the AppServiceProvider audit-composition / BranchController
 * precedent, AGENTS.md:93-96), and the ED↔Stay association is a SOFT `stay_id` (no FK/relation).
 *
 * discharge / transfer-out close the visit with the recorded disposition — NO Stay. ADMIT is the signature
 * reuse (map §2.3): it creates a Phase-1 inpatient Stay via the EXISTING, concurrency-safe, atomic
 * `AdmissionService::admit` (`admission_type = emergency`) — admission is REUSED, never reimplemented or
 * modified — and records the disposition + the soft `stay_id` ATOMICALLY: the admit + the disposition happen
 * in ONE transaction, so a failure rolls back BOTH (never a dispositioned-admit visit without its Stay, never
 * a Stay without the disposition).
 *
 * ELECTRIC FENCE: the disposition is the clinician's recorded DECISION (admit / discharge / transfer) — the
 * system computes NOTHING (no admit-probability, no discharge-risk, no suggested disposition). Nothing
 * auto-decides; a human owns the decision, this only records it and reuses the proven admit.
 */
class EdDispositionService
{
    public function __construct(
        private readonly EdVisitService $visits,
        private readonly AdmissionService $admissions,
    ) {}

    /** Discharge the patient home — the recorded disposition; NO Stay. Gated `ed.manage` (via the transition). */
    public function discharge(User $actor, EdVisit $visit, ?string $note = null): EdVisit
    {
        return $this->visits->transition($actor, $visit, EdVisit::STATUS_DISPOSITIONED, $note, EdVisit::DISPOSITION_DISCHARGE);
    }

    /** Transfer the patient out (to another facility) — the recorded disposition; NO Stay. Gated `ed.manage`. */
    public function transferOut(User $actor, EdVisit $visit, ?string $note = null): EdVisit
    {
        return $this->visits->transition($actor, $visit, EdVisit::STATUS_DISPOSITIONED, $note, EdVisit::DISPOSITION_TRANSFER);
    }

    /**
     * ADMIT — the ED→ADT handoff. Reuses the EXISTING atomic, concurrency-safe {@see AdmissionService::admit}
     * to create an inpatient `Stay` (`admission_type = emergency`, the target bed/ward), then records the
     * disposition + the soft `stay_id` on the visit — ALL in ONE transaction (a failure rolls back BOTH).
     * Requires `admission.manage` (the ED physician / charge nurse with admission rights); the bed-claim
     * concurrency-safety + one-active-stay guard are the proven G1/G2 guarantees, reused UNCHANGED.
     *
     * @return array{stay: Stay, visit: EdVisit}
     */
    public function admit(User $actor, EdVisit $visit, Bed $bed, StaffProfile $clinician, ?string $reason = null): array
    {
        // ADMIT additionally requires admission.manage (AdmissionService re-checks it; assert up front for a
        // clean error and so an ed.manage-only user is refused before any write).
        Gate::forUser($actor)->authorize('admission.manage');

        $patient = Patient::query()->findOrFail($visit->patient_id);

        return DB::transaction(function () use ($actor, $visit, $bed, $clinician, $reason, $patient): array {
            // Reuse the EXISTING concurrency-safe atomic admit → an inpatient Stay (emergency admission).
            $stay = $this->admissions->admit($actor, $patient, $bed, $clinician, Stay::TYPE_EMERGENCY, $reason);

            // Record the disposition + the soft stay link — atomically with the admit above. If this throws
            // (e.g. the visit is not awaiting_disposition), the whole transaction — including the Stay + the
            // bed claim — rolls back. No orphan Stay, no dispositioned-admit visit without its Stay.
            $updated = $this->visits->transition($actor, $visit, EdVisit::STATUS_DISPOSITIONED, $reason, EdVisit::DISPOSITION_ADMIT, $stay->id);

            return ['stay' => $stay, 'visit' => $updated];
        });
    }
}
