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
        Gate::authorize('dispatch.manage', ['branch_id' => $request->query('branch_id')]);

        $date = Carbon::parse($request->query('date', Carbon::today()->toDateString()))->toDateString();
        $branch = Branch::query()
            ->where('active', true)
            ->when($request->query('branch_id'), fn ($query, $branchId) => $query->whereKey($branchId))
            ->orderBy('name')
            ->first();

        /*
         * QF13c-M2 — AUTHORISE BEFORE RESOLVING THE BRANCH.
         *
         * This request used to resolve `firstOrFail()` before the Gate. An unauthorised caller then got
         * 404 when a tenant had no branch and 403 when it did: an existence oracle for a record they could
         * not read. D-185 requires refusals to match in shape, so the Gate above runs before every branch
         * lookup. DayBoardController (DEPLOY-FIX.1a) and AvailabilityController (DEPLOY-FIX.2) establish
         * the rest of this exact shape: active branches only, then an honest null-branch payload.
         */
        if (! $branch instanceof Branch) {
            return Inertia::render('Nursing/Dispatch', $this->emptyDispatch($date));
        }

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
            'branches' => Branch::query()->where('active', true)->orderBy('name')->get(['id', 'name'])->all(),
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
            'actions' => $this->actionUrls(),
        ]);
    }

    /**
     * The dispatch payload when the tenant has no active branch.
     *
     * A zero-active-branch tenant is a real state, reached either on first provisioning or after the
     * last quiet branch is deactivated. Every collection is genuinely empty because there is no active
     * branch to scope it to. Keep the action URLs alongside the populated payload so the page's contract
     * cannot silently drift before a branch is added (DEPLOY-FIX.1a / DEPLOY-FIX.2 precedent).
     *
     * @return array<string, mixed>
     */
    private function emptyDispatch(string $date): array
    {
        return [
            'filters' => ['date' => $date, 'branch_id' => null],
            'branches' => [],
            'unassignedVisits' => [],
            'nurseLanes' => [],
            'actions' => $this->actionUrls(),
        ];
    }

    /**
     * Dispatch action endpoints, shared by populated and empty payloads.
     *
     * @return array<string, string>
     */
    private function actionUrls(): array
    {
        return [
            'assignUrl' => route('nursing.dispatch.assign'),
            'unassignUrl' => route('nursing.dispatch.unassign'),
        ];
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
