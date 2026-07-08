<?php

namespace Modules\Platform\Services;

use Closure;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Models\Tenant;

/**
 * Holds the tenant context for the current request or job.
 *
 * Registered as a singleton, so a single instance is shared for the lifetime
 * of one request/job and reset on the next. Everything tenant-scoped reads
 * from here — see {@see BelongsToTenant}.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    /**
     * When true, tenant scoping is bypassed (super-admin / cron / system jobs).
     */
    private bool $systemMode = false;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?string
    {
        return $this->tenant?->getKey();
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    public function inSystemMode(): bool
    {
        return $this->systemMode;
    }

    /**
     * Run the callback with tenant scoping bypassed, then restore the previous
     * mode. Nesting-safe. Use only for genuine platform-level work (super-admin,
     * cron) — never to reach across tenants for a feature.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function system(Closure $callback): mixed
    {
        $previous = $this->systemMode;
        $this->systemMode = true;

        try {
            return $callback();
        } finally {
            $this->systemMode = $previous;
        }
    }
}
