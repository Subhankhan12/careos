<?php

namespace App\AiCore\Agents;

use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Services\AgentRuntime;
use Modules\AiCore\Services\AiInteractionRecorder;
use Modules\AiCore\Services\PromptRegistry;
use Modules\Platform\Models\User;

class DispatchAgent
{
    public const ASSIGNMENT_FEATURE = 'nursing.dispatch_assignments';

    public const REPLAN_FEATURE = 'nursing.dispatch_replan';

    public const AGENT = 'nursing-dispatch-agent';

    public function __construct(
        private readonly AgentRuntime $runtime,
        private readonly PromptRegistry $prompts,
        private readonly AiInteractionRecorder $recorder,
    ) {}

    /**
     * The Dispatch agent reasons about logistics only: qualification codes,
     * time windows, straight-line distances, and hour caps. It must refuse
     * clinically framed prioritization or urgency requests.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function proposeAssignments(array $input, User $actor, ?string $request = null): array
    {
        if ($this->isClinicalRequest((string) $request)) {
            return $this->refuse(self::ASSIGNMENT_FEATURE, 'nursing.propose_assignments', $input);
        }

        return $this->runGovernedTool(
            'nursing.propose_assignments',
            $input,
            $actor,
            self::ASSIGNMENT_FEATURE,
            'Propose validator-bound nursing visit assignments for dispatcher approval',
        );
    }

    /**
     * The Dispatch agent reasons about logistics only: qualification codes,
     * time windows, straight-line distances, and hour caps. It must refuse
     * clinically framed prioritization or urgency requests.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function replanDay(array $input, User $actor, ?string $request = null): array
    {
        if ($this->isClinicalRequest((string) $request)) {
            return $this->refuse(self::REPLAN_FEATURE, 'nursing.replan_day', $input);
        }

        return $this->runGovernedTool(
            'nursing.replan_day',
            $input,
            $actor,
            self::REPLAN_FEATURE,
            'Propose validator-bound day-of replans for dispatcher approval',
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function refuse(string $feature, string $toolKey, array $input): array
    {
        $prompt = $this->prompts->get($feature);
        $this->recorder->record(
            $feature,
            self::AGENT,
            'internal',
            'logistics-only-dispatch',
            '1',
            $prompt->hash(),
            'refused',
            toolCalls: [['tool' => $toolKey]],
            metadata: [
                'reason' => 'clinical_prioritization_request',
                'date' => $input['date'] ?? null,
                'branch_id' => $input['branch_id'] ?? null,
            ],
        );

        return [
            'status' => 'refused',
            'label' => AiInteractionRecorder::LABEL,
            'human_handoff' => true,
            'answer' => 'I can only help with logistics assignments. A human dispatcher must handle clinically framed priority questions.',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function runGovernedTool(string $toolKey, array $input, User $actor, string $feature, string $why): array
    {
        try {
            return $this->runtime->runTool($toolKey, $input, $actor, $feature, self::AGENT, $why);
        } catch (AiCoreException $e) {
            $prompt = $this->prompts->get($feature);
            $this->recorder->record(
                $feature,
                self::AGENT,
                'internal',
                'logistics-only-dispatch',
                '1',
                $prompt->hash(),
                'invalid_proposal',
                toolCalls: [['tool' => $toolKey]],
                errorMessage: $e->getMessage(),
                metadata: [
                    'date' => $input['date'] ?? null,
                    'branch_id' => $input['branch_id'] ?? null,
                ],
            );

            throw $e;
        }
    }

    private function isClinicalRequest(string $request): bool
    {
        return preg_match('/\b(sickest|urgent|urgency|diabetic|diabetes|diagnos(?:e|is)|symptom|symptoms|triage|clinical|risk|priority|prioriti[sz]e|needs.*most)\b/i', $request) === 1;
    }
}
