<?php

namespace App\Services;

use Modules\AiCore\Models\Agent;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Audit\Services\AuditService;

/**
 * The sanctioned write path for configuring a governed {@see Agent} (AGENT.P1). App-layer because
 * the change is audited and AiCore may not depend on Audit. It CLAMPS ON WRITE — a valid autonomy
 * enum, a valid status, and a whitelist restricted to REAL registered tool keys — and audits the
 * change (`agent.configured`).
 *
 * Clamp-on-write is only the first line: an agent's configured level is a single value across tools
 * with different ceilings, so the AUTHORITATIVE cap is {@see AgentResolver} at call time
 * (MIN(configured, tool ceiling, role ceiling)). Storing 'auto' here never lets the agent exceed a
 * tool/role ceiling — the resolver mins it. This service only NARROWS; it never widens authority.
 *
 * RBAC (admin.manage) is applied by the P2 configuration controller that will call this service; no
 * route exists yet (AGENT.P1 is the entity + resolver, no UI).
 */
class AgentConfigService
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array{autonomy_level?: string, status?: string, tool_keys?: list<string>}  $data
     */
    public function configure(Agent $agent, array $data): Agent
    {
        $changes = [];

        if (array_key_exists('autonomy_level', $data) && array_key_exists($data['autonomy_level'], AutonomyPolicy::LEVELS)) {
            if ($agent->autonomy_level !== $data['autonomy_level']) {
                $changes['autonomy_level'] = [$agent->autonomy_level, $data['autonomy_level']];
                $agent->autonomy_level = $data['autonomy_level'];
            }
        }

        if (array_key_exists('status', $data) && in_array($data['status'], [Agent::STATUS_ACTIVE, Agent::STATUS_PAUSED], true)) {
            if ($agent->status !== $data['status']) {
                $changes['status'] = [$agent->status, $data['status']];
                $agent->status = $data['status'];
            }
        }

        if (array_key_exists('tool_keys', $data)) {
            // Whitelist stores only REAL registered tool keys (a forged/unknown key is dropped). An
            // out-of-ceiling REAL tool may be stored — the resolver still caps it (narrows, never widens).
            $registered = array_keys($this->tools->all());
            $clean = array_values(array_filter(array_unique($data['tool_keys']), fn (string $k): bool => in_array($k, $registered, true)));
            if ($agent->tool_keys !== $clean) {
                $changes['tool_keys'] = [$agent->tool_keys, $clean];
                $agent->tool_keys = $clean;
            }
        }

        if ($changes !== []) {
            $agent->save();
            $this->audit->record([
                'action' => 'agent.configured',
                'resource_type' => 'agent',
                'resource_id' => $agent->id,
                'context' => ['key' => $agent->key, 'changes' => $changes],
            ]);
        }

        return $agent;
    }
}
