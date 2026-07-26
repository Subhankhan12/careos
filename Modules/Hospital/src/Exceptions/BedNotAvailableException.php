<?php

namespace Modules\Hospital\Exceptions;

use RuntimeException;

/**
 * Thrown when a bed cannot be claimed because it is not free — the losing side of
 * a concurrency-safe claim (only one admission may occupy a bed). Analogous to
 * Scheduling's BookingConflictException.
 */
class BedNotAvailableException extends RuntimeException
{
    public static function notFree(string $bedId, string $status): self
    {
        return new self("Bed {$bedId} is not free (status: {$status}).");
    }
}
