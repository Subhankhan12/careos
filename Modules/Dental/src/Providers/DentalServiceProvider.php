<?php

namespace Modules\Dental\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Dental vertical (DENTAL.G1 — foundation). This gate ships the domain data model
 * only: the tooth/odontogram state (record-not-judge) + dental RBAC + a thin service.
 * No UI, no commands yet (the chart UI is DENTAL.G2).
 */
class DentalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
