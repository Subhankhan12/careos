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

    public static function offerNotOpen(string $status): self
    {
        return new self("Waitlist offer status {$status} cannot be accepted or declined.");
    }

    public static function offerExpired(): self
    {
        return new self('This waitlist offer has expired.');
    }

    public static function alreadyOffered(): self
    {
        return new self('This waitlist entry already has an open offer.');
    }
}
