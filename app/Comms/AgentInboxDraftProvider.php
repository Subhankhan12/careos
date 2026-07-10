<?php

namespace App\Comms;

use Modules\AiCore\Models\AgentAction;
use Modules\Comms\Contracts\InboxDraftProvider;
use Modules\Comms\Models\Thread;

/**
 * App-layer composition (D-017): reads the C.7 approval queue for a pending
 * comms.draft_reply draft without Comms depending on AiCore.
 */
class AgentInboxDraftProvider implements InboxDraftProvider
{
    public function pendingDraftFor(Thread $thread): ?array
    {
        $action = AgentAction::query()
            ->where('tool_key', 'comms.draft_reply')
            ->where('status', AgentAction::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->get()
            ->first(fn (AgentAction $candidate): bool => ($candidate->input_payload['thread_id'] ?? null) === $thread->id);

        if ($action === null || ($action->proposed_output['handoff'] ?? true) === true) {
            return null;
        }

        return [
            'action_id' => $action->id,
            'body' => (string) ($action->proposed_output['body'] ?? ''),
            'lines' => array_values((array) ($action->proposed_output['lines'] ?? [])),
        ];
    }
}
