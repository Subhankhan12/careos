<?php

namespace Modules\Pharmacy\Exceptions;

use RuntimeException;

/**
 * Domain errors for the tenant-authored formulary (PHARMACY.G1): an invalid dosage form or a blank
 * medication name/code. Operational validation only — nothing clinical is judged here.
 */
class FormularyException extends RuntimeException
{
    public static function invalidForm(string $form): self
    {
        return new self("Unknown dosage form: {$form}.");
    }

    public static function nameRequired(): self
    {
        return new self('A formulary item requires a medication name.');
    }

    public static function codeRequired(): self
    {
        return new self('A formulary item requires a tenant code.');
    }
}
