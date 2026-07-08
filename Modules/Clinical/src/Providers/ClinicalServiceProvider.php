<?php

namespace Modules\Clinical\Providers;

use Illuminate\Support\ServiceProvider;

class ClinicalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
