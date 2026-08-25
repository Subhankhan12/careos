<?php

namespace App\Services;

use App\AiCore\Agents\InboxAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Services\AgentRegistry;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\Clinical\Services\OrderService;
use Modules\Comms\Models\Thread;
use Modules\Comms\Services\ThreadService;
use Modules\Platform\Models\User;

/**
 * G2 (GOV.P2) — "what is actually waiting on a person right now", for the agent-governance surface.
 *
 * THIS IS THE REAL VERSION OF THE SLICE THE WIREFRAME INVENTED. The governance mock drew an
 * "escalated" outcome on its outcome chart; GOV.P1 refused it, because `escalated` is not an
 * `agent_action` status and no query could ever produce it. The genuine hand-off is a THREAD flagged
 * for a clinician, which lives in a different table with a different lifecycle — so it belongs in a
 * worklist, not in a pie of action outcomes.
 *
 * ── THE CATEGORIES, EACH A REAL STATE WITH A REAL SETTER AND CLEARER ────────────────────────────
 *
 *  1. PENDING AGENT ACTIONS
 *     set     — {@see ApprovalQueue::propose()} creates the row as `pending`
 *     cleared — `approve()` (→ executed) or `reject()` (→ rejected), both requiring the tool's own
 *               permission and a real reviewer
 *     blocked on a human? YES — nothing else moves it; the queue waits indefinitely.
 *     permission — `ai.manage` (the approval queue's own gate)
 *
 *  2. THREADS FLAGGED FOR A CLINICIAN
 *     set     — {@see InboxAgent::refuseClinical()} writes
 *               `clinician_attention_at` + `clinician_attention_reason` when the fence refuses a
 *               clinical question, and audits `thread.flagged_for_clinician`
 *     cleared — **NOTHING CLEARS THE COLUMN.** There is no setter anywhere that nulls it (verified by
 *               a whole-repo search), so "flagged" is a permanent mark, not a resolvable state.
 *               Counting flagged threads directly would produce a number no human action can ever
 *               reduce — a worklist you cannot empty, which is worse than no worklist at all.
 *               So this reader defines STILL WAITING as the conjunction of three real facts:
 *               the thread is flagged, the thread is still OPEN, and NO staff message has been
 *               posted since the flag. Replying ({@see ThreadService::postStaffMessage})
 *               or closing ({@see ThreadService::close}) — both real human
 *               actions — remove it from the list.
 *     permission — `comms.manage` (the inbox's own gate; this is patient-thread work)
 *
 * ── DELIBERATELY EXCLUDED, EACH WITH ITS REASON ─────────────────────────────────────────────────
 *
 *  • FENCE-REFUSED ACTIONS ({@see AgentAction::STATUS_FENCE_REFUSED}) — **terminal, not waiting.**
 *    `recordFenceRefusal()` writes a final status and the action is resolved; there is no approve or
 *    reject left to perform. Where the refusal did leave work for a person, it is already counted:
 *    the same inbox refusal sets the clinician-attention flag in category 2.
 *
 *  • OPERATOR ACCESS REQUESTS pending an owner decision (OPMODE.G3) — **cannot occur today.**
 *    Operator Mode is deliberately inert (D-164): the backend exists but there is no HTTP route and
 *    no UI, so no request can be raised. Listing it would imply a queue that cannot receive anything.
 *
 *  • THE CLINICAL AND OPERATIONAL WORKLISTS — orders resulted-but-not-reviewed
 *    ({@see OrderService::toReview}), recalls due (PC.P7), draft referrals
 *    awaiting send (PC.P6), unsigned notes, draft timesheet lines, dunning. Every one is a real state
 *    that really blocks on a human — and every one has **its own screen, its own permission and its
 *    own owner**. They are out of scope HERE, not out of existence: this panel is scoped to agent
 *    governance, and it names them on screen so an empty panel can never be misread as "nothing
 *    anywhere needs a person" (the PC.P5 completeness lesson, applied to the boundary rather than to
 *    the contents).
 *
 * ── WHAT IS NOT HERE ────────────────────────────────────────────────────────────────────────────
 *
 * No priority, no urgency, no SLA, no overdue band, no age tint. Items are ordered oldest-first,
 * which is a DATE SORT over a recorded timestamp — a fact — and never presented as importance
 * (the PC.P7 formulation, D-169). Nothing clinical is read or shown: the thread's subject and the
 * recorded refusal reason are governance metadata, and the patient's message body never leaves the
 * inbox.
 */
class NeedsHumanReader
{
    /** The categories this reader covers, in the order the surface renders them. */
    public const CATEGORY_APPROVALS = 'pending_approvals';

    public const CATEGORY_CLINICIAN = 'clinician_attention';

    /** Categories that are real states but cannot be produced today, stated rather than hidden. */
    public const UNPRODUCIBLE = ['operator_access_request'];

    /** Worklists that genuinely block on a human but belong to another surface. */
    public const ELSEWHERE = ['orders_review', 'recalls_due', 'referrals_draft', 'notes_draft'];

    public function __construct(private readonly AgentMetricsService $metrics) {}

    /**
     * Every in-scope category, each with a real count and its items — scoped to what THIS viewer may
     * actually see. A category the viewer holds no permission for comes back `visible: false` with a
     * zero count and no items: fail-closed, and never another category's data.
     *
     * @return array{
     *     categories: list<array{key: string, visible: bool, count: int, actionUrl: string, items: list<array<string, mixed>>}>,
     *     total: int,
     *     unproducible: list<string>,
     *     elsewhere: list<string>
     * }
     */
    public function forUser(User $user, int $limit = 5): array
    {
        $categories = [
            $this->pendingApprovals($user, $limit),
            $this->clinicianAttention($user, $limit),
        ];

        return [
            'categories' => $categories,
            // The total counts only what this viewer can see, so the empty state it drives is honest
            // for THEM — a viewer who cannot see the queue is not told the queue is empty.
            'total' => array_sum(array_column($categories, 'count')),
            'unproducible' => self::UNPRODUCIBLE,
            'elsewhere' => self::ELSEWHERE,
        ];
    }

    /**
     * Category 1 — agent actions waiting for approve or reject.
     *
     * The COUNT comes from {@see AgentMetricsService::window()}'s `pendingNow`, the same figure the
     * governance dashboard shows, so the two surfaces cannot disagree about the size of the queue.
     *
     * @return array{key: string, visible: bool, count: int, actionUrl: string, items: list<array<string, mixed>>}
     */
    private function pendingApprovals(User $user, int $limit): array
    {
        if (! Gate::forUser($user)->allows('ai.manage')) {
            return $this->hidden(self::CATEGORY_APPROVALS, route('governance.approvals.index'));
        }

        // ONE definition of the queue depth — shared with the dashboard (GOV.P1).
        $count = $this->metrics->pendingApprovalCount();

        $items = AgentAction::query()
            ->where('status', AgentAction::STATUS_PENDING)
            // A DATE SORT, not a ranking: the oldest thing waiting is a fact about a timestamp.
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AgentAction $action): array => [
                'id' => $action->id,
                'agent' => AgentRegistry::displayName($action->agent),
                'tool' => $action->tool_key,
                'waitingSince' => $action->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'key' => self::CATEGORY_APPROVALS,
            'visible' => true,
            'count' => $count,
            'actionUrl' => route('governance.approvals.index'),
            'items' => $items,
        ];
    }

    /**
     * Category 2 — threads the fence handed to a clinician, still unanswered.
     *
     * "Still waiting" is the conjunction described in the class docblock: flagged, still open, and no
     * staff message since the flag. Each conjunct is a real column or row, and each is cleared by a
     * real human action.
     *
     * @return array{key: string, visible: bool, count: int, actionUrl: string, items: list<array<string, mixed>>}
     */
    private function clinicianAttention(User $user, int $limit): array
    {
        if (! Gate::forUser($user)->allows('comms.manage')) {
            return $this->hidden(self::CATEGORY_CLINICIAN, route('comms.inbox'));
        }

        $query = $this->waitingForClinician();

        $items = (clone $query)
            ->orderBy('clinician_attention_at')
            ->limit($limit)
            ->get()
            ->map(fn (Thread $thread): array => [
                'id' => $thread->id,
                'subject' => $thread->subject,
                // The reason the FENCE recorded — its own words, not a characterisation of the
                // patient's message, and never the message itself.
                'reason' => $thread->clinician_attention_reason,
                'waitingSince' => $thread->clinician_attention_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'key' => self::CATEGORY_CLINICIAN,
            'visible' => true,
            'count' => $query->count(),
            'actionUrl' => route('comms.inbox'),
            'items' => $items,
        ];
    }

    /**
     * The one query defining "flagged and still unanswered", so the count and the items can never
     * describe different sets.
     *
     * @return Builder<Thread>
     */
    private function waitingForClinician()
    {
        /*
         * COMMS.P1 moved the conjunction itself onto the model, because the inbox now offers the
         * same "still needs a human" filter. Two copies of this query would be two definitions of
         * the same claim on two screens a user reads minutes apart — the drift GOV.P1 removed from
         * `approvedAsIsPct`. The conjunction is unchanged; only its home is.
         */
        return Thread::query()->waitingForClinician();
    }

    /**
     * A category this viewer may not see: zero, no items, and flagged as not visible — so the screen
     * can say "you cannot see this one" rather than "there is nothing here".
     *
     * @return array{key: string, visible: bool, count: int, actionUrl: string, items: list<array<string, mixed>>}
     */
    private function hidden(string $key, string $actionUrl): array
    {
        return [
            'key' => $key,
            'visible' => false,
            'count' => 0,
            'actionUrl' => $actionUrl,
            'items' => [],
        ];
    }
}
