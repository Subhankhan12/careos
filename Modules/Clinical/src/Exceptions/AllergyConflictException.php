<?php

namespace Modules\Clinical\Exceptions;

use RuntimeException;

class AllergyConflictException extends RuntimeException
{
    public static function forSubstance(string $substanceKey): self
    {
        return new self("Medication substance '{$substanceKey}' conflicts with an active documented allergy.");
    }
}
