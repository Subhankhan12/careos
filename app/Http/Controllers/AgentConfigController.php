<?php

namespace App\Http\Controllers;

use App\Services\AgentConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Models\Agent;
use Modules\AiCore\Services\AgentResolver;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolRegistry;
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
 * code-enforced invariants, no disable path here). This gate builds the list + shell + ladder +
 * the two read-only panels — no whitelist edit (P4), no metrics/ledger (P5), no limits (P6).
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

    /**
     * The electric-fence invariants — CODE-ENFORCED, toggle-free. Keys only; the descriptor text is
     * i18n. Every one is a genuinely enforced invariant (cited in the AGENT.P3 gate report / the DIFF
     * doc); this card DISPLAYS them, there is no route or action here to disable any of them.
     */
    private const FENCE_INVARIANTS = [
        'ai_labelled',          // every interaction recorded to the append-only ai_interactions ledger
        'human_approves_send',  // agents draft (comms.draft_reply); a human sends — no send tool exists
        'clinical_reviewed',    // clinical tools capped at suggest; no diagnosis/triage/dosing
        'consent_scoped',       // BelongsToTenant fail-closed tenant scoping
        'immutable_ledger',     // append-only hash-chained audit_events (DB triggers) + ai_interactions
        'reground_at_approve',  // approve re-authorises the tool permission + re-executes from live state
    ];

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
        ]);
    }

    public function configure(Request $request, string $agent, AgentResolver $resolver, AgentConfigService $service): RedirectResponse
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
        ]);

        $payload = [];

        if (array_key_exists('autonomy_level', $data)) {
            // CLAMP-ON-WRITE: the stored level can never exceed the agent's effective ceiling. A
            // forged POST of a level above the ceiling is capped here; the resolver caps again at
            // call time (defense in depth). This is the SETTINGS.P2 clamp pattern, per agent.
            $payload['autonomy_level'] = $this->clampToCeiling($data['autonomy_level'], $resolver->agentCeiling($model));
        }

        if (array_key_exists('status', $data)) {
            $payload['status'] = $data['status'];
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
            'configureUrl' => route('governance.agents.configure', ['agent' => $agent->id]),
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
