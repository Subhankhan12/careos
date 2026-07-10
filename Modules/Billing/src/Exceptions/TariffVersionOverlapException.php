<?php

namespace Modules\Billing\Exceptions;

use RuntimeException;

class TariffVersionOverlapException extends RuntimeException
{
    public static function forCatalog(string $key, string $validFrom, ?string $validTo): self
    {
        $range = $validTo === null ? "{$validFrom}.." : "{$validFrom}..{$validTo}";

        return new self("Tariff catalog [{$key}] has an overlapping effective-date range [{$range}].");
    }
}
