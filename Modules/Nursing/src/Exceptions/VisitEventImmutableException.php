<?php

namespace Modules\Nursing\Exceptions;

use RuntimeException;

class VisitEventImmutableException extends RuntimeException
{
    public static function make(): self
    {
        return new self('visit_events are append-only: update/delete is forbidden.');
    }
}
