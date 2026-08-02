<?php

namespace Modules\Radiology\Exceptions;

use RuntimeException;

/**
 * Domain errors for imaging-study tracking (RAD.G3) — illegal state transitions, the append-only guard on the
 * state history, and the required-reason guard. Operational record-keeping — no computed judgment (no image
 * finding/CAD, no computed priority).
 */
class ImagingStudyException extends RuntimeException
{
    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Illegal imaging study state transition: {$from} -> {$to}");
    }

    public static function cancellationReasonRequired(): self
    {
        return new self('Cancelling an imaging study requires a reason.');
    }

    public static function invalidEventType(string $type): self
    {
        return new self("Invalid imaging study event type: {$type}");
    }

    public static function appendOnly(): self
    {
        return new self('imaging_study_events are append-only: UPDATE and DELETE are forbidden.');
    }
}
