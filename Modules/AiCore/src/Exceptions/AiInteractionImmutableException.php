<?php

namespace Modules\AiCore\Exceptions;

use RuntimeException;

class AiInteractionImmutableException extends RuntimeException
{
    public static function make(): self
    {
        return new self('ai_interactions is append-only: update/delete is forbidden.');
    }
}
