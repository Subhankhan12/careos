<?php

namespace Modules\AiCore\Services;

use Illuminate\Support\Facades\Gate;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Models\Agent;
use Modules\AiCore\Models\AgentAction;
use Modules\Platform\Models\User;

class AgentRuntime
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly AutonomyPolicy $autonomy,
        private readonly KillSwitch $killSwitch,
        private readonly ApprovalQueue $approvalQueue,
        private readonly PromptRegistry $prompts,
        private readonly AiInteractionRecorder $recorder,
        private readonly BudgetGate $budgetGate,
        private readonly AgentResolver $agentResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  Agent|null  $agentEntity  when given, the effective autonomy is the CAPPED resolver
     *                                   result MIN(agent configured, tool ceiling, role ceiling) — the agent can only narrow, never
     *                                   raise past the AutonomyPolicy cap or the role RBAC ceiling. When null (every existing
     *                                   caller), behaviour is unchanged: the per-tool AutonomyPolicy level applies.
     * @return array{status: string, label: string, human_handoff: bool, action?: AgentAction}
     */
    public function runTool(string $toolKey, array $input, User $actor, string $feature = 'demo.echo', string $agent = 'demo-agent', string $why = 'Demo no-op tool', ?Agent $agentEntity = null): array
    {
        $tool = $this->tools->get($toolKey);
        $prompt = $this->prompts->get($feature);

        try {
            $this->budgetGate->assertWithinBudget(1);
        } catch (AiCoreException $e) {
            $this->recorder->record(
                $feature,
                $agent,
                'internal',
                'tool-runtime',
                '1',
                $prompt->hash(),
                'budget_blocked',
                toolCalls: [['tool' => $toolKey]],
                errorMessage: $e->getMessage(),
            );

            return [
                'status' => 'budget_blocked',
                'label' => AiInteractionRecorder::LABEL,
                'human_handoff' => true,
            ];
        }

        if (! $this->killSwitch->enabled($feature)) {
            $this->recorder->record(
                $feature,
                $agent,
                'internal',
                'tool-runtime',
                '1',
                $prompt->hash(),
                'disabled',
                toolCalls: [['tool' => $toolKey]],
            );

            return [
                'status' => 'disabled',
                'label' => AiInteractionRecorder::LABEL,
                'human_handoff' => true,
            ];
        }

        // The effective autonomy. With an Agent entity, it is the CAPPED resolver result
        // MIN(agent configured, tool ceiling, role ceiling) — the agent only narrows. The role
        // ceiling is OFF when the acting user lacks the tool's permission (the RBAC ceiling caps the
        // agent to not-callable). Without an entity, the existing per-tool AutonomyPolicy level applies.
        if ($agentEntity !== null) {
            $roleCeiling = Gate::forUser($actor)->allows($tool->definition()->permission)
                ? AutonomyPolicy::AUTO
                : AutonomyPolicy::OFF;
            $level = $this->agentResolver->effectiveLevel($agentEntity, $tool->definition(), $roleCeiling);
        } else {
            $level = $this->autonomy->levelFor($tool->definition());
        }

        if ($level === AutonomyPolicy::OFF) {
            $this->recorder->record(
                $feature,
                $agent,
                'internal',
                'tool-runtime',
                '1',
                $prompt->hash(),
                'off',
                toolCalls: [['tool' => $toolKey]],
            );

            return [
                'status' => 'off',
                'label' => AiInteractionRecorder::LABEL,
                'human_handoff' => true,
            ];
        }

        if ($level === AutonomyPolicy::AUTO) {
            return [
                'status' => 'executed',
                'label' => AiInteractionRecorder::LABEL,
                'human_handoff' => true,
                'action' => $this->approvalQueue->autoExecute($toolKey, $input, $actor, $feature, $agent, $why, $level),
            ];
        }

        return [
            'status' => 'pending',
            'label' => AiInteractionRecorder::LABEL,
            'human_handoff' => true,
            'action' => $this->approvalQueue->propose($toolKey, $input, $actor, $feature, $agent, $why, $level),
        ];
    }
}
