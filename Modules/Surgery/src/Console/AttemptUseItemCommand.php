<?php

namespace Modules\Surgery\Console;

use Illuminate\Console\Command;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Exceptions\SurgicalInventoryException;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalItem;
use Modules\Surgery\Services\SurgicalUsageService;
use Throwable;

/**
 * Attempt to use one unit of a surgical item in a case from a separate PHP process, for the parallel-hammer
 * test (mirrors pharmacy:attempt-dispense / surgery:attempt-book-slot). N racing processes use the SAME last
 * unit; exactly one prints USED: and the rest print INSUFFICIENT: — proving the FOR UPDATE stock lock
 * serialises the decrement (no oversell, no negative on-hand).
 */
class AttemptUseItemCommand extends Command
{
    protected $signature = 'surgery:attempt-use-item
        {tenantId}
        {caseId}
        {itemId}
        {userId}
        {--not-before= : Unix timestamp with microseconds; child waits until this before using}';

    protected $description = 'Attempt one surgical-item use from a separate PHP process for concurrency tests.';

    public function handle(SurgicalUsageService $usage, TenantContext $tenants): int
    {
        $notBefore = $this->option('not-before');

        if ($notBefore !== null && $notBefore !== '') {
            while (microtime(true) < (float) $notBefore) {
                usleep(1000);
            }
        }

        $tenant = Tenant::query()->findOrFail((string) $this->argument('tenantId'));
        $user = User::query()->findOrFail((int) $this->argument('userId'));

        $previousTenant = $tenants->current();
        $tenants->set($tenant);

        try {
            try {
                $case = SurgicalCase::query()->findOrFail((string) $this->argument('caseId'));
                $item = SurgicalItem::query()->findOrFail((string) $this->argument('itemId'));
                $record = $usage->recordUsage($user, $case, $item, 1);

                $this->line('USED:'.$record->id);

                return self::SUCCESS;
            } catch (SurgicalInventoryException $exception) {
                $this->line('INSUFFICIENT:'.$exception->getMessage());

                return self::SUCCESS;
            } catch (Throwable $exception) {
                $this->line('FAILED:'.$exception::class.':'.$exception->getMessage());

                return self::FAILURE;
            }
        } finally {
            if ($previousTenant !== null) {
                $tenants->set($previousTenant);
            } else {
                $tenants->forget();
            }
        }
    }
}
