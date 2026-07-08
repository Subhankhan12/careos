<?php

namespace App\Providers;

use App\Audit\PlatformAuditContext;
use Illuminate\Support\ServiceProvider;
use Modules\Audit\Contracts\AuditContext;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Wire the audit context to the Platform-aware implementation. This
        // binding lives in the app layer so neither module depends on the other.
        $this->app->bind(AuditContext::class, PlatformAuditContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
