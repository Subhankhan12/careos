<?php

namespace App\AiCore\Tools;

use App\AiCore\Support\InboxDraftEngine;
use InvalidArgumentException;
use Modules\AiCore\Contracts\AiTool;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolDefinition;
use Modules\Comms\Models\Thread;
use Modules\Comms\Services\ThreadService;
use Modules\Platform\Models\User;

/**
 * DRAFT-ONLY (D-G5): the agent produces a grounded, source-referenced draft
 * that waits in the approval queue. The agent NEVER posts. Only when the
 * assigned staff member explicitly sends does execute() run — with the HUMAN
 * as actor — posting through ThreadService with ai_assisted=true. The ceiling
 * is `suggest`; any higher setting degrades.
 */
class DraftReplyTool implements AiTool
{
    public function __construct(
        private readonly InboxDraftEngine $engine,
        private readonly ThreadService $threads,
    ) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            key: 'comms.draft_reply',
            name: 'Draft a reply to a patient thread',
            category: ToolDefinition::CATEGORY_OPERATIONAL,
            permission: 'comms.manage',
            schema: [
                'type' => 'object',
                'required' => ['thread_id'],
                'properties' => [
                    'thread_id' => ['type' => 'string'],
                    'draft' => ['type' => 'array'],
                ],
            ],
            reversible: true,
            autonomyCeiling: AutonomyPolicy::SUGGEST,
        );
    }

    public function preview(array $input): array
    {
        return $this->engine->draft($input);
    }

    public function execute(array $input, ?User $actor = null): array
    {
        if ($actor === null) {
            throw new InvalidArgumentException('A human sender is required.');
        }

        // Re-ground the draft against current state before anything is posted.
        $draft = $this->preview($input);

        if (($draft['handoff'] ?? true) === true || trim((string) ($draft['body'] ?? '')) === '') {
            throw new AiCoreException('This draft handed off to a human; there is nothing to send.');
        }

        $thread = Thread::query()->whereKey($draft['thread_id'])->firstOrFail();

        // The HUMAN posts through ThreadService; the message row carries
        // ai_assisted=true so staff always see its origin. The patient simply
        // receives a message from their care team.
        $message = $this->threads->postStaffMessage($thread, $actor, (string) $draft['body'], aiAssisted: true);

        return [
            'message_id' => $message->id,
            'thread_id' => $thread->id,
            'ai_assisted' => true,
            'sent_by' => $actor->id,
            'executed_via' => ThreadService::class,
        ];
    }
}
