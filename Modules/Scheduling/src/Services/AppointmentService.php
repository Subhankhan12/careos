<?php

namespace Modules\Scheduling\Services;

use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
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
