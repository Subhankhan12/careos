<?php

namespace Modules\Lab\Exceptions;

use RuntimeException;

/**
 * Domain errors for the lab-order overlay (LAB.G2) — validation guards + the append-only guard. The order
 * itself is a REUSED Clinical `Order`; this covers only the thin lab overlay (specimen + priority).
 */
class LabOrderException extends RuntimeException
{
    public static function invalidPriority(string $priority): self
    {
        return new self("Invalid lab order priority: {$priority}");
    }

    public static function appendOnly(): self
    {
        return new self('lab_orders are append-only: UPDATE and DELETE are forbidden.');
    }
}
