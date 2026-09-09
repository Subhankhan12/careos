<?php

namespace App\Http\Controllers\Comms;

use App\AiCore\Agents\InboxAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\Platform\Models\User;

/**
 * App-layer composition (D-017): the G.3 inbox's AI-draft actions. The agent DRAFTS only; sending is
 * this explicit human action.
 *
 * QA-FIX.9a (`P10-C3`, D-218) — THIS CONTROLLER IS THE SECOND CALLER OF `ApprovalQueue::approve()`, and
 * it used to be the ungated one. Two things were wrong and they needed different remedies:
 *
 *  1. AUTHORITY. Neither method carried a permission check, so the gate on the surface they belong to —
 *     `comms.manage`, on the sibling `InboxController` — did not apply to them. It does now.
 *     `comms.manage` is deliberately the permission here and NOT `ai.manage`: the inbox is reception's
 *     screen, reception holds `comms.manage` without `ai.manage`, and requiring the governance
 *     permission would have made sending an AI-drafted reply an org_admin-only act — a product change,
 *     not a security fix. `ai.manage` fences the GOVERNANCE QUEUE, which shows every agent action in
 *     the tenant; this route is one surface's own draft.
 *
 *  2. SCOPE, which is what actually made it dangerous. The action was resolved with a bare key lookup,
 *     so the endpoint reached the whole `agent_actions` table: a CLINICAL or FINANCIAL action id posted
 *     here executed, even though those are exactly the categories `AiApprovalQueueController::bulkApprove()`
 *     refuses server-side. The lookup is now scoped to what this surface is responsible for — a PENDING
 *     `comms.draft_reply` — so anything else is simply not found.
 *
 * THE SCOPE IS THE SINGLE-ITEM COUNTERPART OF THE BULK EXCLUSION, and deliberately not a copy of it.
 * Bulk needs a category blacklist because a bulk gesture selects across categories by construction. A
 * surface-scoped route needs a SCOPE, which is strictly stronger: it also refuses operational actions
 * this surface never proposed. The governance queue keeps no category check on its single approve,
 * because a reviewer holding `ai.manage` and looking at one item is the informed review the rule
 * protects.
 */
class InboxAgentController
{
    public function draft(Request $request, InboxAgent $agent): RedirectResponse
    {
        Gate::authorize('comms.manage');

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
        Gate::authorize('comms.manage');

        $data = $request->validate([
            'action_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Only this surface's own kind of draft. A clinical or financial action id is NOT FOUND here,
        // rather than refused after the fact, so there is no category list to keep in step with the
        // tool registry.
        $action = AgentAction::query()
            ->whereKey($data['action_id'])
            ->where('tool_key', 'comms.draft_reply')
            ->where('status', AgentAction::STATUS_PENDING)
            ->firstOrFail();

        // approve() re-authorizes the reviewer against the TOOL's permission, requires the action to
        // still be pending, and executes through the tool, which posts via ThreadService with
        // ai_assisted=true after re-grounding the draft.
        try {
            $queue->approve($action, $user);
        } catch (AiCoreException $exception) {
            // The fence refused the draft, or it stopped being pending between the read and the call.
            // Both are answers, not crashes: the governance controller already handles them this way
            // and a second Send used to be a 500 here. FenceRefusalException extends AiCoreException,
            // so one catch covers both; the service has already recorded the fence refusal.
            return back()->withErrors(['ai_draft' => $exception->getMessage()]);
        }

        $threadId = $action->input_payload['thread_id'] ?? null;

        return redirect()->route('comms.inbox', is_string($threadId) ? ['thread_id' => $threadId] : []);
    }
}
