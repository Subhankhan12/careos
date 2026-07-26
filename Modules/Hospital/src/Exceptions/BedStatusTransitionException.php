<?php

namespace Modules\Hospital\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a bed housekeeping status change is not a legal transition per
 * Bed::TRANSITIONS (e.g. free -> cleaning, or occupied -> free without a cleaning
 * turnover), or when free -> occupied is attempted through setStatus() instead of
 * the concurrency-safe claim().
 */
class BedStatusTransitionException extends InvalidArgumentException
{
    public static function illegal(string $bedId, string $from, string $to): self
    {
        return new self("Illegal bed status transition for {$bedId}: {$from} -> {$to}.");
    }

    public static function useClaim(string $bedId): self
    {
        return new self("Bed {$bedId} must be occupied through the concurrency-safe claim(), not setStatus().");
    }
}
