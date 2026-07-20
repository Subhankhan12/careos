<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Platform\Models\User;

/**
 * AI approval queue (CLINIC.W9) — a READ + ACT-THROUGH-EXISTING-PATH window onto the
 * governed agent-action queue. It lists PENDING agent actions and lets a human approve or
 * reject them, but it introduces NO new execution path and NO new autonomy:
 *
 *  - approve/reject go ONLY through {@see ApprovalQueue} (the same service the backend
 *    already tests and the P.4 eval harness locks). This controller never executes a tool,
 *    never sets an autonomy level, and never CREATES an agent action — there is no
 *    propose/create route here, so a human cannot inject an un-fenced action.
 *  - the queue only ever contains items the AutonomyPolicy already routed to human
 *    approval; approving is exactly the human step the `approve` cap requires. Clinical and
 *    financial tools are hard-capped at `approve` by AutonomyPolicy — the UI cannot raise
 *    that, and it never asks.
 *  - {@see ApprovalQueue::approve()}/{@see ApprovalQueue::reject()} re-authorize the
 *    reviewer against the TOOL's OWN permission on every call and assert the action is
 *    still pending. A reviewer who reaches this page (`ai.manage`) but lacks a tool's
 *    permission (e.g. appointment.manage) is DENIED by the service (403). That
 *    AuthorizationException is left to propagate on purpose — only AiCoreException (a
 *    domain error such as "already reviewed") is caught and surfaced.
 *  - a rejected action does nothing (the service already handles this); an approved action
 *    executes only through the existing approved-action path with its tenancy, audit, and
 *    electric-fence checks intact. Every approve/reject is audited by the EXISTING
 *    app-layer glue (agent_action.* / ai_interaction.*) — this controller adds no audit of
 *    its own.
 *
 * Gated on `ai.manage`; tenant-scoped. Actions resolve by string id (FIX.1 / D-090) so a
 * cross-tenant or missing id fails closed as 404 without implicit route-model binding.
 */
class AiApprovalQueueController
{
    private const RESOLVED_LIMIT = 20;

    public function index(Request $request, ToolRegistry $tools): Response
    {
        Gate::authorize('ai.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $pending = AgentAction::query()
            ->where('status', AgentAction::STATUS_PENDING)
            ->orderByDesc('id')
            ->get()
            ->map(fn (AgentAction $action): array => $this->presentPending($action, $tools, $actor))
            ->all();

        $resolved = AgentAction::query()
            ->whereIn('status', [AgentAction::STATUS_EXECUTED, AgentAction::STATUS_REJECTED])
            ->orderByDesc('id')
            ->limit(self::RESOLVED_LIMIT)
            ->get()
            ->map(fn (AgentAction $action): array => $this->presentResolved($action))
            ->all();

        return Inertia::render('Governance/ApprovalQueue', [
            'pending' => $pending,
            'resolved' => $resolved,
        ]);
    }

    public function approve(Request $request, string $id): RedirectResponse
    {
        Gate::authorize('ai.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $action = AgentAction::query()->whereKey($id)->firstOrFail();

        try {
            // Approve AS-IS through the existing path. No edited payload, no autonomy input:
            // the request body cannot raise autonomy or alter the tool that runs. The service
            // re-authorizes against the tool's permission (403 if the reviewer lacks it —
            // left to propagate) and executes only through tool->execute().
            app(ApprovalQueue::class)->approve($action, $actor);
        } catch (AiCoreException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return redirect()->route('governance.approvals.index')->with('status', 'approved');
    }

    public function reject(Request $request, string $id): RedirectResponse
    {
        Gate::authorize('ai.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $action = AgentAction::query()->whereKey($id)->firstOrFail();

        try {
            // Reject does nothing but record the decision — no tool executes.
            app(ApprovalQueue::class)->reject($action, $actor, $data['reason']);
        } catch (AiCoreException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return redirect()->route('governance.approvals.index')->with('status', 'rejected');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPending(AgentAction $action, ToolRegistry $tools, User $actor): array
    {
        [$toolName, $category, $canReview] = $this->toolContext($action->tool_key, $tools, $actor);

        return [
            'id' => $action->id,
            'agent' => $action->agent,
            'feature' => $action->feature,
            'toolKey' => $action->tool_key,
            'toolName' => $toolName,
            'category' => $category,
            'autonomyLevel' => $action->autonomy_level,
            'why' => $action->why,
            'proposedOutput' => $action->proposed_output,
            'diff' => $action->diff,
            'canReview' => $canReview,
            'approveUrl' => route('governance.approvals.approve', $action->id),
            'rejectUrl' => route('governance.approvals.reject', $action->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentResolved(AgentAction $action): array
    {
        return [
            'id' => $action->id,
            'agent' => $action->agent,
            'toolKey' => $action->tool_key,
            'status' => $action->status,
            'reviewedBy' => $action->reviewed_by,
            'rejectionReason' => $action->rejection_reason,
            'resolvedAt' => ($action->executed_at ?? $action->rejected_at)?->toIso8601String(),
        ];
    }

    /**
     * Resolve display context for a tool, and whether THIS reviewer holds the tool's
     * permission — a UX hint mirroring the service-side authorize(); the server stays
     * authoritative. An unregistered tool key degrades to "cannot review".
     *
     * @return array{0: string|null, 1: string|null, 2: bool}
     */
    private function toolContext(string $toolKey, ToolRegistry $tools, User $actor): array
    {
        try {
            $definition = $tools->get($toolKey)->definition();
        } catch (AiCoreException) {
            return [null, null, false];
        }

        return [
            $definition->name,
            $definition->category,
            Gate::forUser($actor)->allows($definition->permission),
        ];
    }
}
