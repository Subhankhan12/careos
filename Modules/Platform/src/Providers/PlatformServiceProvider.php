<?php

namespace Modules\Platform\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Platform\Services\TenantContext;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One TenantContext per request/job; reset on the next.
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
