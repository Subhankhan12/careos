<?php

namespace Modules\AiCore\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\AiCore\Services\PromptRegistry;
use Modules\AiCore\Services\ToolRegistry;

class AiCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/aicore.php'), 'aicore');

        $this->app->singleton(PromptRegistry::class);
        $this->app->singleton(ToolRegistry::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
