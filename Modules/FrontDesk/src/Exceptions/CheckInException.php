<?php

namespace Modules\FrontDesk\Exceptions;

use RuntimeException;

class CheckInException extends RuntimeException
{
    public static function notToday(): self
    {
        return new self('This appointment is not scheduled for today.');
    }

    public static function wrongBranch(): self
    {
        return new self('This appointment is not at this branch.');
    }

    public static function notCheckInable(string $status): self
    {
        return new self("An appointment in status {$status} cannot be checked in.");
    }
}
