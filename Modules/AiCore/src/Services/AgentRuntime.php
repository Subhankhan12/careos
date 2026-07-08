<?php

namespace Modules\AiCore\Services;

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
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: string, label: string, human_handoff: bool, action?: AgentAction}
     */
    public function runTool(string $toolKey, array $input, User $actor, string $feature = 'demo.echo', string $agent = 'demo-agent', string $why = 'Demo no-op tool'): array
    {
        $tool = $this->tools->get($toolKey);
        $prompt = $this->prompts->get($feature);

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

        $level = $this->autonomy->levelFor($tool->definition());

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
