<?php

namespace App\AiCore\Tools;

use Modules\AiCore\Contracts\AiTool;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Services\AiInteractionRecorder;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolDefinition;
use Modules\Clinical\Models\Recall;
use Modules\Clinical\Models\RecallRule;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\ConsentService;
use Modules\Platform\Models\User;

class DraftRecallMessageTool implements AiTool
{
    public function __construct(private readonly ConsentService $consents) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            key: 'clinical.draft_recall_message',
            name: 'Draft recall follow-up message',
            category: ToolDefinition::CATEGORY_CLINICAL,
            permission: 'note.write',
            schema: [
                'type' => 'object',
                'required' => ['recall_id', 'template'],
                'properties' => [
                    'recall_id' => ['type' => 'string'],
                    'template' => ['type' => 'string'],
                ],
            ],
            reversible: true,
            autonomyCeiling: AutonomyPolicy::SUGGEST,
        );
    }

    public function preview(array $input): array
    {
        return $this->payload($input, false);
    }

    public function execute(array $input, ?User $actor = null): array
    {
        return $this->payload($input, true);
    }

    /**
     * ABSOLUTE CONSTRAINT: the Follow-up agent drafts WORDING only for a
     * recall already selected by deterministic RecallEngine rules. It never
     * selects recipients, gives medical advice, adds symptom guidance, or
     * states clinical reasons beyond the recall rule and clinician template.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function payload(array $input, bool $approved): array
    {
        $recall = Recall::query()->whereKey((string) $input['recall_id'])->firstOrFail();
        $patient = Patient::query()->whereKey($recall->patient_id)->firstOrFail();
        $rule = RecallRule::query()->whereKey($recall->rule_id)->firstOrFail();
        $template = trim((string) $input['template']);

        if ($template === '') {
            throw new AiCoreException('A recall message template is required.');
        }

        $message = $this->renderTemplate($template, $patient, $recall, $rule);
        $this->assertNoMedicalAdvice($message);

        $payload = [
            'recall_id' => $recall->id,
            'patient_id' => $patient->id,
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'message' => $message,
            'label' => AiInteractionRecorder::LABEL,
            'human_handoff' => true,
            'selected_by' => 'deterministic_recall_engine',
            'sent' => false,
        ];

        if (! $approved) {
            return [
                ...$payload,
                'status' => 'draft_pending_approval',
            ];
        }

        if (! $this->consents->has($patient, 'comms.email')) {
            return [
                ...$payload,
                'status' => 'blocked_no_comms_consent',
            ];
        }

        return [
            ...$payload,
            'status' => 'ready_for_human_delivery',
        ];
    }

    private function renderTemplate(string $template, Patient $patient, Recall $recall, RecallRule $rule): string
    {
        return strtr($template, [
            '{patient_name}' => trim($patient->first_name.' '.$patient->last_name),
            '{first_name}' => $patient->first_name,
            '{mrn}' => $patient->mrn,
            '{rule_name}' => $rule->name,
            '{due_on}' => $recall->due_on->toDateString(),
        ]);
    }

    private function assertNoMedicalAdvice(string $message): void
    {
        if (preg_match('/\b(symptom|symptoms|pain|fever|bleeding|chest pain|dose|dosage|diagnos(?:e|is)|triage|worsening|urgent symptoms|medical advice)\b/i', $message) === 1) {
            throw new AiCoreException('Recall message drafts cannot contain medical advice or symptom guidance.');
        }
    }
}
