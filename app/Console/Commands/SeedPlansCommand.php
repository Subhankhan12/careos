<?php

namespace App\Console\Commands;

use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Console\Command;
use Modules\Platform\Models\Plan;
use Modules\Platform\Services\FeatureService;

/**
 * DEPLOY.PROV (M3) — seed the real subscription plans, production-safely.
 *
 * WHY THIS EXISTS AS A COMMAND. The plans already lived in `PlanCatalogSeeder`,
 * which `DatabaseSeeder` calls — but the deploy runbook's release sequence ran
 * `migrate --force` and never `db:seed`, so on a fresh production database the
 * `plans` table stayed EMPTY. That failed silently and expensively:
 * `tenants.plan_id` is nullable, and {@see FeatureService}
 * falls through to `false` for every feature when a tenant has no plan — so
 * telehealth, EVV and ai_drafting were all quietly OFF with nothing to indicate why.
 *
 * A named command makes the step impossible to forget and safe to re-run, and it
 * does NOT drag in the demo seeders: it calls the plan catalog directly, so there is
 * no path from here to a demo tenant.
 *
 * Idempotent: the underlying seeder is `updateOrCreate` keyed on `key`, so running
 * this twice updates in place and never duplicates.
 */
class SeedPlansCommand extends Command
{
    protected $signature = 'plans:seed';

    protected $description = 'Seed the real subscription plans (idempotent, production-safe, demo-free).';

    public function handle(PlanCatalogSeeder $seeder): int
    {
        $before = Plan::query()->count();

        $seeder->run();

        $after = Plan::query()->count();

        $this->info(sprintf(
            'Plans seeded: %d total (%d new).',
            $after,
            max(0, $after - $before),
        ));

        foreach (Plan::query()->orderBy('key')->get() as $plan) {
            $features = collect($plan->features ?? [])
                ->map(fn (bool $on, string $key): string => ($on ? '+' : '-').$key)
                ->implode(' ');

            $this->line(sprintf('  %-12s %-12s %s', $plan->key, $plan->name, $features));
        }

        $this->newLine();
        $this->comment('Assign one to every tenant — a tenant with no plan has every feature OFF.');

        return self::SUCCESS;
    }
}
