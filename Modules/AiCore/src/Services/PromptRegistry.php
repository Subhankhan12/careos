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
            'scheduler.fill_waitlist:1' => new PromptVersion(
                'scheduler.fill_waitlist',
                1,
                'Propose waitlist candidates for an open scheduling slot. Do not book without human approval.',
                true,
            ),
            'scheduler.suggest_slots:1' => new PromptVersion(
                'scheduler.suggest_slots',
                1,
                'Suggest appointment slots from availability only. Do not book or modify records.',
                true,
            ),
            'front_desk.faq:1' => new PromptVersion(
                'front_desk.faq',
                1,
                'Answer only from tenant-approved KB content. If not covered, escalate to a human.',
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
