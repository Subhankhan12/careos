<?php

namespace Modules\Radiology\Exceptions;

use RuntimeException;

/**
 * Domain errors for radiology billing (RAD.G5) — a non-positive price (a guard, NOT money math), an unbillable
 * exam (no tariff code), and nothing-to-invoice. Radiology billing is STRICTLY ORCHESTRATION over the existing
 * engine; it computes no money. The fee is a tariff, never a report/finding verdict (the fence).
 */
class RadiologyBillingException extends RuntimeException
{
    public static function nonPositivePrice(): self
    {
        return new self('An imaging exam price must be a positive amount in minor units.');
    }

    public static function examNotBillable(string $radiologyOrderId): self
    {
        return new self("Imaging order {$radiologyOrderId} has no billable exam code.");
    }

    public static function nothingToInvoice(string $patientId): self
    {
        return new self("No validated, uninvoiced charges to invoice for patient {$patientId}.");
    }
}
