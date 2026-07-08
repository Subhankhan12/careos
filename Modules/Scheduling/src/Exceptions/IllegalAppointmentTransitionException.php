<?php

namespace Modules\Scheduling\Exceptions;

use RuntimeException;

class IllegalAppointmentTransitionException extends RuntimeException
{
    public static function fromTo(string $from, string $to): self
    {
        return new self("Illegal appointment transition from {$from} to {$to}.");
    }
}
