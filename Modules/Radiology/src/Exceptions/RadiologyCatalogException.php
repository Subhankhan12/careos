<?php

namespace Modules\Radiology\Exceptions;

use RuntimeException;

/**
 * Domain errors for the imaging exam catalog (RAD.G1) — validation guards only. NO computed image
 * read/finding/grade logic lives here or anywhere in the module: the catalog records modality/body-part
 * reference data; a computed image interpretation is a hard medical-device non-goal.
 */
class RadiologyCatalogException extends RuntimeException
{
    public static function codeAndNameRequired(): self
    {
        return new self('An imaging exam needs a code and a name.');
    }
}
