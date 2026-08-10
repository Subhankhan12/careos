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
 * Gated on `admin.manage`; tenant-scoped; the change is audited (`agent.configured`, P1). This gate
 * builds the list + shell + ladder ONLY — no whitelist edit (P4), no metrics/ledger (P5), no
 * limits (P6).
 */
class AgentConfigController
{
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
     * ceiling, and which ladder rungs are selectable. The tool list is read-only context (the real
     * governed tools the agent touches — the flow the pipeline visual describes); editing it is P4.
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
            'configureUrl' => route('governance.agents.configure', ['agent' => $agent->id]),
        ];
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
