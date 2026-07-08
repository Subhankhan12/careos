<?php

namespace Modules\Scheduling\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\Service;

class AvailableSlotFinder
{
    public function __construct(private readonly AvailabilityService $availability) {}

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

        $slots = [];
        $cursor = $date->setTime(7, 0);
        $endOfDay = $date->setTime(19, 0);

        while ($cursor->lessThanOrEqualTo($endOfDay) && count($slots) < $limit) {
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

            $cursor = $cursor->addMinutes(30);
        }

        return $slots;
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
