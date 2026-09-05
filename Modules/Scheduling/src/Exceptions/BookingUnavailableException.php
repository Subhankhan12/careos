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

    /**
     * The requested start has already passed (QA-FIX.1b, P1-H3, D-194).
     *
     * A booking is an appointment to keep; a start in the past cannot be kept. This is raised for
     * a stale or forged slot, independently of what the slot finder offered — the finder not
     * offering something is not the same as the server refusing it.
     */
    public static function startsInThePast(string $startsAt): self
    {
        return new self("The requested start {$startsAt} has already passed.");
    }
}
