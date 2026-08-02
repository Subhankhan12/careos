<?php

namespace Modules\Radiology\Exceptions;

use RuntimeException;

/**
 * Domain errors for the imaging-order overlay (RAD.G2) — validation guards + the append-only guard. The order
 * itself is a REUSED Clinical `Order`; this covers only the thin imaging overlay (modality/body-part +
 * priority). The priority is a recorded flag from a closed set, never a computed value.
 */
class RadiologyOrderException extends RuntimeException
{
    public static function invalidPriority(string $priority): self
    {
        return new self("Invalid imaging order priority: {$priority}");
    }

    public static function appendOnly(): self
    {
        return new self('radiology_orders are append-only: UPDATE and DELETE are forbidden.');
    }
}
