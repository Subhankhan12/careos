<?php

namespace App\AiCore\Tools;

use App\AiCore\Support\BillingCodeSuggestionEngine;
use InvalidArgumentException;
use Modules\AiCore\Contracts\AiTool;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolDefinition;
use Modules\Billing\Services\ChargeCaptureService;
use Modules\Billing\Services\TariffResolver;
use Modules\Clinical\Models\Encounter;
use Modules\Nursing\Models\Visit;
use Modules\Platform\Models\User;

/**
 * Maps documented services to tariff codes as SUGGESTIONS only. Financial
 * category — hard-capped at 'approve'. On human approval, charges are captured
 * through ChargeCaptureService, which re-resolves the tariff itself: the
 * agent's prices are NEVER trusted.
 */
class SuggestChargeCodesTool implements AiTool
{
    public function __construct(
        private readonly BillingCodeSuggestionEngine $engine,
        private readonly ChargeCaptureService $capture,
    ) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            key: 'billing.suggest_charge_codes',
            name: 'Suggest charge codes from documentation',
            category: ToolDefinition::CATEGORY_FINANCIAL,
            permission: 'billing.manage',
            schema: [
                'type' => 'object',
                'required' => ['source_type', 'source_id'],
                'properties' => [
                    'source_type' => ['type' => 'string', 'enum' => ['encounter', 'visit']],
                    'source_id' => ['type' => 'string'],
                    'suggestions' => ['type' => 'array'],
                ],
            ],
            reversible: true,
            autonomyCeiling: AutonomyPolicy::APPROVE,
        );
    }

    public function preview(array $input): array
    {
        return $this->engine->suggest($input);
    }

    public function execute(array $input, ?User $actor = null): array
    {
        if ($actor === null) {
            throw new InvalidArgumentException('A human approver is required.');
        }

        // Re-run the full in-code validation (source-linking + tariff
        // resolution) against current state before any capture.
        $preview = $this->preview($input);
        $captured = [];

        foreach ($preview['suggestions'] as $suggestion) {
            // Capture goes through ChargeCaptureService, which authorizes,
            // re-resolves the tariff at the service date, and snapshots the
            // TARIFF's price — any agent-supplied number never reaches it.
            $charge = match ($preview['source_type']) {
                'encounter' => $this->capture->captureFromEncounter(
                    Encounter::query()->whereKey($preview['source_id'])->firstOrFail(),
                    (string) $suggestion['code'],
                    (int) $suggestion['quantity'],
                    $actor,
                ),
                'visit' => $this->capture->captureFromVisit(
                    Visit::query()->whereKey($preview['source_id'])->firstOrFail(),
                    (string) $suggestion['code'],
                    (int) $suggestion['quantity'],
                    $actor,
                ),
                default => throw new InvalidArgumentException('Unsupported billing suggestion source type.'),
            };

            $captured[] = [
                'charge_id' => $charge->id,
                'code' => $charge->code,
                'quantity' => $charge->quantity,
                'unit_price_minor' => $charge->unit_price_minor,
                'line_total_minor' => $charge->line_total_minor,
            ];
        }

        return [
            'captured' => count($captured),
            'charges' => $captured,
            'executed_via' => ChargeCaptureService::class,
            'prices_from' => TariffResolver::class,
        ];
    }
}
