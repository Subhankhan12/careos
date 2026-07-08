<?php

namespace Modules\AiCore\Services;

class PromptVersion
{
    public function __construct(
        public readonly string $feature,
        public readonly int $version,
        public readonly string $body,
        public readonly bool $evalPassed,
    ) {}

    public function hash(): string
    {
        return hash('sha256', $this->feature.'|'.$this->version.'|'.$this->body);
    }
}
