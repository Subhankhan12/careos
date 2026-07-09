<?php

namespace Modules\Nursing\Console;

use Illuminate\Console\Command;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Services\VisitPlanGenerator;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;

class MaterializeVisitsCommand extends Command
{
    protected $signature = 'nursing:materialize-visits {--weeks=8 : Number of weeks ahead to materialize}';

    protected $description = 'Materialize planned nursing visits for active visit plans across a horizon.';

    public function handle(TenantContext $tenants, VisitPlanGenerator $generator): int
    {
        $weeks = max(1, (int) $this->option('weeks'));
        $from = now()->toDateString();
        $to = now()->addWeeks($weeks)->toDateString();
        $total = 0;
        $previousTenant = $tenants->current();

        foreach (Tenant::query()->where('status', 'active')->orderBy('id')->get() as $tenant) {
            $tenants->set($tenant);

            foreach (VisitPlan::query()->where('active', true)->orderBy('id')->get() as $visitPlan) {
                $total += $generator->materialize($visitPlan, $from, $to);
            }
        }

        if ($previousTenant !== null) {
            $tenants->set($previousTenant);
        } else {
            $tenants->forget();
        }

        $this->line("Planned visits materialized: {$total}.");

        return self::SUCCESS;
    }
}
