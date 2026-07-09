<?php

namespace Modules\Nursing\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Nursing\Console\MaterializeVisitsCommand;

class NursingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MaterializeVisitsCommand::class,
            ]);
        }
    }
}
