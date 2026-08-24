<?php

namespace App\Http\Controllers;

use App\Services\AgentMetricsService;
use App\Services\NeedsHumanReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\AiInteraction;
use Modules\AiCore\Services\KillSwitch;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\ReconciliationRun;
use Modules\Platform\Models\IntegrityCheck;
use Modules\Platform\Models\Setting;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;

/**
 * Governance dashboard (CLINIC.W9) — a STRICTLY READ-ONLY oversight window onto the
 * tenant's governance/audit posture, assembled entirely from EXISTING data:
 *
 *  - audit-chain validity: a live {@see AuditService::verifyChain()} replay (a pure read
 *    that writes nothing) plus the latest scheduled {@see IntegrityCheck} (the D-069
 *    evidence trail);
 *  - billing reconciliation: the latest {@see ReconciliationRun} (the D-068 launch-blocker
 *    monitor) plus the persisted `billing.reconciliation.alarm` setting;
 *  - AI usage: outcome counts + integer-minor cost over the append-only ai_interactions
 *    ledger for a fixed window;
 *  - the approval-queue depth (pending agent actions), cross-linked to Part B;
 *  - recent audit events + security-relevant events (role changes, AI kill switches),
 *    DISPLAYED, never editable.
 *
 * There is NO mutation path here: audit_events / ai_interactions / integrity_checks /
 * reconciliation_runs are all append-only at model + DB-trigger level, and this controller
 * only ever reads them. The single POST ({@see verifyChain()}) RUNS the existing
 * verification and shows the result — it writes nothing. It lives in the app layer because
 * it composes Audit + Platform + Billing + AiCore, which no single module may do. Gated on
 * `audit.view`; tenant-scoped (AuditEvent has no BelongsToTenant, so tenant_id is filtered
 * explicitly).
 */
class GovernanceDashboardController
{
    /** How far back the AI-usage summary looks. */
    private const AI_WINDOW_DAYS = 30;

    /**
     * GOV.P1 — the ranges the governance window offers, in days back from today.
     *
     * These are SERVER-side parameters, not client filters: picking one re-requests this page and
     * the reader recomputes from the real records. A client-side re-slice would only ever be able
     * to narrow what was already fetched, and would quietly disagree with the database as soon as
     * the window exceeded the page size (the BILLAR.P6 rule).
     *
     * @var array<string, int>
     */
    private const RANGES = [
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
    ];

    private const DEFAULT_RANGE = '30d';

    /** How many recent rows each activity list surfaces. */
    private const ACTIVITY_LIMIT = 15;

    private const SECURITY_LIMIT = 10;

    /**
     * Audit actions worth surfacing as security-relevant. Any that never occur simply
     * make the list shorter — no harm.
     *
     * @var list<string>
     */
    private const SECURITY_ACTIONS = [
        'role.assigned',
        'role.revoked',
        'allergy.override',
        'patient.merged',
        'patient.unmerged',
        'break_glass.granted',
        'tenant.status_changed',
        'agent_action.rejected',
    ];

    public function index(
        Request $request,
        AuditService $audit,
        SettingsService $settings,
        KillSwitch $killSwitch,
        AgentMetricsService $metrics,
        NeedsHumanReader $needsHuman,
    ): Response {
        Gate::authorize('audit.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $tenantId = $actor->tenant_id;

        // The window comes from the REQUEST and is resolved here; an unknown value falls back to
        // the default rather than erroring, so a hand-edited URL cannot break the page.
        $range = (string) $request->query('range', self::DEFAULT_RANGE);
        $days = self::RANGES[$range] ?? self::RANGES[self::DEFAULT_RANGE];
        $range = array_key_exists($range, self::RANGES) ? $range : self::DEFAULT_RANGE;
        $to = Carbon::today();
        $from = $to->copy()->subDays($days - 1);

        return Inertia::render('Governance/Dashboard', [
            /*
             * GOV.P1 — the windowed governance metrics (G1). Computed SERVER-side by the same
             * reader the agent pages use, so the two surfaces cannot disagree about a number.
             * Every figure is a real count or an honest null; see the reader's docblock for the
             * list of things the wireframe asked for that are deliberately absent.
             */
            /*
             * GOV.P2 — what is actually waiting on a person right now. Scoped PER CATEGORY to what
             * this viewer may see (fail-closed), and deliberately not windowed: a queue depth is a
             * fact about now, not about a period.
             */
            'needsHuman' => $needsHuman->forUser($actor),
            'metrics' => $metrics->window($from, $to),
            'windowLedger' => $metrics->ledgerForWindow($from, $to, 40),
            'range' => $range,
            'ranges' => array_keys(self::RANGES),
            // The fence-vault invariants, mirrored from the SAME list the agent page shows
            // (AGENT.P3). Display only — there is no route here, or anywhere, to disable one.
            'fenceInvariants' => AgentConfigController::FENCE_INVARIANTS,
            'agentsUrl' => route('governance.agents.index'),
            'dashboardUrl' => route('governance.dashboard'),
            'chain' => $this->chainStatus($audit, $tenantId),
            'reconciliation' => $this->reconciliationStatus($settings),
            'ai' => $this->aiUsage($settings),
            'queue' => [
                'pending' => AgentAction::query()->where('status', AgentAction::STATUS_PENDING)->count(),
                'url' => route('governance.approvals.index'),
            ],
            'kill' => ['disabledFeatures' => $this->disabledFeatures($killSwitch)],
            'activity' => $this->events($tenantId, self::ACTIVITY_LIMIT),
            'security' => $this->events($tenantId, self::SECURITY_LIMIT, self::SECURITY_ACTIONS),
            'verifyUrl' => route('governance.verify-chain'),
        ]);
    }

    /**
     * Re-run the EXISTING chain verification on demand and flash the result. This is a
     * pure read: verifyChain() replays the chain and returns a verdict — it writes
     * nothing, mutates nothing, and cannot alter any record.
     */
    public function verifyChain(Request $request, AuditService $audit): RedirectResponse
    {
        Gate::authorize('audit.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $result = $audit->verifyChain($actor->tenant_id);

        return redirect()->route('governance.dashboard')
            ->with('status', $result['ok'] ? 'chain_ok' : 'chain_broken');
    }

    /**
     * @return array<string, mixed>
     */
    private function chainStatus(AuditService $audit, ?string $tenantId): array
    {
        $live = $audit->verifyChain($tenantId);

        $last = IntegrityCheck::query()
            ->where('kind', IntegrityCheck::KIND_AUDIT_CHAIN)
            ->orderByDesc('checked_at')
            ->first();

        return [
            'ok' => $live['ok'],
            'count' => $live['count'] ?? null,
            'brokenAt' => $live['broken_at'] ?? null,
            'reason' => $live['reason'] ?? null,
            'lastCheckedAt' => $last?->checked_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reconciliationStatus(SettingsService $settings): array
    {
        $run = ReconciliationRun::query()->orderByDesc('ran_at')->first();
        $alarm = $settings->get('billing.reconciliation.alarm');

        return [
            'period' => $run?->period,
            'passed' => $run?->passed,
            'ranAt' => $run?->ran_at?->toIso8601String(),
            'alarm' => is_array($alarm) ? $alarm : null,
        ];
    }

    /**
     * AI-usage facts over the window: total interactions, count per outcome, and the
     * integer-minor cost sum. Facts only — counts and a sum, nothing graded.
     *
     * @return array<string, mixed>
     */
    private function aiUsage(SettingsService $settings): array
    {
        $since = now()->subDays(self::AI_WINDOW_DAYS);

        $interactions = AiInteraction::query()
            ->where('occurred_at', '>=', $since)
            ->get(['outcome', 'cost_minor']);

        $byOutcome = $interactions
            ->groupBy('outcome')
            ->map(fn ($group): int => $group->count())
            ->sortKeys()
            ->all();

        return [
            'windowDays' => self::AI_WINDOW_DAYS,
            'total' => $interactions->count(),
            'byOutcome' => $byOutcome,
            'costMinor' => (int) $interactions->sum('cost_minor'),
            'currency' => (string) $settings->get('currency', 'EUR'),
        ];
    }

    /**
     * Feature keys the tenant has explicitly disabled via the kill switch. Determined
     * through the existing {@see KillSwitch::enabled()} read, so it matches runtime truth.
     *
     * @return array<int, string>
     */
    private function disabledFeatures(KillSwitch $killSwitch): array
    {
        return Setting::query()
            ->where('key', 'like', 'ai.feature.%.enabled')
            ->get(['key'])
            ->map(fn (Setting $s): string => Str::of($s->key)->after('ai.feature.')->beforeLast('.enabled')->toString())
            ->filter(fn (string $feature): bool => ! $killSwitch->enabled($feature))
            ->values()
            ->all();
    }

    /**
     * Recent audit events for this tenant, most recent first, DISPLAYED read-only.
     * AuditEvent has no BelongsToTenant scope (Audit may not depend on Platform), so the
     * tenant filter is explicit — the isolation guarantee this whole surface relies on.
     *
     * @param  list<string>|null  $actions
     * @return list<array<string, mixed>>
     */
    private function events(?string $tenantId, int $limit, ?array $actions = null): array
    {
        $query = AuditEvent::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit);

        if ($actions !== null) {
            $query->whereIn('action', $actions);
        }

        return $query->get(['id', 'occurred_at', 'action', 'actor_type', 'resource_type'])
            ->map(fn (AuditEvent $e): array => [
                'id' => $e->id,
                'occurredAt' => $e->occurred_at,
                'action' => $e->action,
                'actorType' => $e->actor_type,
                'resourceType' => $e->resource_type,
            ])
            ->all();
    }
}
