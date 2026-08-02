<?php

namespace Modules\Lab\Exceptions;

use RuntimeException;

/**
 * Domain errors for lab billing (LAB.G6) — a non-positive price (a guard, NOT money math), an unbillable test
 * (no tariff code), and nothing-to-invoice. Lab billing is STRICTLY ORCHESTRATION over the existing engine; it
 * computes no money. The fee is a tariff, never a result-severity verdict (the fence).
 */
class LabBillingException extends RuntimeException
{
    public static function nonPositivePrice(): self
    {
        return new self('A lab test price must be a positive amount in minor units.');
    }

    public static function testNotBillable(string $labOrderId): self
    {
        return new self("Lab order {$labOrderId} has no billable test code.");
    }

    public static function nothingToInvoice(string $patientId): self
    {
        return new self("No validated, uninvoiced charges to invoice for patient {$patientId}.");
    }
}
