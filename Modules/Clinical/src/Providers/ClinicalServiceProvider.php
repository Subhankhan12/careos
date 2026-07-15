<?php

namespace Modules\Clinical\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Clinical\Console\EvaluateRecallsCommand;

class ClinicalServiceProvider extends ServiceProvider
{
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
