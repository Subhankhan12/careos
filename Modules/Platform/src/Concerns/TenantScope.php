<?php

namespace Modules\Platform\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Services\TenantContext;

/**
 * The fail-closed global scope applied to every model using
 * {@see BelongsToTenant}.
 *
 *  - tenant in context      → constrain to that tenant's rows;
 *  - no tenant, system mode → no constraint (platform-level access);
 *  - no tenant, normal mode → THROW (never return unscoped rows).
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->has()) {
            $builder->where(
                $model->qualifyColumn('tenant_id'),
                $context->id(),
            );

            return;
        }

        if ($context->inSystemMode()) {
            return;
        }

        throw TenantContextMissingException::forQuery($model);
    }
}
