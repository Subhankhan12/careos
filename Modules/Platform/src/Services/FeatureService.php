<?php

namespace Modules\Platform\Services;

use Modules\Platform\Models\FeatureFlag;

/**
 * Resolves whether a feature is enabled for the current tenant.
 *
 * Resolution order:
 *   1. tenant override — an explicit {@see FeatureFlag} row wins;
 *   2. plan default    — the current tenant's plan `features[$key]`;
 *   3. false           — unknown features are off.
 *
 * Reading the override is tenant-scoped by BelongsToTenant, so this fails closed
 * when no tenant context is established.
 */
class FeatureService
{
    public function __construct(private readonly TenantContext $context) {}

    public function enabled(string $key): bool
    {
        $flag = FeatureFlag::query()->where('key', $key)->first();

        if ($flag !== null) {
            return $flag->enabled;
        }

        $plan = $this->context->current()?->plan;

        if ($plan !== null) {
            return (bool) ($plan->features[$key] ?? false);
        }

        return false;
    }
}
