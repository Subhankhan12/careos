<?php

namespace Modules\Scheduling\Console;

use Illuminate\Console\Command;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Exceptions\BookingConflictException;
use Modules\Scheduling\Services\BookingService;
use Throwable;

class AttemptBookingCommand extends Command
{
    protected $signature = 'scheduling:attempt-booking
        {tenantId}
        {serviceId}
        {patientId}
        {branchId}
        {resourceIds}
        {userId}
        {startsAt}
        {--not-before= : Unix timestamp with microseconds; child waits until this before booking}';

    protected $description = 'Attempt one booking from a separate PHP process for concurrency tests.';

    public function handle(BookingService $bookings, TenantContext $tenants): int
    {
        $notBefore = $this->option('not-before');

        if ($notBefore !== null && $notBefore !== '') {
            while (microtime(true) < (float) $notBefore) {
                usleep(1000);
            }
        }

        $tenant = Tenant::query()->findOrFail((string) $this->argument('tenantId'));
        $user = User::query()->findOrFail((int) $this->argument('userId'));
        $patientId = (string) $this->argument('patientId');
        $resourceIds = array_values(array_filter(explode(',', (string) $this->argument('resourceIds'))));

        $previousTenant = $tenants->current();
        $tenants->set($tenant);

        try {
            try {
                $appointment = $bookings->book(
                    (string) $this->argument('serviceId'),
                    $patientId !== '-' ? $patientId : null,
                    (string) $this->argument('branchId'),
                    (string) $this->argument('startsAt'),
                    $resourceIds,
                    $user,
                );

                $this->line('BOOKED:'.$appointment->id);

                return self::SUCCESS;
            } catch (BookingConflictException $exception) {
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
