<?php

namespace App\Http\Controllers;

use App\AiCore\Tools\DraftReplyTool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Exceptions\FenceRefusalException;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\AiCore\Services\AutonomyPolicy;
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

    public function index(Request $request, ToolRegistry $tools, AutonomyPolicy $autonomy): Response
    {
        Gate::authorize('ai.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $pending = AgentAction::query()
            ->where('status', AgentAction::STATUS_PENDING)
            ->orderByDesc('id')
            ->get()
            ->map(fn (AgentAction $action): array => $this->presentPending($action, $tools, $autonomy, $actor))
            ->all();

        $resolved = AgentAction::query()
            ->whereIn('status', [AgentAction::STATUS_EXECUTED, AgentAction::STATUS_REJECTED, AgentAction::STATUS_FENCE_REFUSED])
            ->orderByDesc('id')
            ->limit(self::RESOLVED_LIMIT)
            ->get()
            ->map(fn (AgentAction $action): array => $this->presentResolved($action))
            ->all();

        return Inertia::render('Governance/ApprovalQueue', [
            'pending' => $pending,
            'resolved' => $resolved,
            'stats' => $this->stats(),
        ]);
    }

    /**
     * The governance stat strip — computed from REAL records only. Every value is derived from
     * actual `agent_actions` rows and their timestamps; a metric with no real source (e.g. no
     * resolved actions in the window, so no denominator) is returned as null so the UI can show
     * an honest "—" rather than a fabricated or estimated number.
     *
     * @return array{pending: int, fenceRefused: int, approvedPct: int|null, avgReviewMinutes: float|null, windowDays: int}
     */
    private function stats(): array
    {
        $windowDays = 30;
        $windowStart = Carbon::now()->subDays($windowDays);

        $pending = AgentAction::query()->where('status', AgentAction::STATUS_PENDING)->count();

        // "N refused by fence" — the real count of the fence_refused outcome recorded on approve.
        $fenceRefused = AgentAction::query()->where('status', AgentAction::STATUS_FENCE_REFUSED)->count();

        // Approved-% and avg review time are computed over actions RESOLVED in the window, keyed by
        // their real resolved timestamp (executed_at / rejected_at / fence_refused_at). With no
        // resolved action in the window there is no denominator — both are honestly absent (null).
        $resolved = AgentAction::query()
            ->whereIn('status', [AgentAction::STATUS_EXECUTED, AgentAction::STATUS_REJECTED, AgentAction::STATUS_FENCE_REFUSED])
            ->get(['status', 'created_at', 'executed_at', 'rejected_at', 'fence_refused_at'])
            ->filter(fn (AgentAction $a): bool => ($this->resolvedAt($a)?->gte($windowStart)) === true);

        $totalResolved = $resolved->count();
        $approvedPct = null;
        $avgReviewMinutes = null;

        if ($totalResolved > 0) {
            $approved = $resolved->where('status', AgentAction::STATUS_EXECUTED)->count();
            $approvedPct = (int) round($approved / $totalResolved * 100);

            $seconds = $resolved->sum(function (AgentAction $a): int {
                $resolvedAt = $this->resolvedAt($a);

                return ($resolvedAt !== null && $a->created_at !== null) ? $a->created_at->diffInSeconds($resolvedAt) : 0;
            });
            $avgReviewMinutes = round($seconds / $totalResolved / 60, 1);
        }

        return [
            'pending' => $pending,
            'fenceRefused' => $fenceRefused,
            'approvedPct' => $approvedPct,
            'avgReviewMinutes' => $avgReviewMinutes,
            'windowDays' => $windowDays,
        ];
    }

    /** The real terminal timestamp of a resolved action, whichever outcome ended it. */
    private function resolvedAt(AgentAction $action): ?Carbon
    {
        return $action->executed_at ?? $action->rejected_at ?? $action->fence_refused_at;
    }

    public function approve(Request $request, string $id): RedirectResponse
    {
        Gate::authorize('ai.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        // Edit-before-sending: an OPTIONAL human edit of the drafted payload. When present it is the
        // CONTENT the human is posting through the gate — nothing more. Only `edited_payload` is read,
        // so the request still cannot raise autonomy or swap the tool that runs. ApprovalQueue::approve
        // runs the SAME gate on it as an unedited approve: it re-authorises the reviewer against the
        // tool's own permission (403 if the reviewer lacks it — left to propagate), asserts the action
        // is still pending, and re-runs the tool (re-grounding/re-deriving from live state) before it
        // posts. An edit is NOT a bypass; when supplied it is RECORDED as human-edited by the service.
        $data = $request->validate(['edited_payload' => ['sometimes', 'array']]);
        $editedPayload = $data['edited_payload'] ?? null;

        $action = AgentAction::query()->whereKey($id)->firstOrFail();

        try {
            app(ApprovalQueue::class)->approve($action, $actor, $editedPayload);
        } catch (FenceRefusalException) {
            // The electric fence refused the action. The service already RECORDED it as a terminal
            // fence_refused outcome; surface that honestly (not as the reviewer's error). Must be
            // caught before AiCoreException — FenceRefusalException is a subclass.
            return redirect()->route('governance.approvals.index')->with('status', 'fence_refused');
        } catch (AiCoreException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return redirect()->route('governance.approvals.index')
            ->with('status', $editedPayload !== null ? 'approved_edited' : 'approved');
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
    private function presentPending(AgentAction $action, ToolRegistry $tools, AutonomyPolicy $autonomy, User $actor): array
    {
        [$toolName, $category, $permission, $canReview, $ceiling] = $this->toolContext($action->tool_key, $tools, $autonomy, $actor);

        return [
            'id' => $action->id,
            'agent' => $action->agent,
            'feature' => $action->feature,
            'toolKey' => $action->tool_key,
            'toolName' => $toolName,
            'category' => $category,
            // The tool's REAL declared permission — the same one approve re-authorises against.
            'permission' => $permission,
            'autonomyLevel' => $action->autonomy_level,
            // The tool's effective CEILING (the AutonomyPolicy cap) — distinct from the proposed level.
            'ceiling' => $ceiling,
            'why' => $action->why,
            // Real recorded grounding: the distinct `source` refs the tool put on its draft lines
            // (kb_article / admin_fact). Empty when the action carries none — never fabricated.
            'sources' => $this->sourcesFor($action->proposed_output),
            // Does this action carry a DRAFT (its execute re-grounds a draft, then posts) or is it a
            // direct action (its execute re-derives, then runs)? Drives the honest approve-contract
            // caption so it never claims "re-grounds the draft" for an action with no draft. Read from
            // the action's own proposed_output shape — not a hardcode.
            'reGroundsDraft' => $this->reGroundsDraft($action->proposed_output),
            // The tool INPUT payload — what execute() consumes and re-grounds. Editing this (and
            // submitting it as edited_payload) is what "edit before sending" changes; the tool then
            // re-derives from it through the same gate. Distinct from proposed_output (the preview).
            'inputPayload' => $action->input_payload,
            'proposedOutput' => $action->proposed_output,
            'diff' => $action->diff,
            'queuedAt' => $action->created_at?->toIso8601String(),
            'canReview' => $canReview,
            'approveUrl' => route('governance.approvals.approve', $action->id),
            'rejectUrl' => route('governance.approvals.reject', $action->id),
        ];
    }

    /**
     * The distinct grounding sources an action's draft recorded on its lines — REAL provenance only
     * (`{type: kb_article|admin_fact, id|key}`). Returns [] when the action carries no structured
     * sources (a handoff draft, or a tool that records none); nothing is ever invented.
     *
     * @param  array<string, mixed>|null  $proposedOutput
     * @return list<array{type: string, ref: string}>
     */
    private function sourcesFor(?array $proposedOutput): array
    {
        $lines = is_array($proposedOutput['lines'] ?? null) ? $proposedOutput['lines'] : [];
        $seen = [];
        $sources = [];

        foreach ($lines as $line) {
            $source = is_array($line) && is_array($line['source'] ?? null) ? $line['source'] : null;
            if ($source === null) {
                continue;
            }

            $type = (string) ($source['type'] ?? '');
            $ref = (string) ($source['key'] ?? $source['id'] ?? '');
            $dedupe = $type.'|'.$ref;

            if ($type === '' || $ref === '' || isset($seen[$dedupe])) {
                continue;
            }

            $seen[$dedupe] = true;
            $sources[] = ['type' => $type, 'ref' => $ref];
        }

        return $sources;
    }

    /**
     * Whether this action's approve step re-grounds a DRAFT (vs. re-derives a direct action). True
     * only when the recorded proposed_output is draft-shaped — it carries a body/lines/handoff, the
     * signature a draft tool (e.g. {@see DraftReplyTool}) writes. This is read from
     * the action's own recorded output, so the surfaced approve-contract caption stays accurate per
     * action: a draft says "re-grounds the draft before it posts"; anything else says "re-derives the
     * action before it runs". Both are true of the real approve path — {@see ApprovalQueue::approve()}
     * re-authorises then re-runs the tool (which re-derives from live state) before it takes effect.
     *
     * @param  array<string, mixed>|null  $proposedOutput
     */
    private function reGroundsDraft(?array $proposedOutput): bool
    {
        if (! is_array($proposedOutput)) {
            return false;
        }

        return array_key_exists('body', $proposedOutput)
            || array_key_exists('lines', $proposedOutput)
            || array_key_exists('handoff', $proposedOutput);
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
            // For a fence_refused row this carries the fence's own reason (kept in rejection_reason,
            // distinguished by the status) — a human reject carries the reviewer's reason.
            'rejectionReason' => $action->rejection_reason,
            'resolvedAt' => $this->resolvedAt($action)?->toIso8601String(),
        ];
    }

    /**
     * Resolve display context for a tool: its name, category, the REAL required permission (the one
     * approve re-authorises against), whether THIS reviewer holds it (a UX hint; the server stays
     * authoritative), and the tool's effective CEILING (the AutonomyPolicy cap). An unregistered
     * tool key degrades to "cannot review". All values are read from the tool's own declaration —
     * none are fabricated.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: bool, 4: string|null}
     */
    private function toolContext(string $toolKey, ToolRegistry $tools, AutonomyPolicy $autonomy, User $actor): array
    {
        try {
            $definition = $tools->get($toolKey)->definition();
        } catch (AiCoreException) {
            return [null, null, null, false, null];
        }

        return [
            $definition->name,
            $definition->category,
            $definition->permission,
            Gate::forUser($actor)->allows($definition->permission),
            $autonomy->effectiveCeiling($definition),
        ];
    }
}
