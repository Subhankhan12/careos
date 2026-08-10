<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Modules\AiCore\Models\Agent;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\AiInteraction;
use Modules\AiCore\Services\AgentRegistry;

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

        // approved-as-is % = executed-without-edit / total resolved. Honestly absent (null → "—") when
        // no action has resolved yet — never a fabricated 0/100.
        $resolved = AgentAction::query()
            ->whereIn('agent', $names)
            ->whereIn('status', [AgentAction::STATUS_EXECUTED, AgentAction::STATUS_REJECTED, AgentAction::STATUS_FENCE_REFUSED])
            ->count();

        $approvedAsIsPct = null;
        if ($resolved > 0) {
            $approvedAsIs = AgentAction::query()
                ->whereIn('agent', $names)
                ->where('status', AgentAction::STATUS_EXECUTED)
                ->whereNull('edited_payload')
                ->count();

            $approvedAsIsPct = (int) round($approvedAsIs / $resolved * 100);
        }

        return [
            'draftsToday' => $draftsToday,
            'approvedAsIsPct' => $approvedAsIsPct,
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
        return AiInteraction::query()
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
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
