<?php

namespace Modules\Hospital\Exceptions;

use InvalidArgumentException;

/**
 * Domain errors for the nursing shift handover (HOSPITAL.G5): an invalid shift label, a missing
 * situation, or an attempt to edit an append-only handover.
 */
class HandoverException extends InvalidArgumentException
{
    public static function invalidShift(string $shift): self
    {
        return new self("Unknown handover shift: {$shift}.");
    }

    public static function situationRequired(): self
    {
        return new self('A handover requires at least a Situation.');
    }

    public static function appendOnly(): self
    {
        return new self('handovers are append-only: a correction is a new handover, never an edit.');
    }
}
