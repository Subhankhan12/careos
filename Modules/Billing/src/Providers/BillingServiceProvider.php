<?php

namespace Modules\Billing\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Billing\Console\AttemptInvoiceIssueCommand;
use Modules\Billing\Console\AttemptPaymentAllocationCommand;
use Modules\Billing\Console\DunningRunCommand;

class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AttemptInvoiceIssueCommand::class,
                AttemptPaymentAllocationCommand::class,
                DunningRunCommand::class,
            ]);
        }
    }
}
