<?php

namespace App\AiCore\Agents;

use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Services\AgentRuntime;
use Modules\AiCore\Services\AiInteractionRecorder;
use Modules\AiCore\Services\PromptRegistry;
use Modules\Platform\Models\User;

/**
 * The Billing agent maps documented services to tariff codes as suggestions
 * and flags likely rule violations before invoicing. The deterministic engine
 * (F.3 ChargeValidator, F.4 IssueService) remains the SOLE source of truth,
 * and both tools are financial category — hard-capped at 'approve'.
 *
 * ELECTRIC FENCE: this agent reads only note text (for mapping) and charge
 * data (for preflight). Anything outside billing mapping — appropriateness of
 * treatment, alternatives, patient condition — is refused with human handoff.
 */
class BillingAgent
{
    public const SUGGEST_FEATURE = 'billing.suggest_codes';

    public const PREFLIGHT_FEATURE = 'billing.preflight';

    public const AGENT = 'billing-agent';

    public function __construct(
        private readonly AgentRuntime $runtime,
        private readonly PromptRegistry $prompts,
        private readonly AiInteractionRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function suggestChargeCodes(array $input, User $actor, ?string $request = null): array
    {
        if ($this->isClinicalRequest((string) $request)) {
            return $this->refuse(self::SUGGEST_FEATURE, 'billing.suggest_charge_codes', $input);
        }

        return $this->runGovernedTool(
            'billing.suggest_charge_codes',
            $input,
            $actor,
            self::SUGGEST_FEATURE,
            'Suggest source-linked tariff codes from documented services for human approval',
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preflightInvoice(array $input, User $actor, ?string $request = null): array
    {
        if ($this->isClinicalRequest((string) $request)) {
            return $this->refuse(self::PREFLIGHT_FEATURE, 'billing.preflight_invoice', $input);
        }

        $input['requested_by'] = (int) $actor->getKey();

        return $this->runGovernedTool(
            'billing.preflight_invoice',
            $input,
            $actor,
            self::PREFLIGHT_FEATURE,
            'Explain deterministic charge-validation results before invoicing',
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
            'billing-mapping-only',
            '1',
            $prompt->hash(),
            'refused',
            toolCalls: [['tool' => $toolKey]],
            metadata: [
                'reason' => 'clinical_question_outside_billing_mapping',
                'source_type' => $input['source_type'] ?? null,
                'patient_id' => $input['patient_id'] ?? null,
            ],
        );

        return [
            'status' => 'refused',
            'label' => AiInteractionRecorder::LABEL,
            'human_handoff' => true,
            'answer' => 'I can only help map documented services to billing codes and explain validation results. Questions about treatment appropriateness, alternatives, or a patient\'s condition must go to a clinician.',
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
                'billing-mapping-only',
                '1',
                $prompt->hash(),
                'invalid_proposal',
                toolCalls: [['tool' => $toolKey]],
                errorMessage: $e->getMessage(),
                metadata: [
                    'source_type' => $input['source_type'] ?? null,
                    'source_id' => $input['source_id'] ?? null,
                    'patient_id' => $input['patient_id'] ?? null,
                ],
            );

            throw $e;
        }
    }

    private function isClinicalRequest(string $request): bool
    {
        return preg_match(
            '/\b(appropriate|necessary|should\s+(?:we|you|they|the)\s+have|instead|getting\s+worse|deteriorat\w*|improv\w*|diagnos\w*|symptom\w*|triage|dos(?:e|ing|age)|prognosis|sickest|clinical(?:ly)?\s+(?:right|correct|indicated)|right\s+treatment)\b/i',
            $request,
        ) === 1;
    }
}
