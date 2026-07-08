<?php

namespace Modules\AiCore\Contracts;

use Modules\AiCore\Services\ToolDefinition;

interface AiTool
{
    public function definition(): ToolDefinition;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(array $input): array;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array;
}
