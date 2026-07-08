<?php

namespace Modules\People\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\People\Console\RefreshCredentialStatuses;

class PeopleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RefreshCredentialStatuses::class,
            ]);
        }
    }
}
