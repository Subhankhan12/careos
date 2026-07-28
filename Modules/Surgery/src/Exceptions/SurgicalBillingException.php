<?php

namespace Modules\Surgery\Exceptions;

use RuntimeException;

/**
 * Domain errors for surgical billing (SURGERY.G5): a non-positive price, or nothing to invoice. Surgical
 * billing is strictly ORCHESTRATION over the existing engine — it computes no charge/VAT/line-total math. A
 * price is a RATE, never a clinical/appropriateness verdict.
 */
class SurgicalBillingException extends RuntimeException
{
    public static function nonPositivePrice(): self
    {
        return new self('A surgical price must be a positive number of minor units.');
    }

    public static function nothingToInvoice(string $patientId): self
    {
        return new self("Patient {$patientId} has no validated surgical charges to invoice in the period.");
    }
}
