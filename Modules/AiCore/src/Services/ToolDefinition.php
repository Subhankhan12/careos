<?php

namespace Modules\AiCore\Services;

class ToolDefinition
{
    public const CATEGORY_OPERATIONAL = 'operational';

    public const CATEGORY_CLINICAL = 'clinical';

    public const CATEGORY_FINANCIAL = 'financial';

    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $category,
        public readonly string $permission,
        public readonly array $schema,
        public readonly bool $reversible = true,
        public readonly string $autonomyCeiling = AutonomyPolicy::AUTO,
    ) {}

    public function isClinicalOrFinancial(): bool
    {
        return in_array($this->category, [self::CATEGORY_CLINICAL, self::CATEGORY_FINANCIAL], true);
    }
}
