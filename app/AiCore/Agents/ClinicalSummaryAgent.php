<?php

namespace App\AiCore\Agents;

use Modules\AiCore\Services\AgentRuntime;
use Modules\AiCore\Services\AiInteractionRecorder;
use Modules\AiCore\Services\PromptRegistry;
use Modules\Platform\Models\User;

class ClinicalSummaryAgent
{
    public const FEATURE = 'clinical.summary';

    public const AGENT = 'clinical-summary-agent';

    public function __construct(
        private readonly AgentRuntime $runtime,
        private readonly PromptRegistry $prompts,
        private readonly AiInteractionRecorder $recorder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(string $patientId, string $from, string $to, User $actor, ?string $request = null): array
    {
        if ($this->isInterpretiveRequest((string) $request)) {
            $prompt = $this->prompts->get(self::FEATURE);
            $this->recorder->record(
                self::FEATURE,
                self::AGENT,
                'internal',
                'extractive-summary',
                '1',
                $prompt->hash(),
                'refused',
                toolCalls: [['tool' => 'clinical.summarize_since_last_visit']],
                metadata: [
                    'patient_id' => $patientId,
                    'reason' => 'interpretive_or_diagnostic_request',
                ],
            );

            return [
                'status' => 'refused',
                'label' => AiInteractionRecorder::LABEL,
                'human_handoff' => true,
                'answer' => 'I can only extract source-linked record content. A clinician must handle interpretive or diagnostic questions.',
            ];
        }

        return $this->runtime->runTool(
            'clinical.summarize_since_last_visit',
            [
                'patient_id' => $patientId,
                'from' => $from,
                'to' => $to,
            ],
            $actor,
            self::FEATURE,
            self::AGENT,
            'Create an extractive source-linked draft summary for clinician review',
        );
    }

    private function isInterpretiveRequest(string $request): bool
    {
        return preg_match('/\b(diagnos(?:e|is)|assess|assessment|getting worse|worse|improving|improved|risk|triage|should|recommend|prioriti[sz]e)\b/i', $request) === 1;
    }
}
