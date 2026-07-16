<?php

namespace Modules\Clinical\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Clinical\Console\EvaluateRecallsCommand;
use Modules\Clinical\Contracts\LabConnectivity;
use Modules\Clinical\Services\ManualLabConnectivity;

class ClinicalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Electronic lab transmission/ingestion is an interface with ONLY the
        // Manual (no-op) implementation. Real HL7/FHIR connectivity is deferred
        // partner work — there is no live client to bind.
        $this->app->bind(LabConnectivity::class, ManualLabConnectivity::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                EvaluateRecallsCommand::class,
            ]);
        }
    }
}
