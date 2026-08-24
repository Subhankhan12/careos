<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\AiCore\Models\Agent;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\AiInteraction;
use Modules\AiCore\Services\AgentRegistry;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolRegistry;

/**
 * Per-agent metrics + the action-ledger view (AGENT.P5) — computed ONLY from real records: the
 * append-only {@see AiInteraction} ledger + the {@see AgentAction} approval-queue outcomes. THE
 * HONESTY RULE: every number is a real count from a real column, or honestly absent (null → "—")
 * when there is no source. Nothing is fabricated, estimated, or hand-inserted. Rows are attributed
 * to a canonical agent via {@see AgentRegistry::ledgerNames()} (the free-form `agent` column has no
 * FK to Agent::key, so the alias map is the single source of truth).
 */
class AgentMetricsService
{
    private const FENCE_WINDOW_DAYS = 7;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly AutonomyPolicy $autonomy,
    ) {}

    /**
     * The hero metrics for one agent — each a REAL count, or null where there is no real source:
     *  - draftsToday: proposed interactions today (append-only ledger, outcome 'proposed').
     *  - approvedAsIsPct: executed-without-edit / total resolved (null when nothing is resolved yet).
     *  - fenceRefused7d: real P5 fence_refused actions in the last 7 days.
     *
     * @return array{draftsToday: int, approvedAsIsPct: int|null, fenceRefused7d: int}
     */
    public function hero(Agent $agent): array
    {
        $names = AgentRegistry::ledgerNames($agent->kind());

        $draftsToday = AiInteraction::query()
            ->whereIn('agent', $names)
            ->where('outcome', 'proposed')
            ->where('occurred_at', '>=', Carbon::now()->startOfDay())
            ->count();

        $fenceRefused7d = AgentAction::query()
            ->whereIn('agent', $names)
            ->where('status', AgentAction::STATUS_FENCE_REFUSED)
            ->where('fence_refused_at', '>=', Carbon::now()->subDays(self::FENCE_WINDOW_DAYS))
            ->count();

        return [
            'draftsToday' => $draftsToday,
            // ONE definition of approved-as-is, shared with the windowed reader below, so the agent
            // pages and the governance dashboard can never disagree about the same number.
            'approvedAsIsPct' => $this->approvedAsIsPct($names),
            'fenceRefused7d' => $fenceRefused7d,
        ];
    }

    /**
     * The action-ledger view — the most recent REAL rows from the append-only ai_interactions ledger,
     * tenant-scoped (the model's global scope), newest first. A read-only VIEW of an immutable table.
     *
     * @return list<array<string, mixed>>
     */
    public function ledger(int $limit = 100): array
    {
        return $this->presentLedger(
            AiInteraction::query()
                ->orderByDesc('occurred_at')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * The ONE ledger presenter, shared by the agent-page tab and the dashboard's windowed table.
     *
     * @param  Collection<int, AiInteraction>  $rows
     * @return list<array<string, mixed>>
     */
    private function presentLedger(Collection $rows): array
    {
        return $rows
            ->map(fn (AiInteraction $row): array => [
                'id' => $row->id,
                'agent' => $row->agent,
                'agentLabel' => AgentRegistry::displayName($row->agent),
                'feature' => $row->feature,
                'tool' => $this->firstTool($row) ?? $row->feature,
                'outcome' => $row->outcome,
                // The fence's own reason (or the recorded error) — real text, or null. No invention.
                'reason' => $row->metadata['reason'] ?? $row->error_message,
                'occurredAt' => optional($row->getAttribute('occurred_at'))->toIso8601String(),
                // A fence refusal is system-attributed in the UI (the fence fired, not the reviewer).
                'system' => $row->outcome === AgentAction::STATUS_FENCE_REFUSED,
            ])
            ->values()
            ->all();
    }

    /**
     * G1 (GOV.P1) — the windowed governance metrics the dashboard renders.
     *
     * THE HONESTY RULE, unchanged from AGENT.P5: every figure below is a real count of real rows, or
     * honestly absent. There is no rate, confidence, accuracy, quality score or trend verdict here,
     * because the records that would source them do not exist — see the governance batch audit
     * (GOVERNANCE-AI-BATCH-DIFF.md §4.4) for the list of numbers the wireframe wanted and why each
     * one is not here.
     *
     * WHICH TIMESTAMP A WINDOW USES. An action is counted in the window in which the thing HAPPENED:
     * a resolved action by when it resolved (executed / rejected / fence-refused at), a pending one
     * by when it was raised. Windowing everything on `created_at` would drop an action raised weeks
     * ago and approved this morning out of "this week", which is the opposite of what an oversight
     * screen is for.
     *
     * TOOLS ARE THE REGISTRY'S. Per-tool counts are emitted for REGISTERED tools only. The ten
     * governed tools are the whole set, and a governance screen that printed some other key would be
     * asserting that a capability exists (D-170) — the wireframe does exactly this with `comms.send`,
     * `clinical.sign` and `billing.charge`, none of which were ever built. Rows whose `tool_key` is
     * not in the registry are not silently dropped either: they are counted in `unregisteredTools`,
     * so the screen can say the number without naming a tool that does not exist.
     *
     * @return array{
     *     from: string, to: string,
     *     byStatus: array<string, int>,
     *     byAgent: list<array{key: string, name: string, total: int, byStatus: array<string, int>, approvedAsIsPct: int|null}>,
     *     byTool: list<array{key: string, name: string, category: string, ceiling: string, total: int}>,
     *     unregisteredTools: int,
     *     ledgerTotal: int,
     *     ledgerByOutcome: array<string, int>,
     *     fenceRefused: int,
     *     pendingNow: int
     * }
     */
    public function window(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->endOfDay();

        $actions = $this->actionsInWindow($from, $to);

        $byStatus = $actions
            ->groupBy('status')
            ->map(fn ($rows): int => $rows->count())
            ->sortKeys()
            ->all();

        // Per-agent: the canonical agents, each attributed through the SAME alias map the agent
        // pages use. An agent with nothing in the window still appears, with zeroes — an absent row
        // would read as "no such agent" rather than "nothing happened".
        $byAgent = [];
        foreach (AgentRegistry::AGENTS as $key => $definition) {
            $names = AgentRegistry::ledgerNames($key);
            $mine = $actions->filter(fn (AgentAction $a): bool => in_array($a->agent, $names, true));

            $byAgent[] = [
                'key' => $key,
                'name' => $definition['name'],
                'total' => $mine->count(),
                'byStatus' => $mine->groupBy('status')->map(fn ($rows): int => $rows->count())->sortKeys()->all(),
                // Lifetime, not windowed — the same number the agent's own page shows, from the same
                // helper. A percentage over a seven-day slice would be a different statistic wearing
                // the same label.
                'approvedAsIsPct' => $this->approvedAsIsPct($names),
            ];
        }

        // Per-tool: registry keys only, with the tool's REAL category and ceiling beside the count,
        // so the screen states the cap rather than implying autonomy the resolver would refuse.
        $registered = $this->tools->all();
        $byTool = [];
        $unregistered = 0;

        foreach ($actions->groupBy('tool_key') as $toolKey => $rows) {
            $key = (string) $toolKey;

            if (! array_key_exists($key, $registered)) {
                $unregistered += $rows->count();

                continue;
            }

            $definition = $registered[$key]->definition();
            $byTool[] = [
                'key' => $key,
                'name' => $definition->name,
                'category' => $definition->category,
                'ceiling' => $this->autonomy->effectiveCeiling($definition),
                'total' => $rows->count(),
            ];
        }

        usort($byTool, fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: strcmp($a['key'], $b['key']));

        $ledger = AiInteraction::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->get(['outcome']);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'byStatus' => $byStatus,
            'byAgent' => $byAgent,
            'byTool' => $byTool,
            'unregisteredTools' => $unregistered,
            'ledgerTotal' => $ledger->count(),
            'ledgerByOutcome' => $ledger->groupBy('outcome')->map(fn ($rows): int => $rows->count())->sortKeys()->all(),
            // The one fence figure that IS countable: refusals recorded by APPROVAL.P5. There is no
            // "breaches" counter here, and there will not be one — nothing records a breach, so a
            // zero would be unfalsifiable rather than reassuring.
            'fenceRefused' => $byStatus[AgentAction::STATUS_FENCE_REFUSED] ?? 0,
            // Deliberately NOT windowed: a queue depth is a fact about now, not about a period.
            'pendingNow' => AgentAction::query()->where('status', AgentAction::STATUS_PENDING)->count(),
        ];
    }

    /**
     * The real ledger rows for a window, newest first — the same shape the agent pages render, so
     * the two tables cannot drift apart.
     *
     * @return list<array<string, mixed>>
     */
    public function ledgerForWindow(CarbonInterface $from, CarbonInterface $to, int $limit = 100): array
    {
        return $this->presentLedger(
            AiInteraction::query()
                ->whereBetween('occurred_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
                ->orderByDesc('occurred_at')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Actions whose decisive moment falls inside the window: resolved ones by when they resolved,
     * pending ones by when they were raised.
     *
     * @return Collection<int, AgentAction>
     */
    private function actionsInWindow(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return AgentAction::query()
            ->where(function ($query) use ($from, $to): void {
                $query
                    ->where(fn ($q) => $q->where('status', AgentAction::STATUS_EXECUTED)->whereBetween('executed_at', [$from, $to]))
                    ->orWhere(fn ($q) => $q->where('status', AgentAction::STATUS_REJECTED)->whereBetween('rejected_at', [$from, $to]))
                    ->orWhere(fn ($q) => $q->where('status', AgentAction::STATUS_FENCE_REFUSED)->whereBetween('fence_refused_at', [$from, $to]))
                    ->orWhere(fn ($q) => $q->where('status', AgentAction::STATUS_PENDING)->whereBetween('created_at', [$from, $to]));
            })
            ->get();
    }

    /**
     * approved-as-is % = executed-without-edit / total resolved, for the given ledger names.
     *
     * Honestly absent (null → "—") when nothing has resolved: never a fabricated 0 or 100. This is
     * the ONE definition — {@see hero()} and {@see window()} both call it, so an agent's own page and
     * the governance dashboard cannot disagree.
     *
     * @param  list<string>  $names
     */
    private function approvedAsIsPct(array $names): ?int
    {
        $resolved = AgentAction::query()
            ->whereIn('agent', $names)
            ->whereIn('status', [AgentAction::STATUS_EXECUTED, AgentAction::STATUS_REJECTED, AgentAction::STATUS_FENCE_REFUSED])
            ->count();

        if ($resolved === 0) {
            return null;
        }

        $approvedAsIs = AgentAction::query()
            ->whereIn('agent', $names)
            ->where('status', AgentAction::STATUS_EXECUTED)
            ->whereNull('edited_payload')
            ->count();

        return (int) round($approvedAsIs / $resolved * 100);
    }

    /** The first tool key on a ledger row's `tool_calls`, if any (the column is nullable JSON). */
    private function firstTool(AiInteraction $row): ?string
    {
        $calls = $row->getAttribute('tool_calls');
        if (is_array($calls) && array_key_exists(0, $calls) && is_array($calls[0]) && array_key_exists('tool', $calls[0])) {
            return (string) $calls[0]['tool'];
        }

        return null;
    }
}
