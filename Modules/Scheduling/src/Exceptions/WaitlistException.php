<?php

namespace Modules\Scheduling\Exceptions;

use RuntimeException;

class WaitlistException extends RuntimeException
{
    public static function invalidStatus(string $status): self
    {
        return new self("Waitlist entry status {$status} cannot perform this action.");
    }

    public static function slotDoesNotMatch(): self
    {
        return new self('The offered slot does not match this waitlist entry.');
    }
}
