<?php

namespace Modules\Nursing\Providers;

use Illuminate\Support\ServiceProvider;

class NursingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
