<?php

namespace Modules\Clinical\Console;

use Illuminate\Console\Command;
use Modules\Clinical\Services\RecallEngine;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;

/**
 * Daily deterministic recall evaluation across every ACTIVE tenant.
 *
 * Idempotent: {@see RecallEngine} generates due recalls through firstOrCreate
 * keyed on (tenant, patient, rule, due_on), and the table carries the matching
 * unique index — so a second run on the same day adds nothing.
 *
 * Actor: deliberately null. The engine takes `?User $actor` and writes its
 * per-recall clinical audit event only when there is a real human to attribute
 * it to. An unattended nightly sweep has no human, and resolving some clinician
 * to stand in would put a false name on a clinical audit trail — worse than the
 * absence. The recall rows themselves still carry their own timestamps, and
 * every later lifecycle change (contacted / booked / completed / dismissed)
 * is audited against the real person who made it.
 *
 * Nothing here selects patients by inference: the criteria are exact matches on
 * documented problem codes and missing encounter types.
 */
class EvaluateRecallsCommand extends Command
{
    protected $signature = 'clinical:evaluate-recalls';

    protected $description = 'Evaluate active recall rules for every active tenant and generate due recalls.';

    public function handle(TenantContext $tenants, RecallEngine $engine): int
    {
        $previousTenant = $tenants->current();
        $total = 0;

        try {
            foreach (Tenant::query()->where('status', 'active')->orderBy('id')->get() as $tenant) {
                $total += $engine->evaluate($tenant)->count();
            }
        } finally {
            if ($previousTenant !== null) {
                $tenants->set($previousTenant);
            } else {
                $tenants->forget();
            }
        }

        $this->line("Recalls generated: {$total}.");

        return self::SUCCESS;
    }
}
