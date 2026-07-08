<?php

namespace Modules\Scheduling\Console;

use Illuminate\Console\Command;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Services\ReminderDispatcher;

class DispatchAppointmentRemindersCommand extends Command
{
    protected $signature = 'appointments:dispatch-reminders';

    protected $description = 'Enqueue due appointment reminder jobs for every tenant.';

    public function handle(TenantContext $tenants, ReminderDispatcher $dispatcher): int
    {
        $total = 0;
        $previousTenant = $tenants->current();

        foreach (Tenant::query()->where('status', 'active')->orderBy('id')->get() as $tenant) {
            $tenants->set($tenant);
            $total += $dispatcher->dispatchDue();
        }

        if ($previousTenant !== null) {
            $tenants->set($previousTenant);
        } else {
            $tenants->forget();
        }

        $this->line("Appointment reminder jobs queued: {$total}.");

        return self::SUCCESS;
    }
}
