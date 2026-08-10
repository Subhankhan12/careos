<?php

namespace Modules\AiCore\Services;

use Illuminate\Support\Carbon;
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

        // AGENT.P6 rate/timing limits — CONSULTED here (only on the Agent-entity path). A limit can
        // only STOP the agent, never widen it: an agent in its quiet-hours window, or over its
        // drafts/hour cap, does not act — the work is deferred to a human (the escalation floor).
        if ($agentEntity !== null) {
            $limited = $this->limitOutcome($agentEntity, $feature, $agent, $toolKey, $prompt);
            if ($limited !== null) {
                return $limited;
            }
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

    /**
     * Consult the agent's rate/timing limits. Returns a terminal outcome (the agent does NOT act,
     * the work is handed to a human) when a limit is hit, or null when the agent may proceed. Each
     * hit is recorded to the append-only ledger, so it is countable + listable.
     *
     * @return array{status: string, label: string, human_handoff: bool}|null
     */
    private function limitOutcome(Agent $agentEntity, string $feature, string $agent, string $toolKey, PromptVersion $prompt): ?array
    {
        // Quiet hours: the agent does not act during its configured window — deferred to a human.
        if ($agentEntity->isQuietHour((int) Carbon::now()->format('G'))) {
            return $this->recordLimit($feature, $agent, $toolKey, $prompt, 'quiet_hours', 'Outside the agent\'s active hours; deferred to a human.');
        }

        // Drafts/hour cap: the agent stops drafting once it hits the cap in the rolling hour window.
        $cap = $agentEntity->max_drafts_per_hour;
        if ($cap !== null) {
            $recent = AgentAction::query()
                ->whereIn('agent', AgentRegistry::ledgerNames($agentEntity->kind()))
                ->where('created_at', '>=', Carbon::now()->subHour())
                ->count();

            if ($recent >= $cap) {
                return $this->recordLimit($feature, $agent, $toolKey, $prompt, 'rate_limited', 'Hourly draft cap reached; deferred to a human.');
            }
        }

        return null;
    }

    /**
     * @return array{status: string, label: string, human_handoff: bool}
     */
    private function recordLimit(string $feature, string $agent, string $toolKey, PromptVersion $prompt, string $outcome, string $reason): array
    {
        $this->recorder->record(
            $feature,
            $agent,
            'internal',
            'tool-runtime',
            '1',
            $prompt->hash(),
            $outcome,
            toolCalls: [['tool' => $toolKey]],
            errorMessage: $reason,
        );

        return [
            'status' => $outcome,
            'label' => AiInteractionRecorder::LABEL,
            'human_handoff' => true,
        ];
    }
}
