<?php

namespace Modules\Hospital\Console;

use Illuminate\Console\Command;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Services\BedBillingService;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SystemActorResolver;
use Modules\Platform\Services\TenantContext;

/**
 * Accrue per-diem bed-day charges for every active inpatient stay, across all active tenants
 * (HOSPITAL.G6). Shaped exactly like nursing:materialize-visits (the map's endorsed pattern): a
 * per-tenant sweep that delegates to an IDEMPOTENT generator — re-running never double-charges (the
 * bed_day_accruals unique key). The charges go through the EXISTING ChargeCaptureService (no money
 * math here). Scheduled daily.
 *
 * ATTRIBUTION (QA-FIX.11b, `P9-H6`, D-225). The charges are attributed to a user who GENUINELY HOLDS
 * `billing.manage`, resolved by {@see SystemActorResolver::forPermission()} — the same resolver
 * `billing:dunning-run`, `billing:reconcile` and `reporting:summary` already use. This docblock
 * previously CLAIMED that attribution while the code picked an arbitrary org_admin row.
 */
class AccrueBedDaysCommand extends Command
{
    protected $signature = 'hospital:accrue-bed-days';

    protected $description = 'Accrue per-diem bed-day charges for active inpatient stays (idempotent).';

    public function handle(TenantContext $tenants, BedBillingService $billing, SystemActorResolver $actors): int
    {
        $total = 0;
        $previous = $tenants->current();

        foreach (Tenant::query()->where('status', 'active')->orderBy('id')->get() as $tenant) {
            $tenants->set($tenant);

            $actor = $actors->forPermission($tenant, 'billing.manage');

            // No billing manager => no accrual for this tenant. An unattended run is never
            // attributed to somebody who was never given the permission (D-195, D-216).
            if (! $actor instanceof User) {
                $this->warn("Skipped {$tenant->slug}: no user holds billing.manage.");

                continue;
            }

            foreach (Stay::query()->where('status', Stay::STATUS_ADMITTED)->orderBy('id')->get() as $stay) {
                $total += $billing->accrueBedDays($actor, $stay);
            }
        }

        if ($previous !== null) {
            $tenants->set($previous);
        } else {
            $tenants->forget();
        }

        $this->line("Bed-days accrued: {$total}.");

        return self::SUCCESS;
    }
}
