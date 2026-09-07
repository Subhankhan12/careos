<?php

namespace Modules\Surgery\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\Clinical\Services\EncounterService;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Exceptions\SurgicalCaseException;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalCaseAnesthesiaAssessment;
use Modules\Surgery\Models\SurgicalCaseEncounter;
use Modules\Surgery\Models\SurgicalCaseEvent;
use Modules\Surgery\Models\SurgicalCaseTeamMember;

/**
 * The surgical case (SURGERY.G1 + the G2 lifecycle). Scheduling a case is gated `surgery.manage`; so are
 * lifecycle transitions, team edits, and recording the anesthetist's ASA/Mallampati. Op documentation REUSES
 * Clinical's sign-and-lock `ClinicalNote`/`Encounter` (Encounter UNMODIFIED) via a Surgery-side link.
 *
 * ELECTRIC FENCE: the service records what a human enters — it computes NO acuity/priority/risk. The ASA and
 * Mallampati classes are ASSIGNED by the anesthetist and recorded; no surgical-risk score is ever computed.
 */
class SurgicalCaseService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EncounterService $encounters,
        private readonly ClinicalNoteService $notes,
    ) {}

    public function schedule(
        User $actor,
        Patient $patient,
        StaffProfile $surgeon,
        string $procedureDescription,
        Carbon $scheduledAt,
        ?string $stayId = null,
    ): SurgicalCase {
        Gate::forUser($actor)->authorize('surgery.manage');
        $this->assertSameTenant($patient->tenant_id, 'patient_id', $patient->id);
        $this->assertSameTenant($surgeon->tenant_id, 'primary_surgeon_id', $surgeon->id);

        return SurgicalCase::query()->create([
            'patient_id' => $patient->id,
            'primary_surgeon_id' => $surgeon->id,
            'stay_id' => $stayId,
            'procedure_description' => $procedureDescription,
            'scheduled_at' => $scheduledAt,
        ]);
    }

    /**
     * Advance the case through its LEGAL-ONLY lifecycle. Gated `surgery.manage`; an illegal move throws
     * {@see SurgicalCaseException::invalidTransition}. The phase timestamp is recorded (a fact) and an
     * append-only {@see SurgicalCaseEvent} is written; the audit follows via the event's `created` hook.
     */
    public function transition(User $actor, SurgicalCase $case, string $toStatus, ?string $reason = null): SurgicalCase
    {
        Gate::forUser($actor)->authorize('surgery.manage');
        $this->assertSameTenant($case->tenant_id, 'surgical_case_id', $case->id);

        if (! SurgicalCase::canTransition($case->status, $toStatus)) {
            throw SurgicalCaseException::invalidTransition($case->status, $toStatus);
        }

        $timestampColumn = match ($toStatus) {
            SurgicalCase::STATUS_PRE_OP => 'pre_op_at',
            SurgicalCase::STATUS_IN_PROGRESS => 'in_progress_at',
            SurgicalCase::STATUS_COMPLETED => 'completed_at',
            SurgicalCase::STATUS_POST_OP => 'post_op_at',
            SurgicalCase::STATUS_CANCELLED => 'cancelled_at',
            default => throw SurgicalCaseException::invalidTransition($case->status, $toStatus),
        };

        return DB::transaction(function () use ($case, $toStatus, $reason, $timestampColumn, $actor): SurgicalCase {
            $case->forceFill([
                'status' => $toStatus,
                'status_reason' => $reason,
                $timestampColumn => Carbon::now(),
            ])->save();

            SurgicalCaseEvent::query()->create([
                'patient_id' => $case->patient_id,
                'surgical_case_id' => $case->id,
                'event_type' => $toStatus, // 1:1 with the target phase
                'reason' => $reason,
                'performed_by' => $actor->id,
                'occurred_at' => Carbon::now(),
            ]);

            return $case->refresh();
        });
    }

    /** Add (or re-role) a member of the surgical team. Gated `surgery.manage`; tenant fail-closed. */
    public function addTeamMember(User $actor, SurgicalCase $case, StaffProfile $staff, string $teamRole): SurgicalCaseTeamMember
    {
        Gate::forUser($actor)->authorize('surgery.manage');
        $this->assertSameTenant($case->tenant_id, 'surgical_case_id', $case->id);
        $this->assertSameTenant($staff->tenant_id, 'staff_profile_id', $staff->id);

        return SurgicalCaseTeamMember::query()->updateOrCreate(
            ['surgical_case_id' => $case->id, 'staff_profile_id' => $staff->id],
            ['team_role' => $teamRole],
        );
    }

    /**
     * Record the anesthetist's ASSIGNED ASA physical-status class (I–VI) + optional Mallampati (I–IV) — a
     * RECORDED FACT, with provenance. Gated `surgery.manage`; tenant fail-closed. CareOS computes NOTHING: no
     * surgical-risk score, no prediction — the class is the clinician's assessment (the electric fence).
     *
     * APPEND-ONLY, WITH BOTH PEOPLE NAMED (QA-FIX.6b, P6-C2, D-209). This used to `forceFill(...)->save()`
     * straight onto the case: a revision OVERWROTE the previous assessment with no history anywhere, the
     * only person recorded was the one PICKED from a dropdown, and it was the single write in this module
     * that raised no audit event. Driven in a browser, an anaesthetist recorded an ASA III naming a
     * **pharmacy technician** as the assessor, then overwrote it with an ASA I — and nothing recorded who
     * had done either.
     *
     * Every assessment is now a NEW {@see SurgicalCaseAnesthesiaAssessment} row carrying BOTH people,
     * because they can legitimately differ and must never stand in for one another (the QA-FIX.2a / D-195
     * rule):
     *   - `assessed_by` — the clinician whose judgment it is (selected; the anaesthetist who assessed the
     *     patient is not always the person at the keyboard).
     *   - `recorded_by` — **the ACTOR**, taken from the authenticated user and never submitted. This is the
     *     column that did not exist.
     *
     * The `surgical_cases.asa_*` columns are still written, as the denormalised CURRENT value — the same
     * posture as `status` beside `surgical_case_events`, so no existing reader breaks and no historical row
     * is rewritten (the D-193 / D-197 / D-202 precedent). The row and the denormalised copy are written in
     * ONE transaction so the case can never claim an assessment that has no record behind it.
     *
     * The audit event follows from the row's `created` hook, exactly like every sibling write in this
     * module — no second audit path (see `AppServiceProvider`).
     *
     * NOT CHANGED HERE, deliberately: the staff dropdown is still unfiltered, so a non-anaesthetist can
     * still be NAMED as the assessor. That is `P6-M6` (role-blind staff selectors, which affects the
     * surgeon and team pickers too) and constraining it here would fix one selector and leave its
     * siblings — it stays open rather than being half-closed inside this part.
     */
    public function recordAnesthesiaAssessment(
        User $actor,
        SurgicalCase $case,
        string $asaClass,
        ?string $mallampati,
        StaffProfile $anesthetist,
    ): SurgicalCase {
        Gate::forUser($actor)->authorize('surgery.manage');
        $this->assertSameTenant($case->tenant_id, 'surgical_case_id', $case->id);
        $this->assertSameTenant($anesthetist->tenant_id, 'assessed_by', $anesthetist->id);

        if (! in_array($asaClass, SurgicalCase::ASA_CLASSES, true)) {
            throw SurgicalCaseException::invalidAsaClass($asaClass);
        }
        if ($mallampati !== null && ! in_array($mallampati, SurgicalCase::MALLAMPATI_CLASSES, true)) {
            throw SurgicalCaseException::invalidMallampati($mallampati);
        }

        return DB::transaction(function () use ($actor, $case, $asaClass, $mallampati, $anesthetist): SurgicalCase {
            $assessedAt = Carbon::now();

            // The record of fact — append-only, audited by its `created` hook.
            SurgicalCaseAnesthesiaAssessment::query()->create([
                'patient_id' => $case->patient_id,
                'surgical_case_id' => $case->id,
                'assessed_by' => $anesthetist->id,
                'recorded_by' => $actor->id,
                'assessed_at' => $assessedAt,
                'asa_class' => $asaClass,
                'mallampati' => $mallampati,
            ]);

            // The denormalised CURRENT value, so existing readers are unaffected.
            $case->forceFill([
                'asa_class' => $asaClass,
                'mallampati' => $mallampati,
                'asa_assessed_by' => $anesthetist->id,
                'asa_assessed_at' => $assessedAt,
            ])->save();

            return $case->refresh();
        });
    }

    /**
     * Every anesthesia assessment recorded for a case, newest first — the history the overwrite used to
     * destroy. A read model only: it computes nothing and ranks nothing.
     *
     * @return Collection<int, SurgicalCaseAnesthesiaAssessment>
     */
    public function anesthesiaAssessmentsFor(SurgicalCase $case): Collection
    {
        return SurgicalCaseAnesthesiaAssessment::query()
            ->with(['assessedBy', 'recordedBy'])
            ->where('surgical_case_id', $case->id)
            ->orderByDesc('assessed_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Start a peri-operative note for a phase — op documentation REUSING Clinical UNMODIFIED. Opens a
     * `TYPE_PROCEDURE` `Encounter` for the case's patient + primary surgeon, links it Surgery-side
     * ({@see SurgicalCaseEncounter}), authors a draft `ClinicalNote` via the EXISTING service, then CLOSES the
     * encounter so no lingering open encounter can break the one-open-per-practitioner invariant for other
     * verticals. The surgeon writes → signs → (amends/versions) the note through the existing note editor.
     */
    public function startNote(User $actor, SurgicalCase $case, string $phase): ClinicalNote
    {
        if (! in_array($phase, SurgicalCaseEncounter::PHASES, true)) {
            throw SurgicalCaseException::invalidPhase($phase);
        }
        $this->assertSameTenant($case->tenant_id, 'surgical_case_id', $case->id);

        $patient = Patient::query()->findOrFail($case->patient_id);
        $surgeon = StaffProfile::query()->findOrFail($case->primary_surgeon_id);
        $branch = Branch::query()->find($surgeon->primary_branch_id) ?? Branch::query()->firstOrFail();

        return DB::transaction(function () use ($actor, $case, $phase, $patient, $surgeon, $branch): ClinicalNote {
            $encounter = $this->encounters->open(
                $patient, $surgeon, $branch, null, Encounter::TYPE_PROCEDURE, $actor, "Surgical case — {$phase}",
            );
            SurgicalCaseEncounter::query()->create([
                'surgical_case_id' => $case->id,
                'encounter_id' => $encounter->id,
                'phase' => $phase,
            ]);
            $note = $this->notes->saveDraft($encounter, $surgeon, [], $actor, null, null);
            $this->encounters->close($encounter, $actor); // no lingering open encounter (protect the one-open invariant)

            return $note;
        });
    }

    /**
     * @return Collection<int, SurgicalCase>
     */
    public function forPatient(Patient $patient): Collection
    {
        return SurgicalCase::query()
            ->where('patient_id', $patient->id)
            ->orderBy('scheduled_at')
            ->get();
    }

    private function assertSameTenant(?string $tenantId, string $attribute, string $id): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
