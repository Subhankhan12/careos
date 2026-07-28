<?php

namespace Modules\Surgery\Exceptions;

use RuntimeException;

/**
 * Domain errors for the surgical case (SURGERY.G1). G1 validates only the minimal shape (a procedure
 * description is required); the lifecycle state machine + its illegal-transition errors are SURGERY.G2.
 */
class SurgicalCaseException extends RuntimeException
{
    public static function procedureRequired(): self
    {
        return new self('A surgical case must record a procedure description.');
    }
}
