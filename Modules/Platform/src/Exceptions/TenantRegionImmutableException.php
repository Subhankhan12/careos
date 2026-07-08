<?php

namespace Modules\Platform\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to change a tenant's region after creation.
 *
 * Region is bound to the region cell a tenant lives in (D-011: PHI never
 * crosses cells). It is fixed at creation and can never be mutated.
 */
class TenantRegionImmutableException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'A tenant region is immutable after creation and cannot be changed.'
        );
    }
}
