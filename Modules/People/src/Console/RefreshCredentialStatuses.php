<?php

namespace Modules\People\Console;

use Illuminate\Console\Command;
use Modules\People\Models\Credential;
use Modules\People\Services\CredentialService;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;

class RefreshCredentialStatuses extends Command
{
    protected $signature = 'credentials:refresh-status';

    protected $description = 'Recompute stored credential statuses from expiry dates for every active tenant.';

    /**
     * Idempotent: the status is DERIVED from `expires_on` through
     * CredentialService, so a second run recomputes the same value and updates
     * nothing. Manually revoked credentials are excluded and stay revoked.
     *
     * Active tenants only — an unattended sweep has no business writing to a
     * suspended tenant's records.
     */
    public function handle(CredentialService $credentials, TenantContext $tenants): int
    {
        $previousTenant = $tenants->current();
        $checked = 0;
        $updated = 0;

        try {
            foreach (Tenant::query()->where('status', 'active')->orderBy('id')->get() as $tenant) {
                $tenants->set($tenant);

                Credential::query()
                    ->where('status', '!=', Credential::STATUS_REVOKED)
                    ->orderBy('id')
                    ->each(function (Credential $credential) use ($credentials, &$checked, &$updated): void {
                        $checked++;

                        if ($credentials->refreshStatus($credential)) {
                            $updated++;
                        }
                    });
            }
        } finally {
            if ($previousTenant !== null) {
                $tenants->set($previousTenant);
            } else {
                $tenants->forget();
            }
        }

        $this->components->info(sprintf('Credentials checked: %d; updated: %d.', $checked, $updated));

        return self::SUCCESS;
    }
}
