<?php

namespace Modules\AiCore\Tools;

use Modules\AiCore\Contracts\AiTool;
use Modules\AiCore\Services\ToolDefinition;

class EchoTool implements AiTool
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            key: 'demo.echo',
            name: 'Demo echo',
            category: ToolDefinition::CATEGORY_OPERATIONAL,
            permission: 'ai.manage',
            schema: [
                'type' => 'object',
                'required' => ['message'],
                'properties' => [
                    'message' => ['type' => 'string'],
                ],
            ],
            reversible: true,
        );
    }

    public function preview(array $input): array
    {
        return $this->payload($input);
    }

    public function execute(array $input): array
    {
        return $this->payload($input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function payload(array $input): array
    {
        return [
            'message' => (string) ($input['message'] ?? ''),
            'label' => 'AI draft - requires human review',
            'human_handoff' => true,
        ];
    }
}
