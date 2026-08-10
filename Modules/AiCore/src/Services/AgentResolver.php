<?php

namespace Modules\AiCore\Services;

use Modules\AiCore\Models\Agent;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;

/**
 * The capped effective-level resolver (AGENT.P1) — the safety foundation that makes an {@see Agent}
 * a GOVERNED CONTAINER, never a new source of authority.
 *
 * The effective autonomy for an (agent, tool) call is ALWAYS the MINIMUM of three ceilings:
 *   1. the agent's CONFIGURED level,
 *   2. the tool's ceiling ({@see AutonomyPolicy::effectiveCeiling} — per-tool ceiling + the
 *      clinical/financial hard-cap), and
 *   3. the role RBAC ceiling (supplied by the caller; the runtime passes OFF when the acting user
 *      does not hold the tool's required permission).
 *
 * The agent config can only NARROW: it can never raise autonomy past the tool ceiling or the role
 * ceiling. This clamp runs at CALL TIME (defense in depth) regardless of what is stored — so even a
 * forged configured level or a whitelist entry for an out-of-ceiling tool cannot widen what the
 * agent may do. A non-whitelisted tool, or a paused agent, resolves to OFF (not callable).
 */
class AgentResolver
{
    public function __construct(
        private readonly AutonomyPolicy $autonomy,
        private readonly TenantContext $context,
    ) {}

    /**
     * The effective (capped) autonomy level for this agent calling this tool. Returns OFF when the
     * agent is paused or the tool is not whitelisted; otherwise MIN(configured, tool ceiling,
     * role ceiling). Clamps at runtime — the stored config can only lower, never widen.
     */
    public function effectiveLevel(Agent $agent, ToolDefinition $definition, string $roleCeiling = AutonomyPolicy::AUTO): string
    {
        // Narrowing gates: a paused agent or a non-whitelisted tool is simply not callable.
        if (! $agent->mayCall($definition->key)) {
            return AutonomyPolicy::OFF;
        }

        $terms = [
            $this->rank($agent->autonomy_level),                          // configured (agent's choice)
            $this->rank($this->autonomy->effectiveCeiling($definition)),  // tool ceiling (AutonomyPolicy cap)
            $this->rank($roleCeiling),                                    // role RBAC ceiling
        ];

        return $this->level(min($terms));
    }

    /** Whether the agent may call the tool at all (status active + whitelisted). */
    public function mayCall(Agent $agent, string $toolKey): bool
    {
        return $agent->mayCall($toolKey);
    }

    /**
     * Idempotently ensure a tenant has its canonical governed agents (AGENT.P1 seed). Safe to call
     * repeatedly; creates only the missing ones. Runs in system mode with an explicit tenant_id
     * (tenant creation happens outside any ambient tenant context — the RbacProvisioner pattern).
     */
    public function ensureForTenant(Tenant $tenant): void
    {
        $this->context->system(function () use ($tenant): void {
            foreach (AgentRegistry::AGENTS as $key => $spec) {
                $exists = Agent::query()->where('tenant_id', $tenant->getKey())->where('key', $key)->exists();
                if ($exists) {
                    continue;
                }

                (new Agent)->forceFill([
                    'tenant_id' => $tenant->getKey(),
                    'key' => $key,
                    'name' => $spec['name'],
                    'autonomy_level' => AutonomyPolicy::SUGGEST,
                    'status' => Agent::STATUS_ACTIVE,
                    'tool_keys' => $spec['tools'],
                ])->save();
            }
        });
    }

    private function rank(string $level): int
    {
        // Unknown levels normalize to OFF (fail-closed — never widen on a bad value).
        return AutonomyPolicy::LEVELS[$level] ?? AutonomyPolicy::LEVELS[AutonomyPolicy::OFF];
    }

    private function level(int $rank): string
    {
        foreach (AutonomyPolicy::LEVELS as $level => $r) {
            if ($r === $rank) {
                return $level;
            }
        }

        return AutonomyPolicy::OFF;
    }
}
