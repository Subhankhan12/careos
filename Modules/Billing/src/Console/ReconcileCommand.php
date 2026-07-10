<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

class ReconcileCommand extends Command
{
    protected $signature = 'billing:reconcile
        {tenant}
        {period : Month to reconcile, YYYY-MM}
        {actorId}';

    protected $description = 'Run the reconciliation engine for a period and record the monthly-close artifact.';

    public function handle(ReconciliationEngine $engine, TenantContext $tenantContext): int
    {
        $tenant = Tenant::query()->whereKey((string) $this->argument('tenant'))->firstOrFail();
        $tenantContext->set($tenant);

        $actor = User::query()->whereKey((int) $this->argument('actorId'))->firstOrFail();
        $run = $engine->run($tenant, (string) $this->argument('period'), $actor);

        $this->line('RECONCILE:'.($run->passed ? 'PASS' : 'FAIL'));

        foreach ($run->report['invariants'] as $invariant) {
            $this->line(sprintf(
                '%s %s expected=%d actual=%d delta=%d rows=%d',
                $invariant['ok'] ? '[OK]' : '[!!]',
                $invariant['invariant'],
                $invariant['expected_minor'],
                $invariant['actual_minor'],
                $invariant['delta_minor'],
                count($invariant['rows']),
            ));
        }

        return $run->passed ? self::SUCCESS : self::FAILURE;
    }
}
