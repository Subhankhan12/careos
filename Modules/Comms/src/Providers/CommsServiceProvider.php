<?php

namespace Modules\Comms\Providers;

use Illuminate\Support\ServiceProvider;

class CommsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
