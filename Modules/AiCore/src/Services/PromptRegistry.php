<?php

namespace Modules\AiCore\Services;

use Modules\AiCore\Exceptions\AiCoreException;

class PromptRegistry
{
    /**
     * @var array<string, PromptVersion>
     */
    private array $prompts;

    public function __construct()
    {
        $this->prompts = [
            'demo.echo:1' => new PromptVersion(
                'demo.echo',
                1,
                'Return the supplied message as a visibly labeled draft. Do not infer clinical facts.',
                true,
            ),
        ];
    }

    public function get(string $feature, int $version = 1): PromptVersion
    {
        $key = $feature.':'.$version;

        if (! isset($this->prompts[$key])) {
            throw new AiCoreException("Prompt {$key} is not registered.");
        }

        $prompt = $this->prompts[$key];

        if (! $prompt->evalPassed) {
            throw new AiCoreException("Prompt {$key} has not passed evals.");
        }

        return $prompt;
    }
}
