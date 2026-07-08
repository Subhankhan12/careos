<?php

namespace Modules\AiCore\Services;

use Modules\AiCore\Contracts\AiTool;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Tools\EchoTool;

class ToolRegistry
{
    /**
     * @var array<string, AiTool>
     */
    private array $tools = [];

    public function __construct(EchoTool $echoTool)
    {
        $this->register($echoTool);
    }

    public function register(AiTool $tool): void
    {
        $this->tools[$tool->definition()->key] = $tool;
    }

    public function get(string $key): AiTool
    {
        if (! isset($this->tools[$key])) {
            throw new AiCoreException("Tool {$key} is not registered.");
        }

        return $this->tools[$key];
    }
}
