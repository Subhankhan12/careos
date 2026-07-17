<?php

namespace Modules\Nursing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Nursing\Models\NurseConstraint;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\Branch;
use Modules\Scheduling\Models\Resource;

class DispatchBoardController
{
    public function __invoke(Request $request): Response
    {
        $date = Carbon::parse($request->query('date', Carbon::today()->toDateString()))->toDateString();
        $branch = Branch::query()
            ->when($request->query('branch_id'), fn ($query, $branchId) => $query->whereKey($branchId))
            ->orderBy('name')
            ->firstOrFail();

        Gate::authorize('dispatch.manage', ['branch_id' => $branch->id]);

        $visits = PlannedVisit::query()
            ->with(['patient', 'assignedResource', 'visitPlan.agreement'])
            ->whereDate('scheduled_date', $date)
            ->whereHas('visitPlan.agreement', fn ($query) => $query->where('branch_id', $branch->id))
            ->orderBy('window_start_at')
            ->get();

        foreach ($visits as $visit) {
            $visit->auditRead([
                'surface' => 'nursing.dispatch',
                'date' => $date,
                'branch_id' => $branch->id,
            ]);
        }

        $resources = Resource::query()
            ->where('branch_id', $branch->id)
            ->where('type', Resource::TYPE_PRACTITIONER)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $constraints = NurseConstraint::query()
            ->whereIn('resource_id', $resources->pluck('id')->all())
            ->get()
            ->keyBy('resource_id');

        return Inertia::render('Nursing/Dispatch', [
            'filters' => ['date' => $date, 'branch_id' => $branch->id],
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->all(),
            'unassignedVisits' => $visits
                ->filter(fn (PlannedVisit $visit): bool => $visit->assigned_resource_id === null)
                ->map(fn (PlannedVisit $visit): array => $this->visitSummary($visit))
                ->values()
                ->all(),
            'nurseLanes' => $resources->map(fn (Resource $resource): array => [
                'resource' => [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'qualification' => $constraints->get($resource->id)?->qualification,
                    'max_hours_per_week' => $constraints->get($resource->id)?->max_hours_per_week,
                ],
                'visits' => $visits
                    ->filter(fn (PlannedVisit $visit): bool => $visit->assigned_resource_id === $resource->id)
                    ->map(fn (PlannedVisit $visit): array => $this->visitSummary($visit))
                    ->values()
                    ->all(),
            ])->all(),
            'actions' => [
                'assignUrl' => route('nursing.dispatch.assign'),
                'unassignUrl' => route('nursing.dispatch.unassign'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function visitSummary(PlannedVisit $visit): array
    {
        $patient = Patient::query()->findOrFail($visit->patient_id);

        return [
            'id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'patient' => trim($patient->first_name.' '.$patient->last_name),
            'scheduled_date' => $visit->scheduled_date->toDateString(),
            'window_start_at' => $visit->window_start_at->toDateTimeString(),
            'window_end_at' => $visit->window_end_at->toDateTimeString(),
            'duration_minutes' => $visit->duration_minutes,
            'required_qualification' => $visit->required_qualification,
            'required_competencies' => $visit->required_competencies ?? [],
            'status' => $visit->status,
            'assigned_resource_id' => $visit->assigned_resource_id,
        ];
    }
}
