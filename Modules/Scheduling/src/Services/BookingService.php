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
    ) {}

    /**
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
        );
    }

    /**
     * @param  list<string>  $resourceIds
     */
    public function bookOnline(
        string $serviceId,
        ?string $patientId,
        string $branchId,
        CarbonInterface|string $startsAt,
        array $resourceIds,
        ?string $notes = null,
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
    ): Appointment {
        $tenantId = $this->tenantContext->id();

        if ($authorize && ($bookedBy === null || ! Gate::forUser($bookedBy)->allows('appointment.manage', ['branch_id' => $branchId]))) {
            throw new AuthorizationException('This user cannot manage appointments.');
        }

        $service = Service::query()->findOrFail($serviceId);

        if (! Branch::query()->whereKey($branchId)->exists()) {
            throw CrossTenantReferenceException::forAttribute('branch_id', $branchId);
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
        ): Appointment {
            foreach ($resourceIds as $resourceId) {
                $this->lockResource($tenantId, $resourceId);
                $this->assertNoOverlap($tenantId, $resourceId, $heldStart, $heldEnd);
            }

            $appointment = Appointment::query()->create([
                'rescheduled_from_id' => $rescheduledFromId,
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
}
