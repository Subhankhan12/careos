<?php

namespace Modules\Hospital\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Hospital\Console\AccrueBedDaysCommand;
use Modules\Hospital\Console\AttemptBedClaimCommand;

/**
 * Hospital / inpatient vertical (HOSPITAL.G1 — foundation). This gate ships the
 * bed/ward/unit domain model + a concurrency-safe bed-claim primitive + inpatient
 * RBAC. No ADT workflow yet (HOSPITAL.G2) and no UI (the ward board is HOSPITAL.G3).
 * Cross-module audit composition lives in the app layer, so this module never
 * depends on Audit.
 */
class HospitalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AttemptBedClaimCommand::class,
                AccrueBedDaysCommand::class,
            ]);
        }
    }
}
