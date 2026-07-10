<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Services\AccountingExportService;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Throwable;

class ExportCommand extends Command
{
    protected $signature = 'billing:export
        {tenant}
        {period : Month to export, YYYY-MM}
        {actorId}';

    protected $description = 'Export a period accounting CSV (refuses unless the period reconciliation passed).';

    public function handle(AccountingExportService $service, TenantContext $tenantContext): int
    {
        $tenant = Tenant::query()->whereKey((string) $this->argument('tenant'))->firstOrFail();
        $tenantContext->set($tenant);

        $actor = User::query()->whereKey((int) $this->argument('actorId'))->firstOrFail();

        try {
            $path = $service->export($tenant, (string) $this->argument('period'), $actor);
            $this->line('EXPORTED:'.$path);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->line('BLOCKED:'.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
