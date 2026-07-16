<?php

namespace Modules\Scheduling\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Scheduling\Console\AttemptBookingCommand;
use Modules\Scheduling\Console\AttemptOfferAcceptCommand;
use Modules\Scheduling\Console\DispatchAppointmentRemindersCommand;
use Modules\Scheduling\Console\ExpireWaitlistOffersCommand;

class SchedulingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AttemptBookingCommand::class,
                AttemptOfferAcceptCommand::class,
                DispatchAppointmentRemindersCommand::class,
                ExpireWaitlistOffersCommand::class,
            ]);
        }
    }
}
