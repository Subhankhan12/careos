<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Services\PaymentService;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Throwable;

class AttemptPaymentAllocationCommand extends Command
{
    protected $signature = 'billing:attempt-payment-allocation
        {tenantId}
        {paymentId}
        {invoiceId}
        {amount}
        {actorId}
        {--not-before= : Unix timestamp before which the command waits}';

    protected $description = 'Test helper: attempt to allocate one payment to one invoice for parallel hammer tests.';

    public function handle(PaymentService $service, TenantContext $tenantContext): int
    {
        $notBefore = $this->option('not-before');

        if (is_string($notBefore) && $notBefore !== '') {
            while (microtime(true) < (float) $notBefore) {
                usleep(1000);
            }
        }

        $tenant = Tenant::query()->whereKey((string) $this->argument('tenantId'))->firstOrFail();
        $tenantContext->set($tenant);

        try {
            $payment = Payment::query()->whereKey((string) $this->argument('paymentId'))->firstOrFail();
            $invoice = Invoice::query()->whereKey((string) $this->argument('invoiceId'))->firstOrFail();
            $actor = User::query()->whereKey((int) $this->argument('actorId'))->firstOrFail();

            $allocation = $service->allocate($payment, $invoice, (int) $this->argument('amount'), $actor);

            $this->line('ALLOCATED:'.$allocation->id);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->line('FAILED:'.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
