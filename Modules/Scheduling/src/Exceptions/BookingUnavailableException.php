<?php

namespace Modules\Scheduling\Exceptions;

use RuntimeException;

class BookingUnavailableException extends RuntimeException
{
    public static function outsideAvailability(string $resourceId): self
    {
        return new self("Resource {$resourceId} is not available for the requested slot.");
    }
}
