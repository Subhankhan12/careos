<?php

namespace Modules\Platform\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Thrown when a tenant-owned model is queried or created with NO established
 * tenant context and NOT in system mode.
 *
 * This is the fail-closed signal: we would rather blow up loudly than return
 * (or write) rows outside a tenant boundary. Never catch this to "fall back"
 * to unscoped access.
 */
class TenantContextMissingException extends RuntimeException
{
    public static function forQuery(Model $model): self
    {
        return new self(sprintf(
            'Refusing to query [%s] with no tenant context (fail-closed). '
            .'Set a TenantContext, or wrap the call in TenantContext::system().',
            $model::class,
        ));
    }

    public static function forCreating(Model $model): self
    {
        return new self(sprintf(
            'Refusing to create [%s] with no tenant context (fail-closed). '
            .'Set a TenantContext, or wrap the call in TenantContext::system().',
            $model::class,
        ));
    }
}
