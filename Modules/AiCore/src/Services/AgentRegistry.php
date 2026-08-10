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
}
