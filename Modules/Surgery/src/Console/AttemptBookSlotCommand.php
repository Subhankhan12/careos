<?php

namespace Modules\Surgery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Exceptions\TheatreException;
use Modules\Surgery\Models\Theatre;
use Modules\Surgery\Services\TheatreSchedulingService;
use Throwable;

/**
 * Attempt one theatre-slot booking from a separate PHP process, for the parallel-hammer test (mirrors
 * pharmacy:attempt-dispense / hospital:attempt-bed-claim). N racing processes book the SAME overlapping block
 * in one theatre; exactly one prints BOOKED: and the rest print CONFLICT: — proving the FOR UPDATE
 * theatre-row lock serialises overlapping bookings (the overlap-lock invariant).
 */
class AttemptBookSlotCommand extends Command
{
    protected $signature = 'surgery:attempt-book-slot
        {tenantId}
        {theatreId}
        {userId}
        {startsAt}
        {durationMinutes}
        {--not-before= : Unix timestamp with microseconds; child waits until this before booking}';

    protected $description = 'Attempt one theatre-slot booking from a separate PHP process for concurrency tests.';

    public function handle(TheatreSchedulingService $scheduling, TenantContext $tenants): int
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
                $theatre = Theatre::query()->findOrFail((string) $this->argument('theatreId'));
                $slot = $scheduling->bookSlot(
                    $user,
                    $theatre,
                    Carbon::parse((string) $this->argument('startsAt')),
                    (int) $this->argument('durationMinutes'),
                );

                $this->line('BOOKED:'.$slot->id);

                return self::SUCCESS;
            } catch (TheatreException $exception) {
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
