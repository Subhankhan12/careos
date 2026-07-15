<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Services\DunningService;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SystemActorResolver;
use Modules\Platform\Services\TenantContext;

/**
 * Two modes:
 *   - Explicit: `billing:dunning-run {tenantId} {actorId}` — one tenant, one
 *     named actor. The human-invoked path, unchanged.
 *   - Unattended: no arguments — every ACTIVE tenant, each acting as its own
 *     resolved billing manager ({@see SystemActorResolver}). The scheduler
 *     uses this path.
 *
 * Idempotent: {@see DunningService::evaluate()} creates the events that SHOULD
 * exist at the as-of date, and `dunning_events` carries
 * unique(tenant, invoice, level) as the backstop — so a level fires at most
 * once per invoice however often the sweep runs.
 */
class DunningRunCommand extends Command
{
    protected $signature = 'billing:dunning-run
        {tenantId? : Omit to sweep every active tenant}
        {actorId? : Omit to resolve each tenant\'s own billing manager}
        {--as-of= : Date (Y-m-d) to evaluate against; defaults to today}
        {--no-send : Render reminders without delivering them}';

    protected $description = 'Deterministically evaluate overdue invoices and create the next staged dunning events.';

    public function handle(
        DunningService $service,
        TenantContext $tenantContext,
        SystemActorResolver $actors,
    ): int {
        $asOfOption = $this->option('as-of');
        $asOf = is_string($asOfOption) && $asOfOption !== '' ? $asOfOption : now()->toDateString();
        $deliver = ! $this->option('no-send');
        $previousTenant = $tenantContext->current();

        try {
            if ($this->argument('tenantId') !== null) {
                return $this->runForOne($service, $tenantContext, $asOf, $deliver);
            }

            $created = 0;

            foreach (Tenant::query()->where('status', 'active')->orderBy('id')->get() as $tenant) {
                $tenantContext->set($tenant);
                $actor = $actors->forPermission($tenant, 'billing.manage');

                // No billing manager => no dunning for this tenant. An unattended
                // run is never escalated to somebody who lacks the permission.
                if (! $actor instanceof User) {
                    $this->warn("Skipped {$tenant->slug}: no user holds billing.manage.");

                    continue;
                }

                $created += count($service->evaluate($tenant, $asOf, $actor, $deliver));
            }

            $this->line('DUNNING_CREATED:'.$created);

            return self::SUCCESS;
        } finally {
            if ($previousTenant !== null) {
                $tenantContext->set($previousTenant);
            } else {
                $tenantContext->forget();
            }
        }
    }

    private function runForOne(
        DunningService $service,
        TenantContext $tenantContext,
        string $asOf,
        bool $deliver,
    ): int {
        $tenant = Tenant::query()->whereKey((string) $this->argument('tenantId'))->firstOrFail();
        $tenantContext->set($tenant);

        $actor = User::query()->whereKey((int) $this->argument('actorId'))->firstOrFail();
        $events = $service->evaluate($tenant, $asOf, $actor, $deliver);

        $this->line('DUNNING_CREATED:'.count($events));

        return self::SUCCESS;
    }
}
