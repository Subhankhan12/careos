<?php

namespace App\AiCore\Tools;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AiCore\Contracts\AiTool;
use Modules\AiCore\Exceptions\AiCoreException;
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

        /*
         * NOTHING TO BOOK IS A REFUSAL, NOT A RESULT (`P10-C2`, second half; D-179).
         *
         * This returned `['booked' => false, 'reason' => 'no_matching_waitlist_entry']`, and a tool that
         * RETURNS is a tool that succeeded: the queue marked the action `executed`, stamped `approved_at`
         * and `executed_at`, and the Resolved tab rendered it **"Approved"** with nothing anywhere saying
         * no appointment exists. Driven in Phase 10 on the retry of a failed approval. Throwing instead
         * leaves the action PENDING — a human still decides whether to retry or reject it — and lands in
         * the `AiCoreException` catch `AiApprovalQueueController:326` already has, so no new exception
         * type and no new controller branch (the QA-FIX.9a shape).
         */
        if ($entryId === '') {
            throw new AiCoreException(
                'No waiting patient matches this slot any more, so nothing was booked. The proposal is unchanged; reject it or approve it again once someone is waiting.'
            );
        }

        $starts = CarbonImmutable::parse((string) $input['starts_at']);
        $ends = CarbonImmutable::parse((string) $input['ends_at']);
        $entry = WaitlistEntry::query()->findOrFail($entryId);

        /*
         * ONE OPERATION, ONE TRANSACTION (D-199) — the fix for `P10-C2`'s first half, and the SIXTH
         * instance of create-then-associate-outside-a-transaction this programme has found.
         *
         * `offer()` flipped the entry to `offered` with a bare `->save()` and dispatched its event with
         * nothing around it; `accept()` then opened its OWN transaction, booked, and threw on a conflict.
         * That rollback could not reach a commit that had already happened, so a refused approval left the
         * patient in `offered` against a slot belonging to someone else — invisible to the candidate
         * search, with no product path back — plus a `waitlist.offered` audit row for an offer that never
         * stood and, because this pair writes no `waitlist_offers` record, nothing that recorded an offer.
         *
         * Wrapping the pair makes `accept()`'s transaction a savepoint inside this one, so a booking
         * conflict now unwinds BOTH halves: the status flip, its event's audit row, and the booking
         * attempt. The exception still propagates unchanged — this changes what is left behind on a
         * refusal, never whether the refusal happens.
         */
        return DB::transaction(function () use ($entry, $starts, $ends, $input, $actor): array {
            $offered = $this->waitlist->offer($entry, $starts, $ends, (string) $input['branch_id'], $actor);
            $appointment = $this->waitlist->accept($offered, array_values((array) $input['resource_ids']), $actor);

            return [
                'booked' => true,
                'appointment_id' => $appointment->id,
                'waitlist_entry_id' => $offered->id,
            ];
        });
    }
}
