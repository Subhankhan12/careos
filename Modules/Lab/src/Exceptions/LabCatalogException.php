<?php

namespace Modules\Lab\Exceptions;

use RuntimeException;

/**
 * Domain errors for the lab test catalog (LAB.G1) — validation guards only. NO computed grade/threshold logic
 * lives here or anywhere in the module: a reference range is recorded reference data, never a graded verdict.
 */
class LabCatalogException extends RuntimeException
{
    public static function codeAndNameRequired(): self
    {
        return new self('A lab test needs a code and a name.');
    }
}
