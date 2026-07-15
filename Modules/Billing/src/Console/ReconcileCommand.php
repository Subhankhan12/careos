<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Models\ReconciliationRun;
use Modules\Billing\Services\ReconciliationAlarm;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SystemActorResolver;
use Modules\Platform\Services\TenantContext;

/**
 * Two modes:
 *   - Explicit: `billing:reconcile {tenant} {period} {actorId}` — one tenant,
 *     one period, one named actor. The human-invoked monthly-close path.
 *   - Unattended: no arguments — the CURRENT period for every ACTIVE tenant,
 *     each acting as its own resolved billing manager. The scheduler uses this,
 *     and it is the standing monitoring signal for the launch-blocker rule in
 *     AGENTS.md.
 *
 * NOT row-idempotent, deliberately: `reconciliation_runs` is an append-only
 * artifact, so every run adds a row. That is the point — the row IS the signal
 * and the history shows when drift appeared. It is nonetheless SAFE under
 * repeated runs: the engine's `check()` is a pure computation that mutates no
 * billing state, and AccountingExportService gates on the LATEST run for a
 * period, so a daily cadence never wrongly blocks or unblocks an export.
 *
 * A failing tenant never aborts the sweep: every tenant is reconciled, each
 * failure raises its own alarm, and the command exits non-zero at the end so
 * the runner sees it too.
 */
class ReconcileCommand extends Command
{
    protected $signature = 'billing:reconcile
        {tenant? : Omit to sweep every active tenant}
        {period? : Month to reconcile, YYYY-MM; defaults to the current month}
        {actorId? : Omit to resolve each tenant\'s own billing manager}';

    protected $description = 'Run the reconciliation engine for a period and record the monthly-close artifact.';

    public function handle(
        ReconciliationEngine $engine,
        TenantContext $tenantContext,
        SystemActorResolver $actors,
        ReconciliationAlarm $alarm,
    ): int {
        $previousTenant = $tenantContext->current();

        try {
            if ($this->argument('tenant') !== null) {
                return $this->runForOne($engine, $tenantContext, $alarm);
            }

            $period = $this->periodArgument();
            $failed = 0;

            foreach (Tenant::query()->where('status', 'active')->orderBy('id')->get() as $tenant) {
                $tenantContext->set($tenant);
                $actor = $actors->forPermission($tenant, 'billing.manage');

                if (! $actor instanceof User) {
                    $this->warn("Skipped {$tenant->slug}: no user holds billing.manage.");

                    continue;
                }

                $run = $engine->run($tenant, $period, $actor);
                $this->line(sprintf('RECONCILE:%s %s', $run->passed ? 'PASS' : 'FAIL', $tenant->slug));
                $this->raiseOrClear($tenant, $run, $alarm);

                if (! $run->passed) {
                    $failed++;
                }
            }

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        } finally {
            if ($previousTenant !== null) {
                $tenantContext->set($previousTenant);
            } else {
                $tenantContext->forget();
            }
        }
    }

    private function runForOne(
        ReconciliationEngine $engine,
        TenantContext $tenantContext,
        ReconciliationAlarm $alarm,
    ): int {
        $tenant = Tenant::query()->whereKey((string) $this->argument('tenant'))->firstOrFail();
        $tenantContext->set($tenant);

        $actor = User::query()->whereKey((int) $this->argument('actorId'))->firstOrFail();
        $run = $engine->run($tenant, $this->periodArgument(), $actor);

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

        $this->raiseOrClear($tenant, $run, $alarm);

        return $run->passed ? self::SUCCESS : self::FAILURE;
    }

    private function raiseOrClear(Tenant $tenant, ReconciliationRun $run, ReconciliationAlarm $alarm): void
    {
        if ($run->passed) {
            $alarm->clear($tenant, $run->period);

            return;
        }

        $alarm->raise($tenant, $run);
    }

    private function periodArgument(): string
    {
        $period = $this->argument('period');

        return is_string($period) && $period !== '' ? $period : now()->format('Y-m');
    }
}
