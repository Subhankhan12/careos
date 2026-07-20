<?php

namespace App\Services;

use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource;

/**
 * Bookable-resource writes for the tenant-admin surface (CLINIC.W8c). Lives in the APP
 * layer for the same reason as {@see BranchService}: resource deactivation safety spans
 * modules (Scheduling's Resource + its appointments), and the arch rules forbid a
 * cross-module guard from living inside a single module. All writes are tenant-scoped
 * (BelongsToTenant) and audited via the AppServiceProvider model hooks
 * (resource.created / resource.updated / resource.activated / resource.deactivated).
 */
class ResourceService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Resource
    {
        return Resource::create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(Resource $resource, array $data): Resource
    {
        $resource->update($data);

        return $resource;
    }

    public function setActive(Resource $resource, bool $active): void
    {
        $resource->update(['active' => $active]);
    }

    /**
     * Future appointments that would be stranded if the resource were removed — the
     * blocking-status set (booked/confirmed/arrived/in-progress), starting from now,
     * for appointments that consume this resource via the appointment_resources pivot.
     * The deactivation guard blocks on this so scheduled care is never orphaned.
     */
    public function futureAppointmentCount(string $resourceId): int
    {
        return Appointment::query()
            ->where('starts_at', '>=', now())
            ->whereIn('status', Appointment::blockingStatuses())
            ->whereHas('resources', fn ($query) => $query->whereKey($resourceId))
            ->count();
    }
}
