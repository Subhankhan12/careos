<?php

namespace App\AiCore\Tools;

use Modules\AiCore\Contracts\AiTool;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolDefinition;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AvailableSlotFinder;

class SuggestSlotsTool implements AiTool
{
    public function __construct(private readonly AvailableSlotFinder $slots) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            key: 'scheduler.suggest_slots',
            name: 'Suggest scheduling slots',
            category: ToolDefinition::CATEGORY_OPERATIONAL,
            permission: 'appointment.manage',
            schema: [
                'type' => 'object',
                'required' => ['service_id', 'branch_id', 'date'],
                'properties' => [
                    'service_id' => ['type' => 'string'],
                    'branch_id' => ['type' => 'string'],
                    'date' => ['type' => 'string'],
                    'limit' => ['type' => 'integer'],
                ],
            ],
            reversible: true,
            autonomyCeiling: AutonomyPolicy::APPROVE,
        );
    }

    public function preview(array $input): array
    {
        return $this->payload($input);
    }

    public function execute(array $input, ?User $actor = null): array
    {
        return $this->payload($input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function payload(array $input): array
    {
        $service = Service::query()->findOrFail((string) $input['service_id']);

        return [
            'slots' => $this->slots->forServiceBranchDate(
                $service,
                (string) $input['branch_id'],
                (string) $input['date'],
                (int) ($input['limit'] ?? 6),
            ),
            'books_on_approval' => false,
        ];
    }
}
