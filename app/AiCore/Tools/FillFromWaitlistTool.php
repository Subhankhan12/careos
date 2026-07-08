<?php

namespace App\AiCore\Tools;

use Carbon\CarbonImmutable;
use Modules\AiCore\Contracts\AiTool;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolDefinition;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\WaitlistEntry;
use Modules\Scheduling\Services\WaitlistService;

class FillFromWaitlistTool implements AiTool
{
    public function __construct(private readonly WaitlistService $waitlist) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            key: 'scheduler.fill_from_waitlist',
            name: 'Fill from waitlist',
            category: ToolDefinition::CATEGORY_OPERATIONAL,
            permission: 'appointment.manage',
            schema: [
                'type' => 'object',
                'required' => ['service_id', 'branch_id', 'starts_at', 'ends_at', 'resource_ids'],
                'properties' => [
                    'service_id' => ['type' => 'string'],
                    'branch_id' => ['type' => 'string'],
                    'starts_at' => ['type' => 'string'],
                    'ends_at' => ['type' => 'string'],
                    'resource_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'waitlist_entry_id' => ['type' => 'string'],
                ],
            ],
            reversible: true,
            autonomyCeiling: AutonomyPolicy::APPROVE,
        );
    }

    public function preview(array $input): array
    {
        $starts = CarbonImmutable::parse((string) $input['starts_at']);
        $ends = CarbonImmutable::parse((string) $input['ends_at']);
        $matches = $this->waitlist->matchingForSlot(
            (string) $input['service_id'],
            (string) $input['branch_id'],
            $starts,
            $ends,
        );

        return [
            'matches' => $matches->map(fn (WaitlistEntry $entry): array => [
                'waitlist_entry_id' => $entry->id,
                'patient_id' => $entry->patient_id,
                'priority' => $entry->priority,
                'status' => $entry->status,
            ])->values()->all(),
            'will_book_on_approval' => $matches->isNotEmpty(),
        ];
    }

    public function execute(array $input, ?User $actor = null): array
    {
        if ($actor === null) {
            throw new \InvalidArgumentException('A human approver is required.');
        }

        $preview = $this->preview($input);
        $entryId = (string) ($input['waitlist_entry_id'] ?? ($preview['matches'][0]['waitlist_entry_id'] ?? ''));

        if ($entryId === '') {
            return ['booked' => false, 'reason' => 'no_matching_waitlist_entry'];
        }

        $starts = CarbonImmutable::parse((string) $input['starts_at']);
        $ends = CarbonImmutable::parse((string) $input['ends_at']);
        $entry = WaitlistEntry::query()->findOrFail($entryId);
        $offered = $this->waitlist->offer($entry, $starts, $ends, (string) $input['branch_id'], $actor);
        $appointment = $this->waitlist->accept($offered, array_values((array) $input['resource_ids']), $actor);

        return [
            'booked' => true,
            'appointment_id' => $appointment->id,
            'waitlist_entry_id' => $offered->id,
        ];
    }
}
