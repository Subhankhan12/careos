<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\ReconciliationRun;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\SettingsService;

/**
 * The launch-blocker alarm, operationalized.
 *
 * AGENTS.md: a tenant's billing period must reconcile to the unit before any
 * real invoicing goes live. The scheduled daily `billing:reconcile` turns that
 * rule from a one-off gate into a standing signal — but a run that fails is
 * worthless if nobody ever hears about it.
 *
 * A failed run leaves THREE marks, in ascending order of durability:
 *   1. The append-only `reconciliation_runs` row (`passed = false`, full
 *      report) — written by {@see ReconciliationEngine::run()} itself. This is
 *      the evidence.
 *   2. An `error`-level log line — this is what a log drain alerts on.
 *   3. A tenant setting (`billing.reconciliation.alarm`) naming the period and
 *      the failing invariants — a persisted flag an admin surface can read
 *      later WITHOUT scanning run history. No UI is built here; only the signal.
 *
 * The alarm clears only when a LATER run for the SAME period passes. A passing
 * July never clears a broken June: the drift is still there and still unfixed.
 */
class ReconciliationAlarm
{
    public const SETTINGS_KEY = 'billing.reconciliation.alarm';

    public function __construct(private readonly SettingsService $settings) {}

    public function raise(Tenant $tenant, ReconciliationRun $run): void
    {
        $failing = $this->failingInvariants($run);

        Log::error('Billing reconciliation FAILED — invoicing for this period is launch-blocked.', [
            'tenant_id' => $tenant->getKey(),
            'tenant_slug' => $tenant->slug,
            'period' => $run->period,
            'reconciliation_run_id' => $run->getKey(),
            'failed_invariants' => $failing,
        ]);

        $this->settings->set(self::SETTINGS_KEY, [
            'period' => $run->period,
            'reconciliation_run_id' => $run->getKey(),
            'failed_invariants' => $failing,
            'failed_at' => $run->ran_at->toISOString(),
        ], 'array');
    }

    /**
     * Clear the alarm only if the run that passed covers the period the alarm
     * was raised for.
     */
    public function clear(Tenant $tenant, string $period): void
    {
        $current = $this->settings->get(self::SETTINGS_KEY);

        if (! is_array($current) || ($current['period'] ?? null) !== $period) {
            return;
        }

        $this->settings->forget(self::SETTINGS_KEY);
    }

    /**
     * @return list<string>
     */
    private function failingInvariants(ReconciliationRun $run): array
    {
        $invariants = $run->report['invariants'] ?? [];

        return array_values(array_map(
            fn (array $invariant): string => (string) $invariant['invariant'],
            array_filter(
                is_array($invariants) ? $invariants : [],
                fn (array $invariant): bool => ($invariant['ok'] ?? true) !== true,
            ),
        ));
    }
}
