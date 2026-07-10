<?php

namespace App\AiCore\Tools;

use Modules\AiCore\Contracts\AiTool;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolDefinition;
use Modules\Billing\Services\ChargeValidator;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

/**
 * Explains what the deterministic F.3 ChargeValidator decided about a
 * patient/period's draft charges before invoicing. The agent EXPLAINS; the
 * validator DECIDES. LLM-claimed violations in the input are DISCARDED — the
 * reported list is copied verbatim from the validator output, so preflight
 * mirrors the deterministic engine exactly. No invoice is ever issued here:
 * issuing stays a human action through IssueService.
 */
class PreflightInvoiceTool implements AiTool
{
    /**
     * Plain-language templates keyed by the validator's own reason codes.
     */
    private const SUMMARIES = [
        ChargeValidator::REASON_MAX_QUANTITY_PER_PERIOD_EXCEEDED => 'This code was billed more times than the tariff allows in the period; reduce the quantity or remove the extra charges.',
        ChargeValidator::REASON_INCOMPATIBLE_CODES_SAME_DATE => 'Two codes on the same date cannot be billed together under the tariff rules; keep one and remove the other.',
        ChargeValidator::REASON_REQUIRED_CODE_MISSING => 'This add-on code needs its base code on the same date; capture the base code or remove the add-on.',
        ChargeValidator::REASON_DOCUMENTATION_REQUIRED_MISSING => 'This code needs a signed encounter note or a completed visit before it can be invoiced.',
    ];

    public function __construct(private readonly ChargeValidator $validator) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            key: 'billing.preflight_invoice',
            name: 'Preflight charges before invoicing',
            category: ToolDefinition::CATEGORY_FINANCIAL,
            permission: 'billing.manage',
            schema: [
                'type' => 'object',
                'required' => ['patient_id', 'from', 'to'],
                'properties' => [
                    'patient_id' => ['type' => 'string'],
                    'from' => ['type' => 'string'],
                    'to' => ['type' => 'string'],
                    'requested_by' => ['type' => 'integer'],
                    'llm_claims' => ['type' => 'array'],
                ],
            ],
            reversible: true,
            autonomyCeiling: AutonomyPolicy::APPROVE,
        );
    }

    public function preview(array $input): array
    {
        return $this->report($input);
    }

    public function execute(array $input, ?User $actor = null): array
    {
        return $this->report($input, $actor);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function report(array $input, ?User $actor = null): array
    {
        $actor ??= User::query()->whereKey((int) ($input['requested_by'] ?? 0))->first();

        if (! $actor instanceof User) {
            throw new AiCoreException('Billing preflight requires a requesting user.');
        }

        $patient = Patient::query()->whereKey((string) $input['patient_id'])->firstOrFail();
        $patient->auditRead(['surface' => 'billing_agent']);

        // The deterministic engine is the SOLE source of truth. Anything the
        // LLM claimed about violations is discarded, never merged.
        $discardedClaims = count((array) ($input['llm_claims'] ?? []));

        $result = $this->validator->validateForPatientPeriod(
            $patient,
            (string) $input['from'],
            (string) $input['to'],
            $actor,
        );

        return [
            'patient_id' => $patient->id,
            'from' => (string) $input['from'],
            'to' => (string) $input['to'],
            'validated' => $result['validated'],
            'violations' => $result['violations'],
            'violation_count' => count($result['violations']),
            'summary_lines' => array_map(
                fn (array $violation): string => sprintf(
                    'Charge %s (%s): %s',
                    $violation['charge_id'],
                    $violation['reason_code'],
                    self::SUMMARIES[$violation['reason_code']] ?? $violation['message'],
                ),
                $result['violations'],
            ),
            'decided_by' => ChargeValidator::class,
            'discarded_llm_claims' => $discardedClaims,
            'invoice_issued' => false,
            'issuing_note' => 'Issuing an invoice remains a human action through IssueService; this tool never issues.',
        ];
    }
}
