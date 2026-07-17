<?php

namespace Modules\Nursing\Services;

use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Nursing\Events\PlannedVisitChanged;
use Modules\Nursing\Exceptions\AssignmentValidationException;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\VisitPlan;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource;

class VisitAssignmentService
{
    public function __construct(
        private readonly AssignmentValidator $validator,
        private readonly TenantContext $tenantContext,
    ) {}

    public function assign(PlannedVisit $plannedVisit, Resource $resource, User $actor): PlannedVisit
    {
        $this->authorize($plannedVisit, $actor);
        $this->tenantContext->id();

        $warnings = [];

        $assigned = DB::transaction(function () use ($plannedVisit, $resource, $actor, &$warnings): PlannedVisit {
            $lockedVisit = PlannedVisit::query()
                ->whereKey($plannedVisit->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedResource = Resource::query()
                ->whereKey($resource->id)
                ->lockForUpdate()
                ->first();

            if ($lockedResource === null) {
                throw CrossTenantReferenceException::forAttribute('resource_id', $resource->id);
            }

            if ($lockedResource->type !== Resource::TYPE_PRACTITIONER) {
                throw new InvalidArgumentException('Planned visits may only be assigned to practitioner resources.');
            }

            if (! $lockedResource->active) {
                throw new InvalidArgumentException('Planned visits may only be assigned to active resources.');
            }

            if ($lockedVisit->status !== PlannedVisit::STATUS_PLANNED && $lockedVisit->status !== PlannedVisit::STATUS_ASSIGNED) {
                throw new InvalidArgumentException('Only planned or assigned visits can be assigned.');
            }

            $candidates = PlannedVisit::query()
                ->where('assigned_resource_id', $lockedResource->id)
                ->where('status', PlannedVisit::STATUS_ASSIGNED)
                ->where(function ($query) use ($lockedVisit): void {
                    $query
                        ->whereBetween('window_start_at', [
                            $lockedVisit->window_start_at->copy()->startOfWeek(CarbonInterface::MONDAY)->toDateTimeString(),
                            $lockedVisit->window_start_at->copy()->endOfWeek(CarbonInterface::SUNDAY)->toDateTimeString(),
                        ])
                        ->orWhereDate('window_start_at', $lockedVisit->window_start_at->toDateString());
                })
                ->lockForUpdate()
                ->get();

            $result = $this->validator->evaluate($lockedVisit, $lockedResource, $candidates);

            if (! $result->passes()) {
                throw new AssignmentValidationException($result->blocking);
            }

            $warnings = $result->warnings;

            $lockedVisit->forceFill([
                'assigned_resource_id' => $lockedResource->id,
                'assigned_at' => now(),
                'assigned_by' => $actor->id,
                'status' => PlannedVisit::STATUS_ASSIGNED,
            ])->save();

            return $lockedVisit->refresh();
        }, 3);

        // Surface non-blocking soft-competency advisories to the dispatcher.
        $assigned->assignmentWarnings = $warnings;

        Event::dispatch(new PlannedVisitChanged($assigned, 'planned_visit.assigned', array_filter([
            'assigned_resource_id' => $assigned->assigned_resource_id,
            'assigned_by' => $assigned->assigned_by,
            'assigned_at' => $assigned->assigned_at?->toDateTimeString(),
            // Trail: the dispatcher assigned despite one or more soft warnings.
            'soft_competency_warnings' => $warnings === [] ? null : $warnings,
        ], fn ($value): bool => $value !== null), $actor));

        return $assigned;
    }

    public function unassign(PlannedVisit $plannedVisit, User $actor): PlannedVisit
    {
        $this->authorize($plannedVisit, $actor);

        $visit = DB::transaction(function () use ($plannedVisit): PlannedVisit {
            $lockedVisit = PlannedVisit::query()
                ->whereKey($plannedVisit->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedVisit->forceFill([
                'assigned_resource_id' => null,
                'assigned_at' => null,
                'assigned_by' => null,
                'status' => PlannedVisit::STATUS_PLANNED,
            ])->save();

            return $lockedVisit->refresh();
        }, 3);

        Event::dispatch(new PlannedVisitChanged($visit, 'planned_visit.unassigned', [], $actor));

        return $visit;
    }

    private function authorize(PlannedVisit $visit, User $actor): void
    {
        $visitPlan = VisitPlan::query()->whereKey($visit->visit_plan_id)->firstOrFail();
        $agreement = ServiceAgreement::query()->whereKey($visitPlan->service_agreement_id)->firstOrFail();

        if (! Gate::forUser($actor)->allows('dispatch.manage', ['branch_id' => $agreement->branch_id])) {
            throw new AuthorizationException('This user cannot manage nursing dispatch.');
        }
    }
}
