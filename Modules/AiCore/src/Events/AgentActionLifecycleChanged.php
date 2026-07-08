<?php

namespace Modules\AiCore\Events;

use Modules\AiCore\Models\AgentAction;

class AgentActionLifecycleChanged
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly AgentAction $action,
        public readonly string $state,
        public readonly array $context = [],
    ) {}
}
