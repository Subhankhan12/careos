<?php

namespace Modules\Scheduling\Console;

use Illuminate\Console\Command;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Services\WaitlistOfferService;

/**
 * Expire waitlist offers past their TTL for every active tenant. Idempotent and
 * safe to run repeatedly (only offers still in 'offered' with expires_at <= now
 * are touched). A system action — no human actor is attributed.
 */
class ExpireWaitlistOffersCommand extends Command
{
    protected $signature = 'scheduling:expire-waitlist-offers';

    protected $description = 'Expire timed-out waitlist offers so freed slots can be re-offered.';

    public function handle(TenantContext $tenants, WaitlistOfferService $offers): int
    {
        $total = 0;
        $previousTenant = $tenants->current();

        foreach (Tenant::query()->where('status', 'active')->orderBy('id')->get() as $tenant) {
            $tenants->set($tenant);
            $total += $offers->expireDue();
        }

        if ($previousTenant !== null) {
            $tenants->set($previousTenant);
        } else {
            $tenants->forget();
        }

        $this->line("Waitlist offers expired: {$total}.");

        return self::SUCCESS;
    }
}
