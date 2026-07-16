<?php

namespace Modules\FrontDesk\Providers;

use Illuminate\Support\ServiceProvider;

class FrontDeskServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
