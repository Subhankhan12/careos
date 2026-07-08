<?php

namespace Modules\Platform\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;

/**
 * Marks an Eloquent model as tenant-owned and enforces fail-closed tenancy.
 *
 * Applying this trait:
 *   - adds a fail-closed global scope ({@see TenantScope}) so reads are always
 *     constrained to the current tenant — or throw when no tenant is
 *     established and we are not in system mode;
 *   - stamps tenant_id from the current context on create (or throws);
 *   - exposes the tenant() relation.
 *
 * CROSS-TENANT SHARING RULE
 * -------------------------
 * Sharing data across tenants is NEVER done by widening this scope, dropping it
 * with withoutGlobalScope(), or querying in system mode for a feature. The only
 * sanctioned path is an explicit share object (built in a later gate) that
 * grants a named, audited grant from one tenant to another. If you find yourself
 * reaching for the global scope to "just show both tenants", stop — that is the
 * leak this trait exists to prevent.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if ($context->has()) {
                if (empty($model->getAttribute('tenant_id'))) {
                    $model->setAttribute('tenant_id', $context->id());
                }

                return;
            }

            if (! $context->inSystemMode()) {
                throw TenantContextMissingException::forCreating($model);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
