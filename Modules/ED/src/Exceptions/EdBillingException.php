<?php

namespace Modules\ED\Exceptions;

use RuntimeException;

/**
 * Domain errors for ED billing (ED.G6) — orchestration guards only (a non-positive price, nothing to invoice).
 * NO money math lives here or anywhere in the module: the billing engine owns all pricing/line-total/VAT.
 */
class EdBillingException extends RuntimeException
{
    public static function nonPositivePrice(): self
    {
        return new self('An ED tariff price must be a positive integer (minor units).');
    }

    public static function nothingToInvoice(string $patientId): self
    {
        return new self("No validated, uninvoiced ED charges to invoice for patient {$patientId}.");
    }
}
