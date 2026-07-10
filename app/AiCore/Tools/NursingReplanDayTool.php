<?php

namespace App\AiCore\Tools;

use App\AiCore\Support\NursingDispatchProposalEngine;
use Modules\AiCore\Contracts\AiTool;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolDefinition;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Services\VisitAssignmentService;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\Resource;

class NursingReplanDayTool implements AiTool
{
    public function __construct(
        private readonly NursingDispatchProposalEngine $proposals,
        private readonly VisitAssignmentService $assignments,
    ) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            key: 'nursing.replan_day',
            name: 'Replan nursing day',
            category: ToolDefinition::CATEGORY_OPERATIONAL,
            permission: 'dispatch.manage',
            schema: [
                'type' => 'object',
                'required' => ['date', 'branch_id'],
                'properties' => [
                    'date' => ['type' => 'string'],
                    'branch_id' => ['type' => 'string'],
                    'unavailable_resource_id' => ['type' => 'string'],
                    'visit_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'resource_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'proposals' => ['type' => 'array'],
                ],
            ],
            reversible: true,
            autonomyCeiling: AutonomyPolicy::APPROVE,
        );
    }

    public function preview(array $input): array
    {
        return $this->proposals->replanDay($input);
    }

    public function execute(array $input, ?User $actor = null): array
    {
        if ($actor === null) {
            throw new \InvalidArgumentException('A human approver is required.');
        }

        $preview = $this->preview($input);
        $assigned = [];

        foreach ($preview['proposals'] as $proposal) {
            $visit = PlannedVisit::query()->whereKey((string) $proposal['visit_id'])->firstOrFail();
            $resource = Resource::query()->whereKey((string) $proposal['resource_id'])->firstOrFail();
            $result = $this->assignments->assign($visit, $resource, $actor);
            $assigned[] = [
                'visit_id' => $result->id,
                'resource_id' => $result->assigned_resource_id,
            ];
        }

        return [
            'assigned' => count($assigned),
            'assignments' => $assigned,
            'executed_via' => VisitAssignmentService::class,
        ];
    }
}
