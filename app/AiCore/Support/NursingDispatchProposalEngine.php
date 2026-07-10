<?php

namespace App\AiCore\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Services\AssignmentValidator;
use Modules\Platform\Services\SettingsService;
use Modules\Scheduling\Models\Resource;

class NursingDispatchProposalEngine
{
    public function __construct(
        private readonly AssignmentValidator $validator,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function proposeAssignments(array $input): array
    {
        return $this->buildPlan($input, false);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function replanDay(array $input): array
    {
        return $this->buildPlan($input, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function buildPlan(array $input, bool $replan): array
    {
        $date = CarbonImmutable::parse((string) $input['date'])->toDateString();
        $branchId = (string) $input['branch_id'];
        $resources = $this->candidateResources($branchId, $input);
        $provisional = collect();

        $proposals = array_key_exists('proposals', $input)
            ? $this->validateExplicitProposals($input, $resources, $provisional, $replan)
            : $this->generateProposals($input, $resources, $provisional, $replan);

        return [
            'date' => $date,
            'branch_id' => $branchId,
            'mode' => $replan ? 'replan_day' : 'propose_assignments',
            'proposals' => $proposals,
            'proposal_count' => count($proposals),
            'books_or_assigns_on_approval' => count($proposals) > 0,
            'explanation' => 'All proposals are re-validated by the deterministic Nursing AssignmentValidator before approval queue creation.',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  Collection<int, \Modules\Scheduling\Models\Resource>  $resources
     * @param  Collection<int, PlannedVisit>  $provisional
     * @return list<array<string, mixed>>
     */
    private function validateExplicitProposals(array $input, Collection $resources, Collection $provisional, bool $replan): array
    {
        $rawProposals = array_values((array) $input['proposals']);
        $proposals = [];

        foreach ($rawProposals as $rawProposal) {
            if (! is_array($rawProposal)) {
                throw new AiCoreException('Dispatch proposal payload is invalid.');
            }

            $visit = PlannedVisit::query()->whereKey((string) ($rawProposal['visit_id'] ?? ''))->firstOrFail();
            $resource = $resources
                ->first(fn (Resource $candidate): bool => $candidate->id === (string) ($rawProposal['resource_id'] ?? ''));

            if (! $resource instanceof Resource) {
                throw new AiCoreException('Dispatch proposal references a non-candidate nurse resource.');
            }

            $this->assertVisitEligible($visit, (string) $input['branch_id'], $replan);
            $proposals[] = $this->validatedProposal($visit, $resource, $provisional);
            $provisional->push($visit->replicate()->forceFill([
                'id' => $visit->id,
                'tenant_id' => $visit->tenant_id,
                'assigned_resource_id' => $resource->id,
                'status' => PlannedVisit::STATUS_ASSIGNED,
            ]));
        }

        return $proposals;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  Collection<int, \Modules\Scheduling\Models\Resource>  $resources
     * @param  Collection<int, PlannedVisit>  $provisional
     * @return list<array<string, mixed>>
     */
    private function generateProposals(array $input, Collection $resources, Collection $provisional, bool $replan): array
    {
        $proposals = [];

        foreach ($this->candidateVisits($input, $replan) as $visit) {
            $best = null;

            foreach ($resources as $resource) {
                if ($replan && ($input['unavailable_resource_id'] ?? null) === $resource->id) {
                    continue;
                }

                $assigned = $this->assignedVisitsFor($visit, $resource)->merge(
                    $provisional->filter(fn (PlannedVisit $candidate): bool => $candidate->assigned_resource_id === $resource->id),
                );
                $reasons = $this->validator->validate($visit, $resource, $assigned);

                if ($reasons !== []) {
                    continue;
                }

                $candidate = $this->proposal($visit, $resource, []);

                if ($best === null || $candidate['estimated_added_travel_minutes'] < $best['estimated_added_travel_minutes']) {
                    $best = $candidate;
                }
            }

            if ($best === null) {
                continue;
            }

            $proposals[] = $best;
            $provisional->push($visit->replicate()->forceFill([
                'id' => $visit->id,
                'tenant_id' => $visit->tenant_id,
                'assigned_resource_id' => $best['resource_id'],
                'status' => PlannedVisit::STATUS_ASSIGNED,
            ]));
        }

        return $proposals;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return Collection<int, PlannedVisit>
     */
    private function candidateVisits(array $input, bool $replan): Collection
    {
        $query = PlannedVisit::query()
            ->whereDate('scheduled_date', CarbonImmutable::parse((string) $input['date'])->toDateString())
            ->orderBy('window_start_at');

        if (isset($input['visit_ids']) && is_array($input['visit_ids'])) {
            $query->whereIn('id', array_values($input['visit_ids']));
        } elseif ($replan && isset($input['unavailable_resource_id'])) {
            $query->where('assigned_resource_id', (string) $input['unavailable_resource_id'])
                ->where('status', PlannedVisit::STATUS_ASSIGNED);
        } else {
            $query->whereNull('assigned_resource_id')
                ->where('status', PlannedVisit::STATUS_PLANNED);
        }

        return $query->get()
            ->filter(fn (PlannedVisit $visit): bool => $this->visitBranchId($visit) === (string) $input['branch_id'])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return Collection<int, \Modules\Scheduling\Models\Resource>
     */
    private function candidateResources(string $branchId, array $input): Collection
    {
        $query = Resource::query()
            ->where('branch_id', $branchId)
            ->where('type', Resource::TYPE_PRACTITIONER)
            ->where('active', true)
            ->orderBy('name');

        if (isset($input['resource_ids']) && is_array($input['resource_ids'])) {
            $query->whereIn('id', array_values($input['resource_ids']));
        }

        return $query->get();
    }

    private function assertVisitEligible(PlannedVisit $visit, string $branchId, bool $replan): void
    {
        if ($this->visitBranchId($visit) !== $branchId) {
            throw new AiCoreException('Dispatch proposal crosses branch or tenant scope.');
        }

        $allowed = $replan
            ? [PlannedVisit::STATUS_PLANNED, PlannedVisit::STATUS_ASSIGNED]
            : [PlannedVisit::STATUS_PLANNED];

        if (! in_array($visit->status, $allowed, true)) {
            throw new AiCoreException('Dispatch proposal references a visit that cannot be assigned.');
        }
    }

    /**
     * @param  Collection<int, PlannedVisit>  $provisional
     * @return array<string, mixed>
     */
    private function validatedProposal(PlannedVisit $visit, Resource $resource, Collection $provisional): array
    {
        $assigned = $this->assignedVisitsFor($visit, $resource)->merge(
            $provisional->filter(fn (PlannedVisit $candidate): bool => $candidate->assigned_resource_id === $resource->id),
        );
        $reasons = $this->validator->validate($visit, $resource, $assigned);

        if ($reasons !== []) {
            throw new AiCoreException('Dispatch proposal failed deterministic validation: '.implode(', ', $reasons));
        }

        return $this->proposal($visit, $resource, $reasons);
    }

    /**
     * @return array<string, mixed>
     */
    private function proposal(PlannedVisit $visit, Resource $resource, array $reasons): array
    {
        return [
            'visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'resource_id' => $resource->id,
            'resource_name' => $resource->name,
            'window_start_at' => $visit->window_start_at->toDateTimeString(),
            'window_end_at' => $visit->window_end_at->toDateTimeString(),
            'validation_reasons' => $reasons,
            'constraints_satisfied' => ['qualification', 'window', 'travel', 'hour_cap'],
            'optimized_for' => 'lowest_estimated_added_straight_line_travel_minutes',
            'estimated_added_travel_minutes' => $this->estimatedAddedTravelMinutes($visit, $resource),
            'explanation' => 'AssignmentValidator accepted qualification, non-overlap window, straight-line travel feasibility, and weekly hour cap.',
        ];
    }

    /**
     * @return Collection<int, PlannedVisit>
     */
    private function assignedVisitsFor(PlannedVisit $visit, Resource $resource): Collection
    {
        return PlannedVisit::query()
            ->where('assigned_resource_id', $resource->id)
            ->where('status', PlannedVisit::STATUS_ASSIGNED)
            ->where(function ($query) use ($visit): void {
                $query
                    ->whereBetween('window_start_at', [
                        $visit->window_start_at->copy()->startOfWeek(CarbonInterface::MONDAY)->toDateTimeString(),
                        $visit->window_start_at->copy()->endOfWeek(CarbonInterface::SUNDAY)->toDateTimeString(),
                    ])
                    ->orWhereDate('window_start_at', $visit->window_start_at->toDateString());
            })
            ->get();
    }

    private function estimatedAddedTravelMinutes(PlannedVisit $visit, Resource $resource): int
    {
        $sameDay = $this->assignedVisitsFor($visit, $resource)
            ->filter(fn (PlannedVisit $assigned): bool => $assigned->window_start_at->toDateString() === $visit->window_start_at->toDateString())
            ->sortBy(fn (PlannedVisit $assigned): string => $assigned->window_start_at->toDateTimeString())
            ->values();

        $previous = $sameDay->filter(fn (PlannedVisit $assigned): bool => $assigned->window_end_at->lte($visit->window_start_at))->last();
        $next = $sameDay->filter(fn (PlannedVisit $assigned): bool => $assigned->window_start_at->gte($visit->window_end_at))->first();
        $minutes = 0.0;

        if ($previous instanceof PlannedVisit) {
            $minutes += $this->travelMinutes($previous, $visit);
        }

        if ($next instanceof PlannedVisit) {
            $minutes += $this->travelMinutes($visit, $next);
        }

        return (int) round($minutes);
    }

    private function travelMinutes(PlannedVisit $from, PlannedVisit $to): float
    {
        if ($from->location_latitude === null || $from->location_longitude === null || $to->location_latitude === null || $to->location_longitude === null) {
            return 0.0;
        }

        $earthKm = 6371.0;
        $latDelta = deg2rad((float) $to->location_latitude - (float) $from->location_latitude);
        $lngDelta = deg2rad((float) $to->location_longitude - (float) $from->location_longitude);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad((float) $from->location_latitude))
            * cos(deg2rad((float) $to->location_latitude))
            * sin($lngDelta / 2) ** 2;
        $distanceKm = $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
        $speedKmh = max(1.0, (float) $this->settings->get('nursing.dispatch.average_speed_kmh', 40));

        return ($distanceKm / $speedKmh) * 60;
    }

    private function visitBranchId(PlannedVisit $visit): string
    {
        $plan = VisitPlan::query()->whereKey($visit->visit_plan_id)->firstOrFail();
        $agreement = ServiceAgreement::query()->whereKey($plan->service_agreement_id)->firstOrFail();

        return $agreement->branch_id;
    }
}
