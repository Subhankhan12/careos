<?php

namespace Modules\Audit\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Audit\Console\EnsureAuditPartitions;
use Modules\Audit\Contracts\AuditContext;
use Modules\Audit\Support\NullAuditContext;

class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Default context; the application layer may bind a richer one first
        // (bindIf leaves an existing binding untouched).
        $this->app->bindIf(AuditContext::class, NullAuditContext::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                EnsureAuditPartitions::class,
            ]);
        }
    }
}
