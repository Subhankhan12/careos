<?php

namespace Modules\Lab\Exceptions;

use RuntimeException;

/**
 * Domain errors for specimen tracking (LAB.G3) — illegal state transitions, the append-only guard on the state
 * history, and the required-reason guard. Operational record-keeping — no computed judgment.
 */
class SpecimenException extends RuntimeException
{
    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Illegal specimen state transition: {$from} -> {$to}");
    }

    public static function rejectionReasonRequired(): self
    {
        return new self('Rejecting a specimen requires a reason.');
    }

    public static function invalidEventType(string $type): self
    {
        return new self("Invalid specimen event type: {$type}");
    }

    public static function appendOnly(): self
    {
        return new self('specimen_events are append-only: UPDATE and DELETE are forbidden.');
    }
}
