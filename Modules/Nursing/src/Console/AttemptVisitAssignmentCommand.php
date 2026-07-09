<?php

namespace Modules\Nursing\Console;

use Illuminate\Console\Command;
use Modules\Nursing\Exceptions\AssignmentValidationException;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Services\VisitAssignmentService;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource;
use Throwable;

class AttemptVisitAssignmentCommand extends Command
{
    protected $signature = 'nursing:attempt-visit-assignment
        {tenantId}
        {plannedVisitId}
        {resourceId}
        {userId}
        {--not-before= : Unix timestamp with microseconds; child waits until this before assigning}';

    protected $description = 'Attempt one planned visit assignment from a separate PHP process for concurrency tests.';

    public function handle(VisitAssignmentService $assignments, TenantContext $tenants): int
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
                $visit = PlannedVisit::query()->findOrFail((string) $this->argument('plannedVisitId'));
                $resource = Resource::query()->findOrFail((string) $this->argument('resourceId'));
                $assigned = $assignments->assign($visit, $resource, $user);

                $this->line('ASSIGNED:'.$assigned->id);

                return self::SUCCESS;
            } catch (AssignmentValidationException $exception) {
                $this->line('CONFLICT:'.implode(',', $exception->reasons()));

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
