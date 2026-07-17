<?php

namespace Modules\Reporting\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Reporting\Console\ReportingSummaryCommand;

/**
 * Reporting is a READ-ONLY aggregation layer: it owns no tables, runs no
 * migrations, and never writes. It reads other modules' tenant-owned data through
 * their query surfaces and returns plain numeric facts (P0P.G14).
 */
class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReportingSummaryCommand::class,
            ]);
        }
    }
}
