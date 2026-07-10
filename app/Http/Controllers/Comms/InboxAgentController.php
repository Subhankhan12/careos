<?php

namespace App\Http\Controllers\Comms;

use App\AiCore\Agents\InboxAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\Platform\Models\User;

/**
 * App-layer composition (D-017): the G.3 inbox's AI-draft actions. The agent
 * DRAFTS only; sending is this explicit human action, and the server
 * independently enforces RBAC, pending state, and grounding on send (P0D.GU).
 */
class InboxAgentController
{
    public function draft(Request $request, InboxAgent $agent): RedirectResponse
    {
        $data = $request->validate([
            'thread_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $agent->draftReply(['thread_id' => $data['thread_id']], $user);

        return redirect()->route('comms.inbox', ['thread_id' => $data['thread_id']]);
    }

    public function sendDraft(Request $request, ApprovalQueue $queue): RedirectResponse
    {
        $data = $request->validate([
            'action_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $action = AgentAction::query()->whereKey($data['action_id'])->firstOrFail();

        // approve() re-authorizes the reviewer, requires the action to still be
        // pending, and executes through the tool, which posts via ThreadService
        // with ai_assisted=true after re-grounding the draft.
        $queue->approve($action, $user);

        $threadId = $action->input_payload['thread_id'] ?? null;

        return redirect()->route('comms.inbox', is_string($threadId) ? ['thread_id' => $threadId] : []);
    }
}
