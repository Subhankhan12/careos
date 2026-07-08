<?php

namespace Modules\Scheduling\Exceptions;

use RuntimeException;

class BookingConflictException extends RuntimeException
{
    public static function resourceTaken(string $resourceId): self
    {
        return new self("Resource {$resourceId} is already booked for the requested slot.");
    }
}
