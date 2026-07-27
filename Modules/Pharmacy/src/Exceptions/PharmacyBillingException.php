<?php

namespace Modules\Pharmacy\Exceptions;

use RuntimeException;

/**
 * Domain errors for pharmacy billing (PHARMACY.G5): a non-positive price, or nothing to invoice. Pharmacy
 * billing is strictly ORCHESTRATION over the existing engine — it computes no charge/VAT/line-total math.
 */
class PharmacyBillingException extends RuntimeException
{
    public static function nonPositivePrice(): self
    {
        return new self('A medication price must be a positive number of minor units.');
    }

    public static function nothingToInvoice(string $patientId): self
    {
        return new self("Patient {$patientId} has no validated charges to invoice in the period.");
    }
}
