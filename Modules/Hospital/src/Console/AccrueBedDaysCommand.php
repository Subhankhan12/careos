<?php

namespace Modules\Hospital\Console;

use Illuminate\Console\Command;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Services\BedBillingService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Accrue per-diem bed-day charges for every active inpatient stay, across all active tenants
 * (HOSPITAL.G6). Shaped exactly like nursing:materialize-visits (the map's endorsed pattern): a
 * per-tenant sweep that delegates to an IDEMPOTENT generator — re-running never double-charges (the
 * bed_day_accruals unique key). The charges go through the EXISTING ChargeCaptureService (no money
 * math here). Attributed to the tenant's billing-capable admin (billing.manage). Scheduled daily.
 */
class AccrueBedDaysCommand extends Command
{
    protected $signature = 'hospital:accrue-bed-days';

    protected $description = 'Accrue per-diem bed-day charges for active inpatient stays (idempotent).';

    public function handle(TenantContext $tenants, BedBillingService $billing): int
    {
        $total = 0;
        $previous = $tenants->current();

        foreach (Tenant::query()->where('status', 'active')->orderBy('id')->get() as $tenant) {
            $tenants->set($tenant);

            $actor = $this->resolveBillingActor();
            if (! $actor instanceof User) {
                $this->warn("No billing-capable user for tenant {$tenant->id}; skipped.");

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

    /** The tenant's org_admin (holds billing.manage) — the accrual's billing actor. */
    private function resolveBillingActor(): ?User
    {
        $roleId = Role::query()->where('key', 'org_admin')->value('id');
        if ($roleId === null) {
            return null;
        }

        $userId = RoleAssignment::query()->where('role_id', $roleId)->value('user_id');

        return $userId === null ? null : User::query()->find($userId);
    }
}
