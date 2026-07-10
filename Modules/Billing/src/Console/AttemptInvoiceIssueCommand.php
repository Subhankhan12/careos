<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\IssueService;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Throwable;

class AttemptInvoiceIssueCommand extends Command
{
    protected $signature = 'billing:attempt-invoice-issue
        {tenantId}
        {invoiceId}
        {actorId}
        {--not-before= : Unix timestamp before which the command waits}';

    protected $description = 'Test helper: attempt to issue one invoice for parallel hammer tests.';

    public function handle(IssueService $service, TenantContext $tenantContext): int
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
            $invoice = Invoice::query()->whereKey((string) $this->argument('invoiceId'))->firstOrFail();
            $actor = User::query()->whereKey((int) $this->argument('actorId'))->firstOrFail();
            $issued = $service->issue($invoice, $actor);

            $this->line('ISSUED:'.$issued->number);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->line('FAILED:'.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
