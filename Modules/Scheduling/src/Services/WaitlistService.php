<?php

namespace Modules\Scheduling\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Modules\Platform\Models\User;
use Modules\Scheduling\Events\WaitlistEntryStatusChanged;
use Modules\Scheduling\Exceptions\WaitlistException;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\WaitlistEntry;

class WaitlistService
{
    public function __construct(private readonly BookingService $bookings) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): WaitlistEntry
    {
        return WaitlistEntry::query()->create($data);
    }

    /**
     * @return Collection<int, WaitlistEntry>
     */
    public function matchingForAppointment(Appointment $appointment): Collection
    {
        return $this->matchingForSlot(
            $appointment->service_id,
            $appointment->branch_id,
            $appointment->starts_at,
            $appointment->ends_at,
        );
    }

    /**
     * @return Collection<int, WaitlistEntry>
     */
    public function matchingForSlot(
        string $serviceId,
        ?string $branchId,
        CarbonInterface|string $startsAt,
        CarbonInterface|string $endsAt,
    ): Collection {
        $starts = CarbonImmutable::parse($startsAt);
        $ends = CarbonImmutable::parse($endsAt);

        return WaitlistEntry::query()
            ->where('service_id', $serviceId)
            ->where('status', WaitlistEntry::STATUS_WAITING)
            ->where(function ($query) use ($branchId): void {
                $query->whereNull('branch_id');

                if ($branchId !== null) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->where(function ($query) use ($starts, $ends): void {
                $query->where('flexible', true)
                    ->orWhere(function ($window) use ($starts, $ends): void {
                        $window->where('desired_starts_at', '<=', $starts)
                            ->where('desired_ends_at', '>=', $ends);
                    });
            })
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->get();
    }

    public function offer(
        WaitlistEntry $entry,
        CarbonInterface|string $startsAt,
        CarbonInterface|string $endsAt,
        string $branchId,
        User $actor,
    ): WaitlistEntry {
        $this->authorize($branchId, $actor);

        if ($entry->status !== WaitlistEntry::STATUS_WAITING) {
            throw WaitlistException::invalidStatus($entry->status);
        }

        $starts = CarbonImmutable::parse($startsAt);
        $ends = CarbonImmutable::parse($endsAt);

        if (! $this->matchingForSlot($entry->service_id, $branchId, $starts, $ends)->contains('id', $entry->id)) {
            throw WaitlistException::slotDoesNotMatch();
        }

        $fromStatus = $entry->status;
        $entry->forceFill([
            'status' => WaitlistEntry::STATUS_OFFERED,
            'offered_starts_at' => $starts,
            'offered_ends_at' => $ends,
            'offered_branch_id' => $branchId,
        ])->save();

        Event::dispatch(new WaitlistEntryStatusChanged(
            $entry->refresh(),
            $fromStatus,
            WaitlistEntry::STATUS_OFFERED,
            $actor,
            ['starts_at' => $starts->toDateTimeString(), 'ends_at' => $ends->toDateTimeString()],
        ));

        return $entry;
    }

    /**
     * @param  list<string>  $resourceIds
     */
    public function accept(WaitlistEntry $entry, array $resourceIds, User $actor): Appointment
    {
        $branchId = $entry->offered_branch_id ?? $entry->branch_id;

        if ($branchId === null) {
            throw WaitlistException::slotDoesNotMatch();
        }

        $this->authorize($branchId, $actor);

        /** @var array{entry: WaitlistEntry, appointment: Appointment} $result */
        $result = DB::transaction(function () use ($entry, $resourceIds, $actor, $branchId): array {
            $locked = WaitlistEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WaitlistEntry::STATUS_OFFERED) {
                throw WaitlistException::invalidStatus($locked->status);
            }

            if ($locked->offered_starts_at === null || $locked->offered_ends_at === null) {
                throw WaitlistException::slotDoesNotMatch();
            }

            $appointment = $this->bookings->book(
                $locked->service_id,
                $locked->patient_id,
                $branchId,
                $locked->offered_starts_at,
                $resourceIds,
                $actor,
            );

            $locked->forceFill(['status' => WaitlistEntry::STATUS_BOOKED])->save();

            return ['entry' => $locked->refresh(), 'appointment' => $appointment];
        });

        Event::dispatch(new WaitlistEntryStatusChanged(
            $result['entry'],
            WaitlistEntry::STATUS_OFFERED,
            WaitlistEntry::STATUS_BOOKED,
            $actor,
            ['appointment_id' => $result['appointment']->id],
        ));

        return $result['appointment'];
    }

    private function authorize(string $branchId, User $actor): void
    {
        if (! Gate::forUser($actor)->allows('appointment.manage', ['branch_id' => $branchId])) {
            throw new AuthorizationException('This user cannot manage appointments.');
        }
    }
}
