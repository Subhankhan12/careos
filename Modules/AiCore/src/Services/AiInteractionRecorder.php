<?php

namespace Modules\AiCore\Services;

use Illuminate\Support\Carbon;
use Modules\AiCore\Events\AiInteractionRecorded;
use Modules\AiCore\Models\AiInteraction;
use Modules\Platform\Services\TenantContext;

class AiInteractionRecorder
{
    public const LABEL = 'AI draft - requires human review';

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<int, array<string, mixed>>|null  $toolCalls
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        string $feature,
        string $agent,
        string $provider,
        string $model,
        string $modelVersion,
        string $promptHash,
        string $outcome,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $costMinor = 0,
        ?array $toolCalls = null,
        ?string $outputRef = null,
        ?string $approverId = null,
        int $latencyMs = 0,
        ?string $errorMessage = null,
        ?array $metadata = null,
    ): AiInteraction {
        $interaction = AiInteraction::query()->create([
            'tenant_id' => $this->tenantContext->id(),
            'feature' => $feature,
            'agent' => $agent,
            'provider' => $provider,
            'model' => $model,
            'model_version' => $modelVersion,
            'prompt_hash' => $promptHash,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_minor' => $costMinor,
            'tool_calls' => $toolCalls,
            'output_ref' => $outputRef,
            'approver_id' => $approverId,
            'latency_ms' => $latencyMs,
            'outcome' => $outcome,
            'label' => self::LABEL,
            'error_message' => $errorMessage,
            'metadata' => $metadata,
            'occurred_at' => Carbon::now(),
        ]);

        event(new AiInteractionRecorded($interaction));

        return $interaction;
    }
}
