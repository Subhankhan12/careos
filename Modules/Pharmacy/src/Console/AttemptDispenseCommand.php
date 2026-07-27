<?php

namespace Modules\Pharmacy\Console;

use Illuminate\Console\Command;
use Modules\Pharmacy\Exceptions\DispensingException;
use Modules\Pharmacy\Models\MedicationOrder;
use Modules\Pharmacy\Services\DispensingService;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Throwable;

/**
 * Attempt one concurrency-safe dispense (1 unit) from a separate PHP process, for the parallel-hammer test
 * (mirrors hospital:attempt-bed-claim). Exactly one of N racing processes prints DISPENSED: for the last
 * unit; the rest print INSUFFICIENT: — proving the FOR UPDATE lock serialises stock decrements.
 */
class AttemptDispenseCommand extends Command
{
    protected $signature = 'pharmacy:attempt-dispense
        {tenantId}
        {orderId}
        {userId}
        {--not-before= : Unix timestamp with microseconds; child waits until this before dispensing}';

    protected $description = 'Attempt one dispense from a separate PHP process for concurrency tests.';

    public function handle(DispensingService $dispensing, TenantContext $tenants): int
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
                $order = MedicationOrder::query()->findOrFail((string) $this->argument('orderId'));
                $dispense = $dispensing->dispense($user, $order, 1);

                $this->line('DISPENSED:'.$dispense->id);

                return self::SUCCESS;
            } catch (DispensingException $exception) {
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
