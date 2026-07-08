<?php

namespace Modules\Scheduling\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Scheduling\Console\AttemptBookingCommand;
use Modules\Scheduling\Console\DispatchAppointmentRemindersCommand;

class SchedulingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AttemptBookingCommand::class,
                DispatchAppointmentRemindersCommand::class,
            ]);
        }
    }
}
