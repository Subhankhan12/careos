<?php

namespace Modules\Scheduling\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Models\Branch;
use Modules\Platform\Services\BranchHoursService;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\Service;

class AvailableSlotFinder
{
    /** Default scan window (07:00–19:00) for branches that have not configured opening hours. */
    /**
     * The stride the scan advances by when looking for the next candidate start.
     *
     * It is a CONSTANT, not a setting: nothing persists a per-tenant or per-service granularity, and
     * SCHED.P2 deliberately did not add a control for one (a control that persists nothing the
     * finder reads would be an unbacked affordance, D-176). It is public so the service-catalog
     * screen can show admins the real value their durations are scanned against rather than
     * restating "30" in a template — one source, the same rule the duration and buffers follow.
     */
    public const SLOT_STRIDE_MINUTES = 30;

    private const DEFAULT_OPEN_MINUTES = 7 * 60;

    private const DEFAULT_CLOSE_MINUTES = 19 * 60;

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BranchHoursService $branchHours,
    ) {}

    /**
     * @return list<array{starts_at: string, ends_at: string, resource_ids: list<string>}>
     */
    public function forServiceBranchDate(
        Service $service,
        string $branchId,
        CarbonInterface|string $date,
        int $limit = 24,
    ): array {
        $date = CarbonImmutable::parse($date)->startOfDay();
        $resourcesByType = $this->resourcesByType($service, $branchId);

        if ($resourcesByType === []) {
            return [];
        }

        // Bound the scan to the branch's opening hours for this weekday. An unconfigured
        // branch keeps the default 07:00–19:00 window; a configured-but-closed day yields
        // no slots at all.
        $window = $this->branchHours->scanWindow($branchId, $date->dayOfWeek, self::DEFAULT_OPEN_MINUTES, self::DEFAULT_CLOSE_MINUTES);

        if ($window === null) {
            return [];
        }

        $slots = [];
        $cursor = $date->addMinutes($window['open']);
        $endOfDay = $date->addMinutes($window['close']);

        /*
         * "Now", expressed as the PRACTICE'S WALL CLOCK, in the same naive base the cursor uses.
         *
         * The cursor is a naive local value: this scan builds it from a date plus an opening-hour
         * offset, and `resource_availability` windows are naive local times too, so the whole scan
         * lives in the practice's clock. Comparing it against a UTC `now()` would be wrong by the
         * tenant's offset — and anchoring the cursor to a real zone instead would silently
         * de-synchronise it from those availability windows (measured: it shifted the first offered
         * slot from 07:00 to 09:00 on a Europe/Zurich branch).
         *
         * So the clock is converted to the branch's zone and re-read as naive digits. Nothing about
         * the emitted slots changes; only the comparison becomes honest.
         */
        $nowLocal = CarbonImmutable::parse(
            CarbonImmutable::now($this->branchTimezone($branchId))->format('Y-m-d H:i:s')
        );

        while ($cursor->lessThanOrEqualTo($endOfDay) && count($slots) < $limit) {
            /*
             * NEVER OFFER A SLOT THAT HAS ALREADY STARTED (QA-FIX.1b, P1-H3, D-194).
             *
             * The scan used to walk from the branch's opening time to its closing time with no
             * reference to the clock, so on the CURRENT day it offered the whole morning back —
             * labelled "soonest" — and the reschedule/quick-book paths booked it. Every consumer
             * of this finder inherits the fix: the day-board quick-book, staff reschedule, portal
             * self-booking and the public booking form.
             *
             * The boundary is strictly "has already started", not "starts within N minutes".
             * SCHED.P2 established there is no min-notice setting anywhere in the product, and
             * inventing one here would be an unbacked policy the backend cannot honour (D-170).
             */
            if ($cursor->lessThanOrEqualTo($nowLocal)) {
                $cursor = $cursor->addMinutes(self::SLOT_STRIDE_MINUTES);

                continue;
            }

            $ends = $cursor->addMinutes($service->default_duration_minutes);
            $resourceIds = [];

            foreach ($resourcesByType as $resources) {
                $resource = $this->firstFreeResource($resources, $service, $cursor, $ends);

                if ($resource === null) {
                    $resourceIds = [];
                    break;
                }

                $resourceIds[] = $resource->id;
            }

            if ($resourceIds !== []) {
                $slots[] = [
                    'starts_at' => $cursor->toDateTimeString(),
                    'ends_at' => $ends->toDateTimeString(),
                    'resource_ids' => $resourceIds,
                ];
            }

            $cursor = $cursor->addMinutes(self::SLOT_STRIDE_MINUTES);
        }

        return $slots;
    }

    /**
     * The zone the branch's clock runs in — the one a slot's wall-clock digits belong to.
     *
     * Mirrors the branch→config fallback `AppointmentSeriesService` already uses for RRULE
     * expansion, so the two places that must agree about "the practice's clock" resolve it the
     * same way. Reading Platform's `Branch` from Scheduling is the established direction.
     */
    private function branchTimezone(string $branchId): string
    {
        $timezone = (string) (Branch::query()->find($branchId)?->getAttribute('timezone') ?? '');

        if ($timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return (string) config('app.timezone');
        }

        return $timezone;
    }

    /**
     * @return array<string, list<resource>>
     */
    private function resourcesByType(Service $service, string $branchId): array
    {
        $grouped = [];

        foreach (array_values(array_unique($service->requires_resource_types ?? [])) as $type) {
            $resources = Resource::query()
                ->where('branch_id', $branchId)
                ->where('type', $type)
                ->where('active', true)
                ->orderBy('name')
                ->get()
                ->filter(fn (Resource $resource): bool => true)
                ->values()
                ->all();

            if ($resources === []) {
                return [];
            }

            $grouped[$type] = $resources;
        }

        return $grouped;
    }

    /**
     * @param  list<resource>  $resources
     */
    private function firstFreeResource(
        array $resources,
        Service $service,
        CarbonImmutable $starts,
        CarbonImmutable $ends,
    ): ?Resource {
        foreach ($resources as $resource) {
            if ($this->fitsAvailability($resource, $service, $starts, $ends)
                && ! $this->hasOverlap($resource, $service, $starts, $ends)) {
                return $resource;
            }
        }

        return null;
    }

    private function fitsAvailability(
        Resource $resource,
        Service $service,
        CarbonImmutable $starts,
        CarbonImmutable $ends,
    ): bool {
        $heldStart = $starts->subMinutes($service->buffer_before_minutes);
        $heldEnd = $ends->addMinutes($service->buffer_after_minutes);

        foreach ($this->availability->windowsFor($resource, $heldStart->toDateString(), $heldEnd->toDateString()) as $window) {
            if ($window['start_at']->lessThanOrEqualTo($heldStart)
                && $window['end_at']->greaterThanOrEqualTo($heldEnd)) {
                return true;
            }
        }

        return false;
    }

    private function hasOverlap(
        Resource $resource,
        Service $service,
        CarbonImmutable $starts,
        CarbonImmutable $ends,
    ): bool {
        $heldStart = $starts->subMinutes($service->buffer_before_minutes);
        $heldEnd = $ends->addMinutes($service->buffer_after_minutes);
        $statuses = Appointment::blockingStatuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        return DB::selectOne(
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
limit 1
SQL,
            [
                $resource->tenant_id,
                $resource->id,
                ...$statuses,
                $heldEnd->format('Y-m-d H:i:s'),
                $heldStart->format('Y-m-d H:i:s'),
            ],
        ) !== null;
    }
}
