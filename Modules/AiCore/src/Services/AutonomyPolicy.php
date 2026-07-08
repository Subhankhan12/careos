<?php

namespace Modules\AiCore\Services;

use InvalidArgumentException;
use Modules\Platform\Services\SettingsService;

class AutonomyPolicy
{
    public const OFF = 'off';

    public const SUGGEST = 'suggest';

    public const APPROVE = 'approve';

    public const AUTO = 'auto';

    public const LEVELS = [
        self::OFF => 0,
        self::SUGGEST => 1,
        self::APPROVE => 2,
        self::AUTO => 3,
    ];

    public function __construct(private readonly SettingsService $settings) {}

    public function levelFor(ToolDefinition $definition): string
    {
        $level = (string) $this->settings->get('ai.autonomy.'.$definition->key, self::SUGGEST);

        return $this->cap($definition, $this->normalize($level));
    }

    public function set(ToolDefinition $definition, string $level): void
    {
        $this->settings->set('ai.autonomy.'.$definition->key, $this->cap($definition, $this->normalize($level)), 'string');
    }

    private function normalize(string $level): string
    {
        if (! array_key_exists($level, self::LEVELS)) {
            throw new InvalidArgumentException('Unknown autonomy level.');
        }

        return $level;
    }

    private function cap(ToolDefinition $definition, string $level): string
    {
        if ($definition->isClinicalOrFinancial() && self::LEVELS[$level] > self::LEVELS[self::APPROVE]) {
            return self::APPROVE;
        }

        return $level;
    }
}
