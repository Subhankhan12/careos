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

    /**
     * `P9-H1` (QA-FIX.12d) — the bed a patient is in is not housekeeping's to reassign.
     *
     * `occupied -> cleaning` is a LEGAL transition and must stay legal: it is how a turnover begins once
     * the patient has left. What was missing is that nothing looked at the STAY, so the transition could
     * be applied while the patient was still admitted — and `release()` then refuses forever, because it
     * requires the bed to be `occupied`, leaving the stay impossible to discharge or transfer.
     */
    public static function occupiedByStay(string $bedId, string $stayId): self
    {
        return new self(
            "Bed {$bedId} is occupied by admitted stay {$stayId}; discharge or transfer the patient first."
        );
    }
}
