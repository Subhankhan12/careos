<?php

namespace Modules\Billing\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Billing\Console\AttemptInvoiceIssueCommand;
use Modules\Billing\Console\AttemptPaymentAllocationCommand;

class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AttemptInvoiceIssueCommand::class,
                AttemptPaymentAllocationCommand::class,
            ]);
        }
    }
}
