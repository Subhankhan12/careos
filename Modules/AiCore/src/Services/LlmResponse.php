<?php

namespace Modules\AiCore\Services;

class LlmResponse
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $latencyMs,
        public readonly array $raw = [],
    ) {}
}
