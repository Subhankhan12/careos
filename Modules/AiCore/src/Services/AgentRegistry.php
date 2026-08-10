<?php

namespace Modules\AiCore\Services;

use Modules\AiCore\Models\Agent;

/**
 * The canonical governed agents — each maps to a real code-class agent path and the real governed
 * tools it may call (every tool key here is a registered {@see ToolRegistry} tool). This is the
 * single source of truth for seeding {@see Agent} rows per tenant (AGENT.P1).
 *
 * An agent is a GOVERNED CONTAINER, never a source of authority: its configured level + whitelist can
 * only NARROW what it may do. The effective autonomy for any (agent, tool) is resolved as
 * MIN(configured, tool ceiling, role ceiling) by {@see AgentResolver} — whitelisting a tool never
 * grants it past the tool/role ceiling.
 */
class AgentRegistry
{
    /**
     * key => [name, tools[]]. Covers the 10 real governed tools across the real agent classes:
     * inbox (InboxAgent), scheduler (Scheduler Agent tools), recall (FollowUpAgent),
     * clinical_summary (ClinicalSummaryAgent), dispatch (DispatchAgent), billing (BillingAgent).
     *
     * @var array<string, array{name: string, tools: list<string>}>
     */
    public const AGENTS = [
        'inbox' => ['name' => 'Front-desk (inbox)', 'tools' => ['comms.draft_reply', 'comms.classify_document']],
        'scheduler' => ['name' => 'Scheduler', 'tools' => ['scheduler.suggest_slots', 'scheduler.fill_from_waitlist']],
        'recall' => ['name' => 'Recall reminders', 'tools' => ['clinical.draft_recall_message']],
        'clinical_summary' => ['name' => 'Clinical summary', 'tools' => ['clinical.summarize_since_last_visit']],
        'dispatch' => ['name' => 'Dispatch', 'tools' => ['nursing.propose_assignments', 'nursing.replan_day']],
        'billing' => ['name' => 'Billing', 'tools' => ['billing.suggest_charge_codes', 'billing.preflight_invoice']],
    ];

    /**
     * How each canonical agent appears in the `agent` column of the append-only `ai_interactions`
     * ledger + `agent_actions` (AGENT.P5 metrics attribution). The column is a FREE-FORM string set
     * by whoever proposed the action — there is NO enforced FK to {@see Agent::$key} — and the
     * codebase writes it under two conventions: the bare canonical key (seeders + direct
     * ApprovalQueue calls, e.g. 'inbox'/'scheduler') and the code-class agent constant (the
     * production agent classes, e.g. InboxAgent::AGENT = 'inbox-agent'). This map is the single
     * source of truth for the string(s) that belong to each canonical agent, so per-agent metrics
     * are computed from REAL rows (a `WHERE agent IN (...)`), never a guessed join.
     *
     * @var array<string, list<string>>
     */
    public const LEDGER_ALIASES = [
        'inbox' => ['inbox', 'inbox-agent', 'front-desk-agent'],
        'scheduler' => ['scheduler', 'scheduler-agent'],
        'recall' => ['recall', 'clinical-follow-up-agent'],
        'clinical_summary' => ['clinical_summary', 'clinical-summary-agent'],
        'dispatch' => ['dispatch', 'nursing-dispatch-agent'],
        'billing' => ['billing', 'billing-agent'],
    ];

    /**
     * The ledger `agent` strings that belong to a canonical agent key (for a `WHERE agent IN (...)`).
     *
     * @return list<string>
     */
    public static function ledgerNames(string $key): array
    {
        return self::LEDGER_ALIASES[$key] ?? [$key];
    }

    /**
     * The friendly canonical name for a raw ledger `agent` string, or the raw string itself when it
     * maps to no canonical agent (e.g. 'demo-agent') — honest, never invented.
     */
    public static function displayName(string $ledgerAgent): string
    {
        foreach (self::LEDGER_ALIASES as $key => $names) {
            if (in_array($ledgerAgent, $names, true)) {
                return self::AGENTS[$key]['name'];
            }
        }

        return $ledgerAgent;
    }
}
