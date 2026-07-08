<?php

namespace Modules\Clinical\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Clinical\Events\EncounterClosed;
use Modules\Clinical\Events\EncounterOpened;
use Modules\Clinical\Models\Encounter;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Services\AppointmentService;

class EncounterService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AppointmentService $appointments,
    ) {}

    public function open(
        Patient $patient,
        StaffProfile $practitioner,
        Branch $branch,
        ?Appointment $appointment,
        string $type,
        User $actor,
        ?string $reasonForVisit = null,
    ): Encounter {
        $this->authorize($actor, $branch->id);
        $tenantId = $this->tenantContext->id();
        $this->assertSameTenant($patient, 'patient_id', $tenantId);
        $this->assertSameTenant($practitioner, 'practitioner_id', $tenantId);
        $this->assertSameTenant($branch, 'branch_id', $tenantId);

        if ($appointment !== null) {
            $this->assertSameTenant($appointment, 'appointment_id', $tenantId);
            $this->assertAppointmentMatches($appointment, $patient, $branch);
        }

        if (! in_array($type, Encounter::types(), true)) {
            throw new InvalidArgumentException('Encounter type is not supported.');
        }

        $encounter = DB::transaction(function () use (
            $patient,
            $practitioner,
            $branch,
            $appointment,
            $type,
            $actor,
            $reasonForVisit,
        ): Encounter {
            Patient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            StaffProfile::query()->whereKey($practitioner->id)->lockForUpdate()->firstOrFail();

            $alreadyOpen = Encounter::query()
                ->where('patient_id', $patient->id)
                ->where('practitioner_id', $practitioner->id)
                ->where('status', Encounter::STATUS_OPEN)
                ->lockForUpdate()
                ->exists();

            if ($alreadyOpen) {
                throw new InvalidArgumentException('Patient already has an open encounter with this practitioner.');
            }

            if ($appointment !== null) {
                $this->moveAppointmentToInProgress($appointment, $actor);
            }

            return Encounter::query()->create([
                'patient_id' => $patient->id,
                'practitioner_id' => $practitioner->id,
                'branch_id' => $branch->id,
                'appointment_id' => $appointment?->id,
                'type' => $type,
                'started_at' => now(),
                'status' => Encounter::STATUS_OPEN,
                'reason_for_visit' => $reasonForVisit,
            ]);
        });

        Event::dispatch(new EncounterOpened($encounter, $actor));

        return $encounter;
    }

    public function close(Encounter $encounter, User $actor): Encounter
    {
        $this->authorize($actor, $encounter->branch_id);
        $this->assertSameTenant($encounter, 'encounter_id', $this->tenantContext->id());

        $closed = DB::transaction(function () use ($encounter): Encounter {
            $locked = Encounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Encounter::STATUS_CLOSED) {
                return $locked;
            }

            $locked->forceFill([
                'status' => Encounter::STATUS_CLOSED,
                'ended_at' => now(),
            ])->save();

            return $locked->refresh();
        });

        if ($encounter->status !== Encounter::STATUS_CLOSED) {
            Event::dispatch(new EncounterClosed($closed, $actor));
        }

        return $closed;
    }

    private function moveAppointmentToInProgress(Appointment $appointment, User $actor): void
    {
        $current = $appointment->refresh();

        if ($current->status === Appointment::STATUS_BOOKED) {
            $current = $this->appointments->confirm($current, $actor);
        }

        if ($current->status === Appointment::STATUS_CONFIRMED) {
            $current = $this->appointments->arrive($current, $actor);
        }

        if ($current->status === Appointment::STATUS_ARRIVED) {
            $this->appointments->start($current, $actor);

            return;
        }

        if ($current->status === Appointment::STATUS_IN_PROGRESS) {
            return;
        }

        throw new InvalidArgumentException('Appointment cannot be moved to in progress.');
    }

    private function authorize(User $actor, string $branchId): void
    {
        if (! Gate::forUser($actor)->allows('encounter.manage', ['branch_id' => $branchId])) {
            throw new AuthorizationException('This user cannot manage encounters.');
        }
    }

    private function assertSameTenant(object $model, string $attribute, string $tenantId): void
    {
        if (($model->tenant_id ?? null) !== $tenantId) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }

    private function assertAppointmentMatches(Appointment $appointment, Patient $patient, Branch $branch): void
    {
        if ($appointment->patient_id !== $patient->id || $appointment->branch_id !== $branch->id) {
            throw new InvalidArgumentException('Encounter appointment must match the encounter patient and branch.');
        }
    }
}
