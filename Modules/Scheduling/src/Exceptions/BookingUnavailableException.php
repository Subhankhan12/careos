<?php

namespace Modules\Scheduling\Exceptions;

use RuntimeException;

class BookingUnavailableException extends RuntimeException
{
    public static function outsideAvailability(string $resourceId): self
    {
        return new self("Resource {$resourceId} is not available for the requested slot.");
    }

    public static function outsideBranchHours(string $branchId): self
    {
        return new self("Branch {$branchId} is closed at the requested time.");
    }

    /**
     * The branch has soft-suspended online bookings (accepts_online_bookings=false): it takes no
     * NEW online bookings, though it stays active for staff and keeps its existing appointments.
     */
    public static function onlineBookingsSuspended(string $branchId): self
    {
        return new self("Branch {$branchId} is not accepting online bookings.");
    }
}
