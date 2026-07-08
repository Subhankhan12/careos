<?php

namespace Modules\Patients\Providers;

use Illuminate\Support\ServiceProvider;

class PatientsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
