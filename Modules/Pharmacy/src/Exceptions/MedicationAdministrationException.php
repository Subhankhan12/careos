<?php

namespace Modules\Pharmacy\Exceptions;

use RuntimeException;

/**
 * Domain errors for the eMAR (PHARMACY.G3): an invalid administration outcome or an append-only violation.
 * Operational validation only — the outcome is the nurse's recorded FACT, nothing clinical is judged.
 */
class MedicationAdministrationException extends RuntimeException
{
    public static function invalidOutcome(string $outcome): self
    {
        return new self("Unknown administration outcome: {$outcome}.");
    }

    public static function appendOnly(): self
    {
        return new self('medication_administrations are append-only: a correction is a new administration, never an edit.');
    }
}
