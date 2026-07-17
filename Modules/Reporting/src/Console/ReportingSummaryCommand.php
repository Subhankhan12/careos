<?php

namespace Modules\Reporting\Console;

use Illuminate\Console\Command;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\SystemActorResolver;
use Modules\Platform\Services\TenantContext;
use Modules\Reporting\Services\ReportingService;

/**
 * Ops/debug proof of the reporting layer end to end. Prints the summary bundle as
 * JSON — a command only, NOT a UI (P0P.G14). The unattended actor is resolved per
 * D-067: the lowest-id tenant user holding `reporting.view` tenant-wide; a tenant
 * with nobody qualified is refused, not escalated.
 */
class ReportingSummaryCommand extends Command
{
    protected $signature = 'reporting:summary
        {tenant : Tenant id or slug}
        {from : Range start date (Y-m-d)}
        {to : Range end date (Y-m-d)}
        {--branch= : Optional branch id filter for branch-dimensioned metrics}';

    protected $description = 'Print the tenant-scoped reporting summary bundle as JSON (read-only; no UI).';

    public function handle(ReportingService $reporting, TenantContext $tenants, SystemActorResolver $actors): int
    {
        $tenantKey = (string) $this->argument('tenant');
        $tenant = Tenant::query()
            ->where('id', $tenantKey)
            ->orWhere('slug', $tenantKey)
            ->first();

        if ($tenant === null) {
            $this->error('Unknown tenant: '.$tenantKey);

            return self::FAILURE;
        }

        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            $actor = $actors->forPermission($tenant, 'reporting.view');

            if ($actor === null) {
                $this->error('No tenant user holds reporting.view; refusing (D-067).');

                return self::FAILURE;
            }

            $summary = $reporting->summary(
                $actor,
                (string) $this->argument('from'),
                (string) $this->argument('to'),
                $this->option('branch') !== null ? (string) $this->option('branch') : null,
            );

            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } finally {
            if ($previous !== null) {
                $tenants->set($previous);
            } else {
                $tenants->forget();
            }
        }
    }
}
