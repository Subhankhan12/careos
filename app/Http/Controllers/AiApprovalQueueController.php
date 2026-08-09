<?php

namespace App\Http\Controllers;

use App\AiCore\Tools\DraftReplyTool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

        // ── Resolved view (search + status/date/reviewer filters + grouping), over REAL data ──────
        // RBAC: the resolved history is limited to tools THIS reviewer may review — the same per-tool
        // permission the approve gate enforces — so a reviewer never sees (or counts) actions they
        // could not have acted on. An unregistered tool_key is excluded (fail-closed).
        $filters = $this->resolvedFilters($request);
        $allowedToolKeys = $this->allowedToolKeys($tools, $actor);

        $base = AgentAction::query()
            ->whereIn('status', [AgentAction::STATUS_EXECUTED, AgentAction::STATUS_REJECTED, AgentAction::STATUS_FENCE_REFUSED])
            ->whereIn('tool_key', $allowedToolKeys);
        $this->applyResolvedFilters($base, $filters); // search / reviewer / date — NOT the status pill

        // Real per-status counts over the filtered base (before the status pill narrows the list).
        $countRows = (clone $base)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $resolvedCounts = [
            'all' => (int) ($countRows[AgentAction::STATUS_EXECUTED] ?? 0) + (int) ($countRows[AgentAction::STATUS_REJECTED] ?? 0) + (int) ($countRows[AgentAction::STATUS_FENCE_REFUSED] ?? 0),
            'executed' => (int) ($countRows[AgentAction::STATUS_EXECUTED] ?? 0),
            'rejected' => (int) ($countRows[AgentAction::STATUS_REJECTED] ?? 0),
            'fence_refused' => (int) ($countRows[AgentAction::STATUS_FENCE_REFUSED] ?? 0),
        ];

        $listQuery = clone $base;
        if ($filters['status'] !== 'all') {
            $listQuery->where('status', $filters['status']);
        }
        $rows = $listQuery
            ->orderByRaw('COALESCE(executed_at, rejected_at, fence_refused_at) DESC')
            ->limit(self::RESOLVED_LIMIT)
            ->get();

        // Resolve human reviewer names in bulk (fence_refused rows are system-attributed, not a human).
        $reviewerIds = $rows->reject(fn (AgentAction $a): bool => $a->status === AgentAction::STATUS_FENCE_REFUSED)
            ->pluck('reviewed_by')->filter()->unique()->values();
        $reviewerNames = $reviewerIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $reviewerIds->all())->pluck('name', 'id');
        $toolNames = $this->toolNames($tools, $rows->pluck('tool_key')->unique()->all());

        $resolved = $rows->map(fn (AgentAction $action): array => $this->presentResolved($action, $reviewerNames, $toolNames))->all();

        return Inertia::render('Governance/ApprovalQueue', [
            'pending' => $pending,
            'resolved' => $resolved,
            'stats' => $this->stats(),
            'resolvedCounts' => $resolvedCounts,
            'resolvedReviewers' => $this->resolvedReviewers($allowedToolKeys),
            'resolvedFilters' => $filters,
        ]);
    }

    /**
     * The active resolved-view filters, from real query params only. Every value maps to a real
     * column: status → the action status; q → a text search over tool_key/agent/feature/why/reason;
     * reviewer → reviewed_by; from/to → the resolved timestamp. Unknown/invalid values fall back to
     * a safe default (no fabricated attribute is ever filterable).
     *
     * @return array{status: string, q: string, reviewer: string, from: string, to: string}
     */
    private function resolvedFilters(Request $request): array
    {
        $status = (string) $request->query('rstatus', 'all');
        if (! in_array($status, ['all', AgentAction::STATUS_EXECUTED, AgentAction::STATUS_REJECTED, AgentAction::STATUS_FENCE_REFUSED], true)) {
            $status = 'all';
        }

        $date = static fn (string $v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : '';

        return [
            'status' => $status,
            'q' => trim((string) $request->query('rq', '')),
            'reviewer' => trim((string) $request->query('rreviewer', '')),
            'from' => $date(trim((string) $request->query('rfrom', ''))),
            'to' => $date(trim((string) $request->query('rto', ''))),
        ];
    }

    /**
     * Apply the search / reviewer / date filters (NOT the status pill) to a resolved query. Every
     * clause targets a REAL column: the free-text search matches the action's own tool key, agent,
     * feature, recorded why, and recorded reason (the searchable text columns — not the nested
     * payload); reviewer is reviewed_by; the date range is the real resolved timestamp.
     *
     * @param  Builder<AgentAction>  $query
     * @param  array{status: string, q: string, reviewer: string, from: string, to: string}  $filters
     */
    private function applyResolvedFilters($query, array $filters): void
    {
        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';
            $query->where(function ($sub) use ($like): void {
                $sub->where('tool_key', 'like', $like)
                    ->orWhere('agent', 'like', $like)
                    ->orWhere('feature', 'like', $like)
                    ->orWhere('why', 'like', $like)
                    ->orWhere('rejection_reason', 'like', $like);
            });
        }

        if ($filters['reviewer'] !== '') {
            $query->where('reviewed_by', $filters['reviewer']);
        }

        if ($filters['from'] !== '') {
            $query->whereRaw('COALESCE(executed_at, rejected_at, fence_refused_at) >= ?', [$filters['from'].' 00:00:00']);
        }
        if ($filters['to'] !== '') {
            $query->whereRaw('COALESCE(executed_at, rejected_at, fence_refused_at) <= ?', [$filters['to'].' 23:59:59']);
        }
    }

    /**
     * The tool keys THIS reviewer may review — the ones whose declared permission the actor holds
     * (the same per-tool gate the approve path enforces). Used to RBAC-scope the resolved history
     * and its counts so a reviewer never sees actions they could not have acted on.
     *
     * @return list<string>
     */
    private function allowedToolKeys(ToolRegistry $tools, User $actor): array
    {
        $keys = [];
        foreach ($tools->all() as $key => $tool) {
            if (Gate::forUser($actor)->allows($tool->definition()->permission)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Distinct HUMAN reviewers who resolved (approved/rejected) an action within the RBAC-allowed
     * set — the real options for the reviewer sub-filter. Fence refusals are system-attributed and
     * are reached through the Fence-refused status pill, not this list.
     *
     * @param  list<string>  $allowedToolKeys
     * @return list<array{id: string, name: string}>
     */
    private function resolvedReviewers(array $allowedToolKeys): array
    {
        $ids = AgentAction::query()
            ->whereIn('status', [AgentAction::STATUS_EXECUTED, AgentAction::STATUS_REJECTED])
            ->whereIn('tool_key', $allowedToolKeys)
            ->whereNotNull('reviewed_by')
            ->distinct()
            ->pluck('reviewed_by')
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u): array => ['id' => (string) $u->id, 'name' => (string) $u->name])
            ->all();
    }

    /**
     * Map each tool key to its real declared name (unregistered → null). Presentation only.
     *
     * @param  list<string>  $toolKeys
     * @return array<string, string|null>
     */
    private function toolNames(ToolRegistry $tools, array $toolKeys): array
    {
        $names = [];
        foreach ($toolKeys as $key) {
            try {
                $names[$key] = $tools->get($key)->definition()->name;
            } catch (AiCoreException) {
                $names[$key] = null;
            }
        }

        return $names;
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
     * @param  Collection<string, string>  $reviewerNames  reviewed_by → user name
     * @param  array<string, string|null>  $toolNames  tool_key → declared name
     * @return array<string, mixed>
     */
    private function presentResolved(AgentAction $action, $reviewerNames, array $toolNames): array
    {
        // Fence refusals are the electric fence's own outcome — system-attributed, NOT a human
        // reviewer, even though a human's approve click triggered the (recorded) refusal.
        $isFence = $action->status === AgentAction::STATUS_FENCE_REFUSED;

        return [
            'id' => $action->id,
            'agent' => $action->agent,
            'toolKey' => $action->tool_key,
            'toolName' => $toolNames[$action->tool_key] ?? null,
            'status' => $action->status,
            // Real attribution: the human reviewer who resolved it, or system for a fence refusal.
            'reviewedBy' => $isFence ? null : $action->reviewed_by,
            'reviewerName' => $isFence ? null : ($action->reviewed_by !== null ? ($reviewerNames[$action->reviewed_by] ?? null) : null),
            'systemAttributed' => $isFence,
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
