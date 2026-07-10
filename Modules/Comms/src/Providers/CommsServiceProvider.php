<?php

namespace Modules\Comms\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Comms\Contracts\TelehealthProvider;
use Modules\Comms\Providers\Telehealth\LiveKitProvider;

class CommsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/telehealth.php'), 'telehealth');

        // Swappable telehealth adapter (D-G1); tests bind the Fake instead.
        $this->app->bind(TelehealthProvider::class, LiveKitProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
