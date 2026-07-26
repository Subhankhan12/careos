<?php

namespace Modules\Hospital\Console;

use Illuminate\Console\Command;
use Modules\Hospital\Exceptions\BedNotAvailableException;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Services\BedService;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Throwable;

/**
 * Attempt one concurrency-safe bed claim from a separate PHP process, for the
 * parallel-hammer test (mirrors scheduling:attempt-booking). Exactly one of N racing
 * processes prints CLAIMED:; the rest print CONFLICT:.
 */
class AttemptBedClaimCommand extends Command
{
    protected $signature = 'hospital:attempt-bed-claim
        {tenantId}
        {bedId}
        {userId}
        {--not-before= : Unix timestamp with microseconds; child waits until this before claiming}';

    protected $description = 'Attempt one bed claim from a separate PHP process for concurrency tests.';

    public function handle(BedService $beds, TenantContext $tenants): int
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
                $bed = Bed::query()->findOrFail((string) $this->argument('bedId'));
                $claimed = $beds->claim($user, $bed);

                $this->line('CLAIMED:'.$claimed->id);

                return self::SUCCESS;
            } catch (BedNotAvailableException $exception) {
                $this->line('CONFLICT:'.$exception->getMessage());

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
