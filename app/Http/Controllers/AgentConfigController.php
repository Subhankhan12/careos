<?php

namespace App\Http\Controllers;

use App\Services\AgentConfigService;
use App\Services\AgentMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Models\Agent;
use Modules\AiCore\Services\AgentRegistry;
use Modules\AiCore\Services\AgentResolver;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\User;
use Modules\Platform\Services\RbacProvisioner;

/**
 * The per-agent governance surface (AGENT.P2) — an agent list + per-agent detail shell + the
 * AUTONOMY LADDER, presentation over the AGENT.P1 capped resolver. It reads the tenant's real
 * {@see Agent} rows and lets a human set each agent's level/status through the sanctioned write
 * path ({@see AgentConfigService}). It introduces NO new autonomy:
 *
 *  - the agent list is the tenant's real {@see Agent} rows (tenant-scoped by the model's global
 *    scope; resolved by string id so a cross-tenant id fails closed as 404 — the FIX.1 rule).
 *  - the ladder offers ONLY levels at or below the agent's EFFECTIVE CEILING
 *    ({@see AgentResolver::agentCeiling()} = MIN of the tool ceilings the agent touches). Higher
 *    rungs render LOCKED; the runtime further narrows by the role ceiling + the fence (P1).
 *  - saving CLAMPS the requested level to that ceiling BEFORE persisting, then writes through
 *    {@see AgentConfigService::configure()} (clamp-on-write + audit). The {@see AgentResolver}
 *    caps again at call time regardless — a forged level above the ceiling cannot widen authority.
 *
 * Gated on `admin.manage`; tenant-scoped; the change is audited (`agent.configured`, P1). It also
 * surfaces two REFLECT-ONLY / TOGGLE-FREE panels (AGENT.P3): the per-agent permission-ceiling MIRROR
 * (role-derived, read-only — no permission-edit path here) and the electric-fence VAULT (the
 * code-enforced invariants, no disable path here). The tool WHITELIST is editable (AGENT.P4):
 * enabling/disabling changes WHICH tools the agent may call (narrowing the callable set, clamped to
 * the agent's candidate remit), never a tool's ceiling/permission — the resolver still caps each
 * tool at runtime (P1). The per-agent hero metrics + the action-ledger tab (AGENT.P5) are computed
 * ONLY from real records ({@see AgentMetricsService}) — every number is a real count or honestly
 * absent ("—"), never fabricated. AGENT.P6 adds the rate/timing LIMITS the {@see AgentRuntime}
 * actually reads (max drafts/hour, quiet hours), the ALWAYS-ON uncertainty escalation (the real
 * clinician-attention hand-off — no disable path), and the new-agent wizard (a P1 governed container
 * capped from birth). This completes the Agent & Tool Config surface.
 */
class AgentConfigController
{
    /**
     * Sensitive, HUMAN-ONLY capabilities no agent tool exercises — the withheld side of the
     * permission ceiling. Each is a REAL RBAC permission ({@see RbacProvisioner::PERMISSIONS}); the
     * mirror only lists one after verifying at runtime that NO registered tool carries it, so the
     * "denied" list can never be fabricated — it is derived from the real registry.
     */
    private const HUMAN_ONLY = ['note.sign', 'patient.edit', 'medication.prescribe', 'allergy.override'];

    /** The reserved demo/echo tool is always registered but is not a production agent tool. */
    private const HIDDEN_PREFIX = 'demo.';

    /**
     * The electric-fence invariants — CODE-ENFORCED, toggle-free. Keys only; the descriptor text is
     * i18n. Every one is a genuinely enforced invariant (cited in the AGENT.P3 gate report / the DIFF
     * doc); this card DISPLAYS them, there is no route or action here to disable any of them.
     *
     * The governance dashboard mirrors this SAME list (GOV.P1), so the two surfaces state one
     * set of invariants rather than two that could drift apart.
     *
     * @var list<string>
     */
    public const FENCE_INVARIANTS = [
        'ai_labelled',          // every interaction recorded to the append-only ai_interactions ledger
        'human_approves_send',  // agents draft (comms.draft_reply); a human sends — no send tool exists
        'clinical_reviewed',    // clinical tools capped at suggest; no diagnosis/triage/dosing
        'consent_scoped',       // BelongsToTenant fail-closed tenant scoping
        'immutable_ledger',     // append-only hash-chained audit_events (DB triggers) + ai_interactions
        'reground_at_approve',  // approve re-authorises the tool permission + re-executes from live state
    ];

    public function __construct(
        private readonly AutonomyPolicy $autonomy,
        private readonly AgentMetricsService $metrics,
        private readonly AuditService $auditService,
    ) {}

    public function index(Request $request, AgentResolver $resolver, ToolRegistry $tools): Response
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $agents = Agent::query()->orderBy('name')->get()
            ->map(fn (Agent $agent): array => $this->present($agent, $resolver, $tools))
            ->values()
            ->all();

        return Inertia::render('Governance/Agents', [
            'agents' => $agents,
            'levelOrder' => array_keys(AutonomyPolicy::LEVELS),
            'governanceUrl' => route('governance.dashboard'),
            // The fence-vault invariants (read-only display) + the Roles surface the permission
            // mirror links to (permissions are changed THERE, by changing the role — not here).
            'fenceInvariants' => self::FENCE_INVARIANTS,
            'rolesUrl' => route('admin.roles.index'),
            // The action-ledger tab (AGENT.P5) — a read-only VIEW of the append-only ai_interactions
            // ledger, tenant-scoped, newest first. Real rows only.
            'ledger' => $this->metrics->ledger(),
            // The always-on uncertainty escalation (the real clinician-attention hand-off — locked-on,
            // no disable path) + the confidence threshold, which is honestly DEFERRED (the runtime has
            // no confidence signal yet), rendered read-only "planned" rather than as a phantom control.
            'escalation' => [
                'alwaysOn' => true,
                'confidenceThresholdWired' => false,
            ],
            // The real agent KINDS the new-agent wizard may create (you cannot create an agent for a
            // capability that has no code-class agent behind it).
            'agentKinds' => $this->agentKinds(),
            'createUrl' => route('governance.agents.store'),
        ]);
    }

    public function store(Request $request, AgentResolver $resolver, ToolRegistry $tools, AgentConfigService $service): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $data = $request->validate([
            // The KIND must be a real canonical agent — you cannot create a non-existent capability.
            'kind' => ['required', 'string', 'in:'.implode(',', array_keys(AgentRegistry::AGENTS))],
            'name' => ['required', 'string', 'max:120'],
            // Any valid enum is accepted; it is CLAMPED to the new agent's ceiling below (capped from birth).
            'autonomy_level' => ['sometimes', 'string', 'in:'.implode(',', array_keys(AutonomyPolicy::LEVELS))],
        ]);

        $kind = $data['kind'];
        // The whitelist is the kind's real remit (registered, callable tools) — never a forged capability.
        $remit = array_values(array_filter(
            AgentRegistry::AGENTS[$kind]['tools'],
            function (string $key) use ($tools): bool {
                try {
                    return $this->autonomy->effectiveCeiling($tools->get($key)->definition()) !== AutonomyPolicy::OFF;
                } catch (AiCoreException) {
                    return false;
                }
            },
        ));

        $agent = (new Agent)->forceFill([
            'tenant_id' => $request->user()->tenant_id,
            'key' => $this->uniqueKey($data['name'], $kind),
            'kind' => $kind,
            'name' => $data['name'],
            'autonomy_level' => AutonomyPolicy::SUGGEST,
            'status' => Agent::STATUS_ACTIVE,
            'tool_keys' => $remit,
        ]);
        $agent->save();

        // Capped from birth: a requested level is clamped to the new agent's effective ceiling (the
        // resolver caps it at runtime too). It can never be created above any ceiling.
        if (array_key_exists('autonomy_level', $data)) {
            $service->configure($agent, ['autonomy_level' => $this->clampToCeiling($data['autonomy_level'], $resolver->agentCeiling($agent))]);
        }

        $this->audit($agent, 'agent.created');

        return redirect()->route('governance.agents.index')->with('status', 'created');
    }

    public function configure(Request $request, string $agent, AgentResolver $resolver, AgentConfigService $service, ToolRegistry $tools): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        // Resolve by string id (never route-model binding of a tenant-scoped model — the FIX.1
        // rule); the global tenant scope makes a cross-tenant/missing id a 404.
        $model = Agent::query()->where('id', $agent)->firstOrFail();

        $data = $request->validate([
            // Any valid enum is accepted; the CEILING clamp below is what enforces the cap, not this.
            'autonomy_level' => ['sometimes', 'string', 'in:'.implode(',', array_keys(AutonomyPolicy::LEVELS))],
            'status' => ['sometimes', 'string', 'in:'.Agent::STATUS_ACTIVE.','.Agent::STATUS_PAUSED],
            'tool_keys' => ['sometimes', 'array'],
            'tool_keys.*' => ['string'],
            // AGENT.P6 rate/timing limits — the service clamps to bounds (nullable = clear the limit).
            'max_drafts_per_hour' => ['sometimes', 'nullable', 'integer'],
            'quiet_hours_start' => ['sometimes', 'nullable', 'integer'],
            'quiet_hours_end' => ['sometimes', 'nullable', 'integer'],
        ]);

        $payload = [];

        // The limits pass straight to the service, which validates the bounds + clears on null. There
        // is deliberately NO key here to disable the uncertainty escalation — it is not suppressible.
        foreach (['max_drafts_per_hour', 'quiet_hours_start', 'quiet_hours_end'] as $limit) {
            if (array_key_exists($limit, $data)) {
                $payload[$limit] = $data[$limit];
            }
        }

        if (array_key_exists('autonomy_level', $data)) {
            // CLAMP-ON-WRITE: the stored level can never exceed the agent's effective ceiling. A
            // forged POST of a level above the ceiling is capped here; the resolver caps again at
            // call time (defense in depth). This is the SETTINGS.P2 clamp pattern, per agent.
            $payload['autonomy_level'] = $this->clampToCeiling($data['autonomy_level'], $resolver->agentCeiling($model));
        }

        if (array_key_exists('status', $data)) {
            $payload['status'] = $data['status'];
        }

        if (array_key_exists('tool_keys', $data)) {
            // WHITELIST CLAMP (AGENT.P4): the whitelist may only reference the agent's CANDIDATE
            // (enable-able) tools — its registered, callable remit. A forged enable of a locked /
            // out-of-remit / unregistered key is DROPPED here (never grants a capability). This only
            // narrows the callable SET; the resolver still caps each tool's autonomy at runtime (P1).
            $candidate = $this->candidateToolKeys($model, $resolver, $tools);
            $payload['tool_keys'] = array_values(array_intersect(array_values($data['tool_keys']), $candidate));
        }

        $service->configure($model, $payload);

        return redirect()->route('governance.agents.index')->with('status', 'saved');
    }

    /** MIN(requested, ceiling) by rank — the level never resolves above the ceiling. */
    private function clampToCeiling(string $requested, string $ceiling): string
    {
        $requestedRank = AutonomyPolicy::LEVELS[$requested] ?? AutonomyPolicy::LEVELS[AutonomyPolicy::OFF];
        $ceilingRank = AutonomyPolicy::LEVELS[$ceiling] ?? AutonomyPolicy::LEVELS[AutonomyPolicy::OFF];

        return min($requestedRank, $ceilingRank) === $requestedRank ? $requested : $ceiling;
    }

    /**
     * The real canonical agent KINDS the wizard may create — each maps to a code-class agent behind a
     * real capability. You cannot create an agent for a kind that does not exist.
     *
     * @return list<array{kind: string, name: string}>
     */
    private function agentKinds(): array
    {
        $out = [];
        foreach (AgentRegistry::AGENTS as $kind => $spec) {
            $out[] = ['kind' => $kind, 'name' => $spec['name']];
        }

        return $out;
    }

    /** A per-tenant unique key from the name (falls back to the kind); appends a numeric suffix if taken. */
    private function uniqueKey(string $name, string $kind): string
    {
        $base = Str::slug($name) ?: $kind;
        $key = $base;
        $n = 1;
        while (Agent::query()->where('key', $key)->exists()) {
            $key = $base.'-'.(++$n);
        }

        return $key;
    }

    /** Record an agent lifecycle event to the audit log (tenant-scoped, append-only). */
    private function audit(Agent $agent, string $action): void
    {
        $this->auditService->record([
            'action' => $action,
            'resource_type' => 'agent',
            'resource_id' => $agent->id,
            'context' => ['key' => $agent->key, 'kind' => $agent->kind()],
        ]);
    }

    /**
     * The agent's CANDIDATE (enable-able) tool keys — its canonical remit ({@see AgentRegistry}),
     * restricted to tools that are registered AND callable (effective ceiling above OFF). The
     * whitelist may only ever reference these: enabling anything else (out-of-remit, unregistered,
     * or a non-callable/locked tool) is refused. Whitelisting one of these still does NOT grant it
     * past its ceiling — the resolver caps each tool's autonomy at runtime (P1).
     *
     * @return list<string>
     */
    private function candidateToolKeys(Agent $agent, AgentResolver $resolver, ToolRegistry $tools): array
    {
        $remit = AgentRegistry::AGENTS[$agent->kind()]['tools'] ?? [];
        $out = [];

        foreach ($remit as $key) {
            try {
                $definition = $tools->get($key)->definition();
            } catch (AiCoreException) {
                continue; // an unregistered remit key is not enable-able
            }

            // A tool whose effective ceiling is OFF is non-callable — it stays LOCKED, never enable-able.
            if ($this->autonomy->effectiveCeiling($definition) === AutonomyPolicy::OFF) {
                continue;
            }

            $out[] = $key;
        }

        return $out;
    }

    /**
     * Present one agent for the list + detail shell: its status, configured level, the effective
     * ceiling, the selectable ladder rungs, the read-only tool context, and the permission-ceiling
     * MIRROR (AGENT.P3 — a reflection, not an editor). Editing the whitelist is P4.
     *
     * @return array<string, mixed>
     */
    private function present(Agent $agent, AgentResolver $resolver, ToolRegistry $tools): array
    {
        $ceiling = $resolver->agentCeiling($agent);
        $ceilingRank = AutonomyPolicy::LEVELS[$ceiling];

        return [
            'id' => $agent->id,
            'key' => $agent->key,
            'name' => $agent->name,
            'status' => $agent->status,
            'level' => $agent->autonomy_level,
            'ceiling' => $ceiling,
            'levels' => array_map(
                fn (string $level): array => [
                    'value' => $level,
                    'allowed' => AutonomyPolicy::LEVELS[$level] <= $ceilingRank,
                ],
                array_keys(AutonomyPolicy::LEVELS),
            ),
            'tools' => $this->tools($agent, $tools),
            'permissions' => $this->permissionMirror($agent, $tools),
            'whitelist' => $this->toolWhitelist($agent, $resolver, $tools),
            'metrics' => $this->metrics->hero($agent),
            // AGENT.P6 — the rate/timing limits the runtime reads, plus the escalation floor.
            'limits' => [
                'maxDraftsPerHour' => $agent->max_drafts_per_hour,
                'quietHoursStart' => $agent->quiet_hours_start,
                'quietHoursEnd' => $agent->quiet_hours_end,
            ],
            'configureUrl' => route('governance.agents.configure', ['agent' => $agent->id]),
        ];
    }

    /**
     * The EDITABLE tool whitelist (AGENT.P4) — "tools it may call · N of M enabled". The agent's
     * candidate remit tools are toggle-able (enabled = in the whitelist); every OTHER governed tool
     * is shown LOCKED (outside this agent's remit — it can never be enabled here). Toggling writes
     * `tool_keys` through {@see AgentConfigService} (clamped to the candidate set in configure());
     * it changes only WHICH tools the agent may call — never a tool's ceiling/permission (P1).
     *
     * @return array<string, mixed>
     */
    private function toolWhitelist(Agent $agent, AgentResolver $resolver, ToolRegistry $tools): array
    {
        $candidate = $this->candidateToolKeys($agent, $resolver, $tools);
        $enabled = array_values(array_intersect($agent->tool_keys ?? [], $candidate));

        $rows = [];

        foreach ($tools->all() as $key => $tool) {
            if (str_starts_with((string) $key, self::HIDDEN_PREFIX)) {
                continue; // the reserved demo/echo tool is not a production agent tool
            }

            $definition = $tool->definition();
            $inRemit = in_array($key, $candidate, true);

            $rows[] = [
                'key' => $definition->key,
                'name' => $definition->name,
                'category' => $definition->category,
                'permission' => $definition->permission,
                'permissionLabel' => RbacProvisioner::PERMISSIONS[$definition->permission] ?? $definition->permission,
                'ceiling' => $this->autonomy->effectiveCeiling($definition),
                'enabled' => in_array($key, $enabled, true),
                // Locked = not this agent's remit: it can never be enabled here (a forged enable is dropped).
                'locked' => ! $inRemit,
            ];
        }

        // Remit tools first (the toggle-able set), then the locked ones; stable name order within each.
        usort($rows, fn (array $a, array $b): int => [$a['locked'] ? 1 : 0, $a['name']] <=> [$b['locked'] ? 1 : 0, $b['name']]);

        return [
            'enabledCount' => count($enabled),
            'candidateCount' => count($candidate),
            'tools' => $rows,
        ];
    }

    /**
     * The per-agent PERMISSION-CEILING MIRROR (AGENT.P3) — a READ-ONLY reflection of the real RBAC +
     * tool permissions, never an editor. Two groups, both derived from live data:
     *
     *  - `exercised`: the distinct permissions this agent's whitelisted tools require. These are the
     *    EXACT permissions re-authorised against the operator's role at approve
     *    ({@see ApprovalQueue::approve} → `Gate::allows($tool->permission)`) — so the ceiling is
     *    role-derived: change the role (elsewhere) to change what may be approved.
     *  - `withheld`: sensitive HUMAN-ONLY permissions the agent can never exercise. Each is included
     *    ONLY after verifying no registered tool carries it — the "denied" list is derived from the
     *    real registry, never fabricated.
     *
     * There is NO write path here: this method only reads. Permissions change on the Roles surface.
     *
     * @return array{exercised: list<array<string, mixed>>, withheld: list<array<string, string>>}
     */
    private function permissionMirror(Agent $agent, ToolRegistry $tools): array
    {
        // Every permission any registered tool exercises — the withheld list must avoid all of these.
        $toolPermissions = [];
        foreach ($tools->all() as $tool) {
            $toolPermissions[$tool->definition()->permission] = true;
        }

        // exercised: group the agent's whitelisted tools by their required permission.
        $exercised = [];
        foreach ($agent->tool_keys ?? [] as $key) {
            try {
                $definition = $tools->get($key)->definition();
            } catch (AiCoreException) {
                continue;
            }

            $permission = $definition->permission;
            $exercised[$permission] ??= [
                'permission' => $permission,
                'label' => RbacProvisioner::PERMISSIONS[$permission] ?? $permission,
                'category' => $definition->category,
                'tools' => [],
            ];
            $exercised[$permission]['tools'][] = $definition->name;
        }

        // withheld: the human-only permissions — real RBAC permissions that NO tool exercises (so the
        // agent structurally cannot), and that this agent does not exercise either.
        $withheld = [];
        foreach (self::HUMAN_ONLY as $permission) {
            if (! array_key_exists($permission, RbacProvisioner::PERMISSIONS)) {
                continue; // only ever list a REAL permission
            }
            if (isset($toolPermissions[$permission]) || isset($exercised[$permission])) {
                continue; // if some tool exercises it, it is NOT human-only — never fake the denial
            }

            $withheld[] = [
                'permission' => $permission,
                'label' => RbacProvisioner::PERMISSIONS[$permission],
            ];
        }

        return ['exercised' => array_values($exercised), 'withheld' => $withheld];
    }

    /**
     * The real registered tools the agent whitelists, with each tool's own ceiling — read-only
     * context for the detail shell (an unregistered/forged key is dropped).
     *
     * @return list<array<string, string>>
     */
    private function tools(Agent $agent, ToolRegistry $tools): array
    {
        $out = [];

        foreach ($agent->tool_keys ?? [] as $key) {
            try {
                $definition = $tools->get($key)->definition();
            } catch (AiCoreException) {
                continue;
            }

            $out[] = ['key' => $definition->key, 'name' => $definition->name, 'category' => $definition->category];
        }

        return $out;
    }
}
