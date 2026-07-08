<?php

namespace Modules\Platform\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant-owned row attempts to reference another row that belongs
 * to a different tenant (e.g. a department pointing at a branch outside its
 * tenant). Fail-closed: cross-tenant linkage is never silently allowed.
 */
class CrossTenantReferenceException extends RuntimeException
{
    public static function forAttribute(string $attribute, string $value): self
    {
        return new self(sprintf(
            'Refusing to link [%s = %s]: the referenced record belongs to a different tenant (fail-closed).',
            $attribute,
            $value,
        ));
    }
}
