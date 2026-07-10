<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Services\DunningService;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

class DunningRunCommand extends Command
{
    protected $signature = 'billing:dunning-run
        {tenantId}
        {actorId}
        {--as-of= : Date (Y-m-d) to evaluate against; defaults to today}
        {--no-send : Render reminders without delivering them}';

    protected $description = 'Deterministically evaluate overdue invoices and create the next staged dunning events.';

    public function handle(DunningService $service, TenantContext $tenantContext): int
    {
        $tenant = Tenant::query()->whereKey((string) $this->argument('tenantId'))->firstOrFail();
        $tenantContext->set($tenant);

        $actor = User::query()->whereKey((int) $this->argument('actorId'))->firstOrFail();
        $asOf = $this->option('as-of');

        $events = $service->evaluate(
            $tenant,
            is_string($asOf) && $asOf !== '' ? $asOf : now()->toDateString(),
            $actor,
            ! $this->option('no-send'),
        );

        $this->line('DUNNING_CREATED:'.count($events));

        return self::SUCCESS;
    }
}
