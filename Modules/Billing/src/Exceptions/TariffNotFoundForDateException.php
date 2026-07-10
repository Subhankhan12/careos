<?php

namespace Modules\Billing\Exceptions;

use RuntimeException;

class TariffNotFoundForDateException extends RuntimeException
{
    public static function forCode(string $code, string $serviceDate): self
    {
        return new self("No active tariff item [{$code}] covers service date [{$serviceDate}].");
    }
}
