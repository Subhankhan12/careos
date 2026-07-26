<?php

namespace Modules\Hospital\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Modules\Hospital\Events\StayTransitioned;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\StayEvent;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * The ADT workflow (HOSPITAL.G2) — admit / transfer / discharge, the core of the inpatient
 * vertical. A Stay is a NET-NEW entity ABOVE an UNMODIFIED Encounter (map §2.2). Every action
 * is ATOMIC (the dental-perform discipline): the stay change + the bed claim/release (via G1's
 * proven concurrency-safe BedService — NOT reimplemented here) + the append-only StayEvent all
 * happen in ONE transaction, so a failure rolls back everything — never an orphan stay, never a
 * stuck bed. The Stay lifecycle is a legal-only state machine (admitted -> discharged; transfer
 * is a bed-move within admitted). Gated on `admission.manage`; tenant + branch scoped, fail-closed.
 *
 * ELECTRIC FENCE: this is OPERATIONAL — where the patient is, which bed. No acuity scoring, no
 * auto-triage, no computed-severity bed assignment, no deterioration flag. The bed/ward/type/
 * disposition are facts a human sets; the system records them and never judges.
 *
 * No charge is posted here — bed-to-billing accrual is HOSPITAL.G6.
 */
class AdmissionService
{
    public function __construct(
        private readonly BedService $beds,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Admit a patient into a bed: create the stay, CLAIM the bed (concurrency-safe, free->occupied),
     * and append the admission event — atomically. One active admission per patient is enforced.
     */
    public function admit(
        User $actor,
        Patient $patient,
        Bed $bed,
        StaffProfile $clinician,
        string $admissionType,
        ?string $reason = null,
    ): Stay {
        Gate::forUser($actor)->authorize('admission.manage');
        $this->assertSameTenant('patient_id', $patient->tenant_id);
        $this->assertSameTenant('bed_id', $bed->tenant_id); // fail-closed: reject a cross-tenant bed up front

        $stay = DB::transaction(function () use ($actor, $patient, $bed, $clinician, $admissionType, $reason): Stay {
            // One-active-stay guard: lock the patient row so two concurrent admits serialise, then
            // refuse a second active admission (the inpatient analogue of one-open-encounter).
            Patient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $active = Stay::query()
                ->where('patient_id', $patient->id)
                ->where('status', Stay::STATUS_ADMITTED)
                ->lockForUpdate()
                ->exists();
            if ($active) {
                throw AdmissionException::alreadyAdmitted($patient->id);
            }

            // Claim the bed through G1's proven concurrency-safe primitive (two concurrent admits
            // to one bed → one winner). Its BedStatusChanged fires inside this transaction, so the
            // bed change + its audit roll back with the stay if anything below fails.
            $this->beds->claim($actor, $bed);

            // Create the stay. The model validates the admission type + every reference within the
            // tenant here — an invalid value throws and the whole transaction (incl. the bed claim)
            // rolls back.
            $stay = Stay::query()->create([
                'patient_id' => $patient->id,
                'branch_id' => $bed->branch_id,
                'admitting_clinician_id' => $clinician->id,
                'current_bed_id' => $bed->id,
                'current_ward_id' => $bed->ward_id,
                'admitted_at' => now(),
                'admission_type' => $admissionType,
                'admission_reason' => $reason,
            ]);

            $this->appendEvent($actor, $stay, StayEvent::TYPE_ADMITTED, $bed->id, null, $bed->ward_id, $reason);

            return $stay;
        });

        Event::dispatch(new StayTransitioned(
            $stay, null, Stay::STATUS_ADMITTED, $actor, StayEvent::TYPE_ADMITTED, $reason,
            ['bed_id' => $bed->id, 'ward_id' => $bed->ward_id, 'admission_type' => $admissionType],
        ));

        return $stay;
    }

    /**
     * Transfer a patient to a different bed/ward: CLAIM the new bed, RELEASE the old (-> cleaning),
     * move the stay, append a transfer event — atomically. Only an active admission may transfer.
     */
    public function transfer(User $actor, Stay $stay, Bed $newBed, ?string $reason = null): Stay
    {
        Gate::forUser($actor)->authorize('admission.manage');
        $this->assertSameTenant('stay_id', $stay->tenant_id);
        $this->assertSameTenant('bed_id', $newBed->tenant_id); // fail-closed: reject a cross-tenant bed up front

        $stay = DB::transaction(function () use ($actor, $stay, $newBed, $reason): Stay {
            $locked = Stay::query()->whereKey($stay->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Stay::STATUS_ADMITTED) {
                throw AdmissionException::notActive($locked->id, $locked->status);
            }

            $oldBedId = $locked->current_bed_id;
            if ($newBed->id === $oldBedId) {
                throw AdmissionException::sameBed($newBed->id);
            }

            // Claim the NEW bed first (if it is taken, this throws and the OLD bed is never released).
            $this->beds->claim($actor, $newBed);

            // Release the OLD bed (occupied -> cleaning) — atomically with the claim + the move.
            $oldBed = Bed::query()->whereKey($oldBedId)->firstOrFail();
            $this->beds->release($actor, $oldBed);

            $locked->forceFill([
                'current_bed_id' => $newBed->id,
                'current_ward_id' => $newBed->ward_id,
            ])->save();

            $this->appendEvent($actor, $locked, StayEvent::TYPE_TRANSFERRED, $newBed->id, $oldBedId, $newBed->ward_id, $reason);

            return $locked;
        });

        Event::dispatch(new StayTransitioned(
            $stay, Stay::STATUS_ADMITTED, Stay::STATUS_ADMITTED, $actor, StayEvent::TYPE_TRANSFERRED, $reason,
            ['bed_id' => $stay->current_bed_id, 'ward_id' => $stay->current_ward_id],
        ));

        return $stay;
    }

    /**
     * Discharge a patient: set the disposition + discharged_at, RELEASE the bed (-> cleaning),
     * transition the stay to discharged, append the discharge event — atomically. Only an active
     * admission may be discharged. (LOS + the discharge summary are HOSPITAL.G7; no charge here.)
     */
    public function discharge(User $actor, Stay $stay, string $disposition, ?string $reason = null): Stay
    {
        Gate::forUser($actor)->authorize('admission.manage');
        $this->assertSameTenant('stay_id', $stay->tenant_id);

        if (! in_array($disposition, Stay::DISPOSITIONS, true)) {
            throw AdmissionException::invalidDisposition($disposition);
        }

        $stay = DB::transaction(function () use ($actor, $stay, $disposition, $reason): Stay {
            $locked = Stay::query()->whereKey($stay->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Stay::STATUS_ADMITTED) {
                throw AdmissionException::notActive($locked->id, $locked->status);
            }

            $bed = Bed::query()->whereKey($locked->current_bed_id)->firstOrFail();
            $this->beds->release($actor, $bed);

            $locked->forceFill([
                'status' => Stay::STATUS_DISCHARGED,
                'discharged_at' => now(),
                'discharge_disposition' => $disposition,
            ])->save();

            $this->appendEvent($actor, $locked, StayEvent::TYPE_DISCHARGED, $bed->id, null, $locked->current_ward_id, $reason, $disposition);

            return $locked;
        });

        Event::dispatch(new StayTransitioned(
            $stay, Stay::STATUS_ADMITTED, Stay::STATUS_DISCHARGED, $actor, StayEvent::TYPE_DISCHARGED, $reason,
            ['bed_id' => $stay->current_bed_id, 'disposition' => $disposition],
        ));

        return $stay;
    }

    private function appendEvent(
        User $actor,
        Stay $stay,
        string $eventType,
        ?string $bedId,
        ?string $fromBedId,
        ?string $wardId,
        ?string $reason,
        ?string $disposition = null,
    ): void {
        StayEvent::query()->create([
            'patient_id' => $stay->patient_id,
            'stay_id' => $stay->id,
            'event_type' => $eventType,
            'bed_id' => $bedId,
            'from_bed_id' => $fromBedId,
            'ward_id' => $wardId,
            'reason' => $reason,
            'disposition' => $disposition,
            'occurred_at' => now(),
            'performed_by' => $actor->id,
        ]);
    }

    private function assertSameTenant(string $attribute, ?string $tenantId): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) $tenantId);
        }
    }
}
