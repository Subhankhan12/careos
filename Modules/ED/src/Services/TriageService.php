<?php

namespace Modules\ED\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Clinical\Models\Vital;
use Modules\Clinical\Services\ClinicalListService;
use Modules\ED\Contracts\TriageAcuityProvider;
use Modules\ED\Exceptions\EdVisitException;
use Modules\ED\Models\EdTriage;
use Modules\ED\Models\EdVisit;
use Modules\ED\Support\AcuityContext;
use Modules\ED\Support\AcuityResult;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * ED triage (ED.G2). Recording a triage is gated `triage.record`; it captures the presenting complaint, the
 * nurse-**ASSIGNED** acuity (with provenance), and — reusing the EXISTING Clinical `Vital` store — RAW vitals,
 * then moves the visit `arrived → triaged` (the G1 legal transition). Triage records are APPEND-ONLY (a
 * re-triage is a new row; history preserved).
 *
 * ELECTRIC FENCE (the crux): the acuity is the value the NURSE ASSIGNS — a recorded fact, exactly like the
 * anesthetist-assigned ASA class. This service does NOT compute or suggest an acuity. The
 * {@see TriageAcuityProvider} seam is threaded in {@see acuitySuggestion()} (a read-side advisory affordance)
 * and returns {@see AcuityResult::none()} today (the null-object) — a certified partner could later return an
 * ADVISORY suggestion, surfaced to the nurse, NEVER auto-assigning the acuity. Recording never touches the
 * seam: the nurse's assigned value is what is stored.
 */
class TriageService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ClinicalListService $clinicalLists,
        private readonly EdVisitService $visits,
        private readonly TriageAcuityProvider $acuity,
    ) {}

    /**
     * Record a triage assessment for a visit. Gated `triage.record`; tenant fail-closed on the visit + nurse.
     * In ONE transaction: append the triage record (the nurse's ASSIGNED acuity + provenance), record RAW
     * vitals if given (the EXISTING `Vital` store, needs `note.write`), and move the visit `arrived → triaged`
     * (only if still `arrived` — a re-triage keeps the status). The acuity is the nurse's value; nothing is
     * computed or suggested here.
     *
     * @param  array<string, mixed>|null  $vitals  raw vital fields (systolic/diastolic/heart_rate/temperature_c/spo2/…) — optional
     */
    public function record(
        User $actor,
        EdVisit $visit,
        StaffProfile $nurse,
        string $presentingComplaint,
        string $acuityScale,
        string $acuityLevel,
        ?array $vitals = null,
    ): EdTriage {
        Gate::forUser($actor)->authorize('triage.record');
        $this->assertSameTenant($visit->tenant_id, 'ed_visit_id', $visit->id);
        $this->assertSameTenant($nurse->tenant_id, 'triaged_by', $nurse->id);

        if (trim($presentingComplaint) === '') {
            throw EdVisitException::presentingComplaintRequired();
        }
        // Data-entry validation only (a valid level for the scale) — never a computed grade. The model
        // re-asserts this; validating here yields a clean domain error before the transaction.
        if (! in_array($acuityScale, EdTriage::SCALES, true)) {
            throw EdVisitException::invalidAcuityScale($acuityScale);
        }
        if (! EdTriage::isValidAssignment($acuityScale, $acuityLevel)) {
            throw EdVisitException::invalidAcuityLevel($acuityLevel, $acuityScale);
        }

        return DB::transaction(function () use ($actor, $visit, $nurse, $presentingComplaint, $acuityScale, $acuityLevel, $vitals): EdTriage {
            $triage = EdTriage::query()->create([
                'patient_id' => $visit->patient_id,
                'ed_visit_id' => $visit->id,
                'triaged_by' => $nurse->id,
                'triaged_at' => Carbon::now(),
                'presenting_complaint' => $presentingComplaint,
                'acuity_scale' => $acuityScale,
                'acuity_level' => $acuityLevel, // the value the NURSE assigned — a recorded fact, not computed
            ]);

            if ($this->hasVitals($vitals)) {
                $patient = Patient::query()->findOrFail($visit->patient_id);
                // RAW vitals via the EXISTING store (no bands/flags/scores — the electric fence, unchanged).
                $this->clinicalLists->recordVital($patient, $nurse, $actor, $vitals, null);
            }

            // The G1 legal transition — only from `arrived` (a re-triage on an already-triaged visit keeps the
            // status, just appends a new triage record).
            if ($visit->status === EdVisit::STATUS_ARRIVED) {
                $this->visits->transition($actor, $visit, EdVisit::STATUS_TRIAGED);
            }

            return $triage;
        });
    }

    /**
     * The triage-acuity seam, threaded (ED.G2). Returns the ADVISORY acuity a CERTIFIED PARTNER would suggest
     * for this visit — {@see AcuityResult::none()} today (the null-object computes nothing). Read-side only:
     * surfaced to the nurse as an advisory, NEVER auto-assigned. CareOS computes no acuity.
     */
    public function acuitySuggestion(EdVisit $visit): AcuityResult
    {
        return $this->acuity->suggestAcuity(new AcuityContext($visit->chief_complaint));
    }

    /**
     * @return Collection<int, EdTriage>
     */
    public function forVisit(EdVisit $visit): Collection
    {
        return EdTriage::query()
            ->where('ed_visit_id', $visit->id)
            ->with('triagedBy')
            ->orderByDesc('triaged_at')
            ->get();
    }

    /**
     * The visit's RAW vitals recorded at triage (patient-scoped, encounter-less), newest first — raw values
     * only, NO bands/flags/scores.
     *
     * @return Collection<int, Vital>
     */
    public function vitalsForVisit(EdVisit $visit): Collection
    {
        return Vital::query()
            ->where('patient_id', $visit->patient_id)
            ->whereNull('encounter_id')
            ->where('recorded_at', '>=', $visit->arrived_at)
            ->orderByDesc('recorded_at')
            ->get();
    }

    /**
     * @param  array<string, mixed>|null  $vitals
     */
    private function hasVitals(?array $vitals): bool
    {
        if ($vitals === null) {
            return false;
        }

        foreach (['systolic', 'diastolic', 'heart_rate', 'temperature_c', 'spo2', 'weight_g', 'height_mm'] as $field) {
            if (($vitals[$field] ?? null) !== null && $vitals[$field] !== '') {
                return true;
            }
        }

        return false;
    }

    private function assertSameTenant(?string $tenantId, string $attribute, string $id): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
