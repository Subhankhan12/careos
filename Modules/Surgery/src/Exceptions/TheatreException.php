<?php

namespace Modules\Surgery\Exceptions;

use RuntimeException;

/**
 * Domain errors for theatres + theatre-scheduling (SURGERY.G1): a missing name, a non-positive block
 * duration, or a scheduling conflict (a surgery would overlap another in the same theatre — the overlap-lock
 * invariant, docs/HOSPITAL-PHASE5-SURGERY-MAP.md §2.1).
 */
class TheatreException extends RuntimeException
{
    public static function nameRequired(): self
    {
        return new self('A theatre must have a name.');
    }

    public static function nonPositiveDuration(): self
    {
        return new self('A theatre slot must have a positive duration.');
    }

    public static function slotConflict(string $theatreId): self
    {
        return new self("Theatre {$theatreId} already has a surgery booked in the requested block.");
    }
}
