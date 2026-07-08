<?php

namespace Modules\AiCore\Services;

use Illuminate\Support\Facades\Http;
use Modules\AiCore\Exceptions\AiCoreException;
use Throwable;

class LlmManager
{
    public function __construct(
        private readonly BudgetGate $budgetGate,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly AiInteractionRecorder $recorder,
    ) {}

    public function complete(string $feature, string $agent, PromptVersion $prompt, string $input, int $maxTokens = 256): LlmResponse
    {
        $provider = (string) config('aicore.provider', 'anthropic');
        $providerConfig = (array) config("aicore.providers.{$provider}", []);
        $model = (string) ($providerConfig['model'] ?? 'unknown');
        $modelVersion = (string) ($providerConfig['model_version'] ?? 'unknown');
        $estimatedInputTokens = $this->estimateTokens($prompt->body."\n".$input);
        $estimatedCost = $this->estimateCostMinor($estimatedInputTokens, $maxTokens);

        try {
            $this->budgetGate->assertWithinBudget($estimatedCost);
        } catch (AiCoreException $e) {
            $this->recorder->record(
                $feature,
                $agent,
                $provider,
                $model,
                $modelVersion,
                $prompt->hash(),
                'budget_blocked',
                $estimatedInputTokens,
                0,
                0,
                errorMessage: $e->getMessage(),
            );

            throw $e;
        }

        try {
            $this->circuitBreaker->assertClosed($provider, $feature);
        } catch (AiCoreException $e) {
            $this->recorder->record(
                $feature,
                $agent,
                $provider,
                $model,
                $modelVersion,
                $prompt->hash(),
                'circuit_open',
                $estimatedInputTokens,
                0,
                0,
                errorMessage: $e->getMessage(),
            );

            throw $e;
        }

        $started = microtime(true);

        try {
            $response = Http::timeout((int) config('aicore.timeout_seconds', 10))
                ->retry((int) config('aicore.retries', 1), 100)
                ->withHeaders($this->headersFor($provider, $providerConfig))
                ->post((string) ($providerConfig['endpoint'] ?? ''), $this->payloadFor($provider, $model, $prompt, $input, $maxTokens))
                ->throw()
                ->json();

            $latencyMs = (int) round((microtime(true) - $started) * 1000);
            $text = $this->textFromResponse((array) $response);
            $inputTokens = (int) data_get($response, 'usage.input_tokens', $estimatedInputTokens);
            $outputTokens = (int) data_get($response, 'usage.output_tokens', $this->estimateTokens($text));
            $costMinor = $this->estimateCostMinor($inputTokens, $outputTokens);

            $this->circuitBreaker->recordSuccess($provider, $feature);
            $this->recorder->record(
                $feature,
                $agent,
                $provider,
                $model,
                $modelVersion,
                $prompt->hash(),
                'completed',
                $inputTokens,
                $outputTokens,
                $costMinor,
                outputRef: hash('sha256', $text),
                latencyMs: $latencyMs,
            );

            return new LlmResponse($text, $inputTokens, $outputTokens, $latencyMs, (array) $response);
        } catch (Throwable $e) {
            $this->circuitBreaker->recordFailure($provider, $feature);
            $this->recorder->record(
                $feature,
                $agent,
                $provider,
                $model,
                $modelVersion,
                $prompt->hash(),
                'failed',
                $estimatedInputTokens,
                0,
                0,
                latencyMs: (int) round((microtime(true) - $started) * 1000),
                errorMessage: $e->getMessage(),
            );

            throw new AiCoreException('AI provider call failed; route to manual workflow.', previous: $e);
        }
    }

    private function estimateTokens(string $text): int
    {
        return max(1, (int) ceil(strlen($text) / 4));
    }

    private function estimateCostMinor(int $inputTokens, int $outputTokens): int
    {
        return max(1, (int) ceil(($inputTokens + ($outputTokens * 2)) / 1000));
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     * @return array<string, string>
     */
    private function headersFor(string $provider, array $providerConfig): array
    {
        if ($provider === 'anthropic') {
            return [
                'x-api-key' => (string) ($providerConfig['api_key'] ?? ''),
                'anthropic-version' => (string) ($providerConfig['version_header'] ?? '2023-06-01'),
            ];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(string $provider, string $model, PromptVersion $prompt, string $input, int $maxTokens): array
    {
        if ($provider === 'anthropic') {
            return [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $prompt->body,
                'messages' => [
                    ['role' => 'user', 'content' => $input],
                ],
            ];
        }

        return [
            'model' => $model,
            'prompt' => $prompt->body."\n".$input,
            'max_tokens' => $maxTokens,
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function textFromResponse(array $response): string
    {
        $anthropicText = data_get($response, 'content.0.text');

        if (is_string($anthropicText)) {
            return $anthropicText;
        }

        $text = data_get($response, 'text');

        return is_string($text) ? $text : '';
    }
}
