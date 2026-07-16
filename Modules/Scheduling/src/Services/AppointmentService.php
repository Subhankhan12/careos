<?php

namespace Modules\Scheduling\Services;

use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;
use Modules\Scheduling\Events\AppointmentTransitioned;
use Modules\Scheduling\Exceptions\IllegalAppointmentTransitionException;
use Modules\Scheduling\Models\Appointment;

class AppointmentService
{
    /**
     * @var array<string, list<string>>
     */
    private const LEGAL_TRANSITIONS = [
        Appointment::STATUS_BOOKED => [
            Appointment::STATUS_CONFIRMED,
            Appointment::STATUS_CANCELLED,
            Appointment::STATUS_NO_SHOW,
            Appointment::STATUS_RESCHEDULED,
        ],
        Appointment::STATUS_CONFIRMED => [
            Appointment::STATUS_ARRIVED,
            Appointment::STATUS_CANCELLED,
            Appointment::STATUS_NO_SHOW,
            Appointment::STATUS_RESCHEDULED,
        ],
        Appointment::STATUS_ARRIVED => [
            Appointment::STATUS_IN_PROGRESS,
            Appointment::STATUS_CANCELLED,
        ],
        Appointment::STATUS_IN_PROGRESS => [
            Appointment::STATUS_COMPLETED,
        ],
        Appointment::STATUS_COMPLETED => [],
        Appointment::STATUS_CANCELLED => [],
        Appointment::STATUS_NO_SHOW => [],
        Appointment::STATUS_RESCHEDULED => [],
    ];

    public function __construct(private readonly BookingService $bookings) {}

    public function confirm(Appointment $appointment, User $actor): Appointment
    {
        return $this->transition($appointment, Appointment::STATUS_CONFIRMED, $actor);
    }

    public function arrive(Appointment $appointment, User $actor): Appointment
    {
        return $this->transition($appointment, Appointment::STATUS_ARRIVED, $actor);
    }

    public function start(Appointment $appointment, User $actor): Appointment
    {
        return $this->transition($appointment, Appointment::STATUS_IN_PROGRESS, $actor);
    }

    public function complete(Appointment $appointment, User $actor): Appointment
    {
        return $this->transition($appointment, Appointment::STATUS_COMPLETED, $actor);
    }

    public function noShow(Appointment $appointment, User $actor, ?string $reason = null): Appointment
    {
        return $this->transition($appointment, Appointment::STATUS_NO_SHOW, $actor, $reason);
    }

    public function cancel(Appointment $appointment, User $actor, string $reason): Appointment
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Cancellation requires a reason.');
        }

        return $this->transition($appointment, Appointment::STATUS_CANCELLED, $actor, $reason);
    }

    /**
     * @param  list<string>  $resourceIds
     */
    public function reschedule(
        Appointment $appointment,
        CarbonInterface|string $startsAt,
        array $resourceIds,
        User $actor,
        string $reason,
        ?string $branchId = null,
        ?string $notes = null,
    ): Appointment {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reschedule requires a reason.');
        }

        $this->authorize($appointment, $actor);
        $oldStatus = $appointment->status;
        $this->assertLegal($oldStatus, Appointment::STATUS_RESCHEDULED);

        /** @var array{old: Appointment, new: Appointment, resource_ids: list<string>} $result */
        $result = DB::transaction(function () use ($appointment, $startsAt, $resourceIds, $actor, $reason, $branchId, $notes): array {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $fromStatus = $locked->status;
            $this->assertLegal($fromStatus, Appointment::STATUS_RESCHEDULED);

            $resourceIds = $resourceIds !== []
                ? $resourceIds
                : $locked->resourceLinks()->orderBy('resource_id')->pluck('resource_id')->all();

            $locked->forceFill([
                'status' => Appointment::STATUS_RESCHEDULED,
                'status_reason' => $reason,
                'status_changed_by' => (string) $actor->getKey(),
                'status_changed_at' => now(),
            ])->save();
            $locked->resourceLinks()->delete();

            $newAppointment = $this->bookings->book(
                $locked->service_id,
                $locked->patient_id,
                $branchId ?? $locked->branch_id,
                $startsAt,
                $resourceIds,
                $actor,
                $locked->source,
                $notes ?? $locked->notes,
                $locked->id,
            );

            return [
                'old' => $locked->refresh(),
                'new' => $newAppointment,
                'resource_ids' => array_values($resourceIds),
            ];
        });

        Event::dispatch(new AppointmentTransitioned(
            $result['old'],
            $oldStatus,
            Appointment::STATUS_RESCHEDULED,
            $actor,
            $reason,
            ['new_appointment_id' => $result['new']->id, 'resource_ids' => $result['resource_ids']],
        ));

        return $result['new'];
    }

    /**
     * Portal self-service cancellation: the PATIENT is the actor. Ownership is
     * fail-closed (only the appointment's own patient), the transition legality
     * and resource freeing reuse the exact staff mechanics under the same row
     * lock, and the app-layer listener audits with actor_type=patient. The
     * cancel-window policy is enforced by the portal controller server-side.
     */
    public function cancelForPatient(Appointment $appointment, Patient $patient, string $reason): Appointment
    {
        if ($appointment->patient_id !== $patient->id) {
            throw new AuthorizationException('This patient cannot cancel this appointment.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Cancellation requires a reason.');
        }

        $fromStatus = $appointment->status;
        $this->assertLegal($fromStatus, Appointment::STATUS_CANCELLED);

        $updated = DB::transaction(function () use ($appointment, $patient, $reason): Appointment {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->assertLegal($locked->status, Appointment::STATUS_CANCELLED);

            $locked->forceFill([
                'status' => Appointment::STATUS_CANCELLED,
                'status_reason' => $reason,
                'status_changed_by' => 'patient:'.$patient->id,
                'status_changed_at' => now(),
            ])->save();

            $locked->resourceLinks()->delete();

            return $locked->refresh();
        });

        Event::dispatch(new AppointmentTransitioned($updated, $fromStatus, Appointment::STATUS_CANCELLED, $patient, $reason));

        return $updated;
    }

    /**
     * Self check-in arrival (P0P.G7): the PATIENT is the actor. Identity is
     * verified upstream (kiosk resolve or portal session), so there is NO staff
     * Gate here — ownership is fail-closed instead. A booked appointment is
     * confirmed then arrived (mirroring the reception 'arrive' path); each hop
     * is dispatched so the app-layer listener audits with actor_type=patient.
     */
    public function arriveForPatient(Appointment $appointment, Patient $patient): Appointment
    {
        if ($appointment->patient_id !== $patient->id) {
            throw new AuthorizationException('This patient cannot check into this appointment.');
        }

        /** @var array{appointment: Appointment, hops: list<array{0: string, 1: string}>} $result */
        $result = DB::transaction(function () use ($appointment, $patient): array {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $hops = [];

            if ($locked->status === Appointment::STATUS_BOOKED) {
                $this->assertLegal($locked->status, Appointment::STATUS_CONFIRMED);
                $from = $locked->status;
                $this->applyPatientStatus($locked, Appointment::STATUS_CONFIRMED, $patient);
                $hops[] = [$from, Appointment::STATUS_CONFIRMED];
            }

            $this->assertLegal($locked->status, Appointment::STATUS_ARRIVED);
            $from = $locked->status;
            $this->applyPatientStatus($locked, Appointment::STATUS_ARRIVED, $patient);
            $hops[] = [$from, Appointment::STATUS_ARRIVED];

            return ['appointment' => $locked->refresh(), 'hops' => $hops];
        });

        foreach ($result['hops'] as [$from, $to]) {
            Event::dispatch(new AppointmentTransitioned($result['appointment'], $from, $to, $patient, 'self check-in'));
        }

        return $result['appointment'];
    }

    private function applyPatientStatus(Appointment $appointment, string $toStatus, Patient $patient): void
    {
        $appointment->forceFill([
            'status' => $toStatus,
            'status_changed_by' => 'patient:'.$patient->id,
            'status_changed_at' => now(),
        ])->save();
    }

    public function transition(
        Appointment $appointment,
        string $toStatus,
        User $actor,
        ?string $reason = null,
    ): Appointment {
        $this->authorize($appointment, $actor);
        $fromStatus = $appointment->status;
        $this->assertLegal($fromStatus, $toStatus);

        $updated = DB::transaction(function () use ($appointment, $toStatus, $actor, $reason): Appointment {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->assertLegal($locked->status, $toStatus);

            $locked->forceFill([
                'status' => $toStatus,
                'status_reason' => $reason,
                'status_changed_by' => (string) $actor->getKey(),
                'status_changed_at' => now(),
            ])->save();

            if ($toStatus === Appointment::STATUS_CANCELLED || $toStatus === Appointment::STATUS_RESCHEDULED) {
                $locked->resourceLinks()->delete();
            }

            return $locked->refresh();
        });

        Event::dispatch(new AppointmentTransitioned($updated, $fromStatus, $toStatus, $actor, $reason));

        return $updated;
    }

    private function authorize(Appointment $appointment, User $actor): void
    {
        if (! Gate::forUser($actor)->allows('appointment.manage', ['branch_id' => $appointment->branch_id])) {
            throw new AuthorizationException('This user cannot manage appointments.');
        }
    }

    private function assertLegal(string $fromStatus, string $toStatus): void
    {
        if (! in_array($toStatus, self::LEGAL_TRANSITIONS[$fromStatus] ?? [], true)) {
            throw IllegalAppointmentTransitionException::fromTo($fromStatus, $toStatus);
        }
    }
}
