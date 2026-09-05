<?php

namespace Modules\Scheduling\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\BranchHoursService;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Events\AppointmentBooked;
use Modules\Scheduling\Exceptions\BookingConflictException;
use Modules\Scheduling\Exceptions\BookingUnavailableException;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentResource;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\Service;

class BookingService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly TenantContext $tenantContext,
        private readonly BranchHoursService $branchHours,
    ) {}

    /**
     * Book a slot, or RECORD one that already happened.
     *
     * `$allowPastStart` is the booking-vs-recording distinction (QA-FIX.1b, D-194) and it is a
     * CALL-SITE CONSTANT — never request-derived. It defaults to `true` because this method is
     * also the repo's historical-recording path: both demo seeders lay down a real past week
     * through it, and a large number of fixtures book at fixed dates that have since become past.
     *
     * **A CALLER THAT REPRESENTS A PERSON CHOOSING A SLOT MUST PASS `false`.** All four
     * interactive paths do: the day-board quick-book, the staff reschedule, the waitlist
     * acceptance and the recurring series. `bookOnline()` does not take the flag at all — the
     * patient portal and the public form can never backdate.
     *
     * @param  list<string>  $resourceIds
     */
    public function book(
        string $serviceId,
        ?string $patientId,
        string $branchId,
        CarbonInterface|string $startsAt,
        array $resourceIds,
        User $bookedBy,
        string $source = Appointment::SOURCE_STAFF,
        ?string $notes = null,
        ?string $rescheduledFromId = null,
        ?string $seriesId = null,
        ?string $occurrenceDate = null,
        bool $allowPastStart = true,
    ): Appointment {
        return $this->createBooking(
            $serviceId,
            $patientId,
            $branchId,
            $startsAt,
            $resourceIds,
            $bookedBy,
            true,
            $source,
            $notes,
            $rescheduledFromId,
            $seriesId,
            $occurrenceDate,
            $allowPastStart,
        );
    }

    /**
     * The ONLINE booking path (patient portal + public form).
     *
     * `$allowPastStart` DEFAULTS TO FALSE here — the opposite of `book()` — because every request
     * that reaches this method is a person choosing a slot. Neither controller passes the argument,
     * so the patient-facing paths are strict without having to remember anything, and nothing a
     * client sends can relax it (QA-FIX.1b, D-194).
     *
     * The flag exists only for RECORDING an online booking that already happened: the demo seeders
     * lay down historical online-sourced appointments, and those must keep `booked_by = null` and
     * `source = online` (D-031), which routing them through `book()` would change.
     *
     * @param  list<string>  $resourceIds
     */
    public function bookOnline(
        string $serviceId,
        ?string $patientId,
        string $branchId,
        CarbonInterface|string $startsAt,
        array $resourceIds,
        ?string $notes = null,
        bool $allowPastStart = false,
    ): Appointment {
        return $this->createBooking(
            $serviceId,
            $patientId,
            $branchId,
            $startsAt,
            $resourceIds,
            null,
            false,
            Appointment::SOURCE_ONLINE,
            $notes,
            allowPastStart: $allowPastStart,
        );
    }

    /**
     * @param  list<string>  $resourceIds
     */
    private function createBooking(
        string $serviceId,
        ?string $patientId,
        string $branchId,
        CarbonInterface|string $startsAt,
        array $resourceIds,
        ?User $bookedBy,
        bool $authorize,
        string $source,
        ?string $notes = null,
        ?string $rescheduledFromId = null,
        ?string $seriesId = null,
        ?string $occurrenceDate = null,
        bool $allowPastStart = true,
    ): Appointment {
        $tenantId = $this->tenantContext->id();

        if ($authorize && ($bookedBy === null || ! Gate::forUser($bookedBy)->allows('appointment.manage', ['branch_id' => $branchId]))) {
            throw new AuthorizationException('This user cannot manage appointments.');
        }

        $service = Service::query()->findOrFail($serviceId);

        $branch = Branch::query()->whereKey($branchId)->first();
        if ($branch === null) {
            throw CrossTenantReferenceException::forAttribute('branch_id', $branchId);
        }

        // SOFT-SUSPEND (BRANCH.P1): a branch that has turned off online bookings takes no NEW
        // ONLINE bookings — but stays active for staff and keeps its existing appointments. This
        // gates the ONLINE source only; staff/agent bookings are unaffected (they can still work a
        // soft-suspended branch). It is DISTINCT from the hard active=false deactivation guard.
        if ($source === Appointment::SOURCE_ONLINE && ! ($branch->active && $branch->accepts_online_bookings)) {
            throw BookingUnavailableException::onlineBookingsSuspended($branchId);
        }

        if ($patientId !== null && ! Patient::query()->whereKey($patientId)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', $patientId);
        }

        if (! $service->isAvailableAtBranch($branchId)) {
            throw new InvalidArgumentException('Service is not available at the requested branch.');
        }

        $resources = $this->resourcesFor($resourceIds, $branchId);
        $this->assertResourceTypesMatch($service, $resources);

        $starts = CarbonImmutable::parse($startsAt);
        $ends = $starts->addMinutes($service->default_duration_minutes);
        $heldStart = $starts->subMinutes($service->buffer_before_minutes);
        $heldEnd = $ends->addMinutes($service->buffer_after_minutes);

        /*
         * A BOOKING CANNOT START IN THE PAST (P1-H3, D-194) — enforced HERE, in the funnel every
         * write path shares, so it holds for a stale or forged request even though the finder no
         * longer offers such a slot. The finder not offering something is not the server refusing
         * it; these are two different guarantees and the audit found the second one missing.
         *
         * `$allowPastStart` is the BOOKING-vs-RECORDING distinction, and it is a CALL-SITE
         * CONSTANT — never derived from the request, so nothing a client sends can relax it.
         * Recording an appointment that already happened (the demo seeders' historical week, an
         * import) is a legitimate act and stays permitted; a person choosing a slot is not, and
         * every interactive caller passes `false`. `bookOnline()` hardcodes `false`: a patient or
         * the public form can never backdate.
         */
        if (! $allowPastStart && $starts->lessThanOrEqualTo($this->nowInBranchClock($branchId))) {
            throw BookingUnavailableException::startsInThePast($starts->toDateTimeString());
        }

        // The branch must be open then. Unconfigured branches impose no constraint; a
        // configured branch rejects a start outside its opening hours (or on a closed day).
        // Every write path (book/bookOnline/series/waitlist) funnels through here.
        if (! $this->branchHours->allowsStart($branchId, $starts->dayOfWeek, $starts->hour * 60 + $starts->minute)) {
            throw BookingUnavailableException::outsideBranchHours($branchId);
        }

        foreach ($resources as $resource) {
            $this->assertWithinAvailability($resource, $heldStart, $heldEnd);
        }

        $resourceIds = $resources->pluck('id')->values()->all();

        $appointment = DB::transaction(function () use (
            $tenantId,
            $service,
            $patientId,
            $branchId,
            $starts,
            $ends,
            $heldStart,
            $heldEnd,
            $resourceIds,
            $bookedBy,
            $source,
            $notes,
            $rescheduledFromId,
            $seriesId,
            $occurrenceDate,
        ): Appointment {
            foreach ($resourceIds as $resourceId) {
                $this->lockResource($tenantId, $resourceId);
                $this->assertNoOverlap($tenantId, $resourceId, $heldStart, $heldEnd);
            }

            $appointment = Appointment::query()->create([
                'rescheduled_from_id' => $rescheduledFromId,
                'series_id' => $seriesId,
                'occurrence_date' => $occurrenceDate,
                'patient_id' => $patientId,
                'service_id' => $service->id,
                'branch_id' => $branchId,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'status' => Appointment::STATUS_BOOKED,
                'booked_by' => $bookedBy !== null ? (string) $bookedBy->getKey() : null,
                'source' => $source,
                'notes' => $notes,
                'check_in_code' => $this->generateCheckInCode(),
            ]);

            foreach ($resourceIds as $resourceId) {
                AppointmentResource::query()->create([
                    'appointment_id' => $appointment->id,
                    'resource_id' => $resourceId,
                ]);
            }

            return $appointment->refresh()->load('resourceLinks');
        });

        Event::dispatch(new AppointmentBooked($appointment, $resourceIds));

        return $appointment;
    }

    /**
     * @param  list<string>  $resourceIds
     * @return Collection<int, resource>
     */
    private function resourcesFor(array $resourceIds, string $branchId): Collection
    {
        $resourceIds = array_values(array_unique($resourceIds));

        if ($resourceIds === []) {
            throw new InvalidArgumentException('At least one resource is required.');
        }

        $resources = Resource::query()
            ->whereIn('id', $resourceIds)
            ->where('active', true)
            ->orderBy('id')
            ->get();

        if ($resources->count() !== count($resourceIds)) {
            throw CrossTenantReferenceException::forAttribute('resource_id', implode(',', $resourceIds));
        }

        foreach ($resources as $resource) {
            if ($resource->branch_id !== $branchId) {
                throw CrossTenantReferenceException::forAttribute('resource_id', $resource->id);
            }
        }

        return $resources;
    }

    /**
     * @param  Collection<int, resource>  $resources
     */
    private function assertResourceTypesMatch(Service $service, Collection $resources): void
    {
        $requiredTypes = array_values(array_unique($service->requires_resource_types ?? []));
        $actualTypes = $resources->pluck('type')->values()->all();

        foreach ($requiredTypes as $requiredType) {
            if (! in_array($requiredType, $actualTypes, true)) {
                throw new InvalidArgumentException("A {$requiredType} resource is required.");
            }
        }

        foreach ($actualTypes as $actualType) {
            if (! in_array($actualType, $requiredTypes, true)) {
                throw new InvalidArgumentException("Resource type {$actualType} is not required by this service.");
            }
        }
    }

    /**
     * "Now" as the PRACTICE'S WALL CLOCK, in the same naive base an appointment's `starts_at` uses.
     *
     * `starts_at` is a naive local value (a chosen date + time-of-day, never derived from `now()`),
     * so comparing it against a UTC `now()` would be wrong by the tenant's offset. Converting the
     * clock into the branch's zone and re-reading it as naive digits puts both sides in the same
     * base. `AvailableSlotFinder` does the identical thing, so the finder that OFFERS a slot and
     * the guard that REFUSES one cannot disagree about what "now" means for this practice.
     */
    private function nowInBranchClock(string $branchId): CarbonImmutable
    {
        return CarbonImmutable::parse(
            CarbonImmutable::now($this->branchTimezone($branchId))->format('Y-m-d H:i:s')
        );
    }

    /**
     * The zone the branch's clock runs in — the one a slot's wall-clock digits belong to.
     *
     * Same resolution as `AvailableSlotFinder::branchTimezone()` and the branch fallback
     * `AppointmentSeriesService` uses.
     */
    private function branchTimezone(string $branchId): string
    {
        $timezone = (string) (Branch::query()->find($branchId)?->getAttribute('timezone') ?? '');

        if ($timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return (string) config('app.timezone');
        }

        return $timezone;
    }

    private function assertWithinAvailability(
        Resource $resource,
        CarbonImmutable $heldStart,
        CarbonImmutable $heldEnd,
    ): void {
        $windows = $this->availability->windowsFor(
            $resource,
            $heldStart->toDateString(),
            $heldEnd->toDateString(),
        );

        foreach ($windows as $window) {
            if ($window['start_at']->lessThanOrEqualTo($heldStart)
                && $window['end_at']->greaterThanOrEqualTo($heldEnd)) {
                return;
            }
        }

        throw BookingUnavailableException::outsideAvailability($resource->id);
    }

    /**
     * A short per-appointment code the patient uses at a kiosk (P0P.G7). Not a
     * secret and not globally unique — the kiosk still requires an exact
     * name + date-of-birth + today + branch match, so the code only disambiguates.
     * Ambiguous characters (0/O, 1/I) are excluded.
     */
    private function generateCheckInCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    private function lockResource(string $tenantId, string $resourceId): void
    {
        $rows = DB::select(
            'select id from resources where tenant_id = ? and id = ? for update',
            [$tenantId, $resourceId],
        );

        if ($rows === []) {
            throw CrossTenantReferenceException::forAttribute('resource_id', $resourceId);
        }
    }

    private function assertNoOverlap(
        string $tenantId,
        string $resourceId,
        CarbonImmutable $heldStart,
        CarbonImmutable $heldEnd,
    ): void {
        $blockingStatuses = Appointment::blockingStatuses();
        $placeholders = implode(',', array_fill(0, count($blockingStatuses), '?'));
        $overlaps = DB::select(
            <<<SQL
select appointment_resources.id
from appointment_resources
inner join appointments on appointments.id = appointment_resources.appointment_id
inner join services on services.id = appointments.service_id
where appointment_resources.tenant_id = ?
  and appointment_resources.resource_id = ?
  and appointments.status in ({$placeholders})
  and date_sub(appointments.starts_at, interval services.buffer_before_minutes minute) < ?
  and date_add(appointments.ends_at, interval services.buffer_after_minutes minute) > ?
for update
SQL,
            [
                $tenantId,
                $resourceId,
                ...$blockingStatuses,
                $heldEnd->format('Y-m-d H:i:s'),
                $heldStart->format('Y-m-d H:i:s'),
            ],
        );

        if ($overlaps !== []) {
            throw BookingConflictException::resourceTaken($resourceId);
        }
    }

    /**
     * Read-only availability preview for a slot — the SAME checks book() runs, but
     * without locking or inserting. Used to show a per-occurrence free/conflict
     * indicator before a series is confirmed. Reasons reuse the booking failures.
     *
     * @param  list<string>  $resourceIds
     * @return array{free: bool, reason: string|null}
     */
    public function checkAvailability(string $serviceId, string $branchId, CarbonInterface|string $startsAt, array $resourceIds): array
    {
        $tenantId = (string) $this->tenantContext->id();

        try {
            $service = Service::query()->findOrFail($serviceId);

            if (! Branch::query()->whereKey($branchId)->exists()) {
                return ['free' => false, 'reason' => 'branch_not_found'];
            }

            if (! $service->isAvailableAtBranch($branchId)) {
                return ['free' => false, 'reason' => 'service_not_at_branch'];
            }

            $resources = $this->resourcesFor($resourceIds, $branchId);
            $this->assertResourceTypesMatch($service, $resources);

            $starts = CarbonImmutable::parse($startsAt);
            $ends = $starts->addMinutes($service->default_duration_minutes);
            $heldStart = $starts->subMinutes($service->buffer_before_minutes);
            $heldEnd = $ends->addMinutes($service->buffer_after_minutes);

            if (! $this->branchHours->allowsStart($branchId, $starts->dayOfWeek, $starts->hour * 60 + $starts->minute)) {
                return ['free' => false, 'reason' => 'outside_branch_hours'];
            }

            foreach ($resources as $resource) {
                $this->assertWithinAvailability($resource, $heldStart, $heldEnd);
            }

            foreach ($resources as $resource) {
                if ($this->hasOverlap($tenantId, $resource->id, $heldStart, $heldEnd)) {
                    return ['free' => false, 'reason' => 'resource_taken'];
                }
            }

            return ['free' => true, 'reason' => null];
        } catch (BookingUnavailableException) {
            return ['free' => false, 'reason' => 'outside_availability'];
        } catch (BookingConflictException) {
            return ['free' => false, 'reason' => 'resource_taken'];
        } catch (CrossTenantReferenceException) {
            return ['free' => false, 'reason' => 'invalid_reference'];
        } catch (\Throwable) {
            return ['free' => false, 'reason' => 'invalid'];
        }
    }

    private function hasOverlap(string $tenantId, string $resourceId, CarbonImmutable $heldStart, CarbonImmutable $heldEnd): bool
    {
        $blockingStatuses = Appointment::blockingStatuses();
        $placeholders = implode(',', array_fill(0, count($blockingStatuses), '?'));
        $rows = DB::select(
            <<<SQL
select appointment_resources.id
from appointment_resources
inner join appointments on appointments.id = appointment_resources.appointment_id
inner join services on services.id = appointments.service_id
where appointment_resources.tenant_id = ?
  and appointment_resources.resource_id = ?
  and appointments.status in ({$placeholders})
  and date_sub(appointments.starts_at, interval services.buffer_before_minutes minute) < ?
  and date_add(appointments.ends_at, interval services.buffer_after_minutes minute) > ?
SQL,
            [
                $tenantId,
                $resourceId,
                ...$blockingStatuses,
                $heldEnd->format('Y-m-d H:i:s'),
                $heldStart->format('Y-m-d H:i:s'),
            ],
        );

        return $rows !== [];
    }
}
