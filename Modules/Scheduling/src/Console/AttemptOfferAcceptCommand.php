<?php

namespace Modules\Scheduling\Console;

use Illuminate\Console\Command;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Exceptions\BookingConflictException;
use Modules\Scheduling\Exceptions\WaitlistException;
use Modules\Scheduling\Models\WaitlistOffer;
use Modules\Scheduling\Services\WaitlistOfferService;
use Throwable;

/**
 * Accept one waitlist offer from a separate PHP process — used by the
 * concurrent-accept hammer to prove that two accepts of the SAME freed slot
 * resolve to exactly one appointment (BookingService's resource lock is the
 * arbiter, exactly as in the booking hammer).
 */
class AttemptOfferAcceptCommand extends Command
{
    protected $signature = 'scheduling:attempt-offer-accept
        {tenantId}
        {offerId}
        {userId}
        {--not-before= : Unix timestamp with microseconds; child waits until this before accepting}';

    protected $description = 'Attempt one waitlist offer accept from a separate PHP process for concurrency tests.';

    public function handle(WaitlistOfferService $offers, TenantContext $tenants): int
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
            $offer = WaitlistOffer::query()->findOrFail((string) $this->argument('offerId'));

            try {
                $appointment = $offers->accept($offer, $user);
                $this->line('ACCEPTED:'.$appointment->id);

                return self::SUCCESS;
            } catch (BookingConflictException|WaitlistException $exception) {
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
