<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolDefinition;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\User;

/**
 * "Agents & automation" settings card (SETTINGS.P2) — a READ + WRITE-THROUGH-EXISTING-POLICY
 * window onto {@see AutonomyPolicy}. It lists the real registered governed tools with their
 * current autonomy level and lets a human LOWER it. It introduces NO new domain logic and NO
 * raw setting write:
 *
 *  - the tool set comes from {@see ToolRegistry::all()} (the real governed set — never a
 *    hardcoded list); the reserved `demo.*` echo tool is not a production agent and is skipped.
 *  - each control offers ONLY levels at or below the tool's effective ceiling
 *    ({@see AutonomyPolicy::effectiveCeiling()} = the same cap the runtime applies). Clinical
 *    tools cap at `suggest`, clinical/financial can never exceed `approve`; the higher levels
 *    render as a visible, LOCKED limit.
 *  - saving persists ONLY through {@see AutonomyPolicy::set()}, which CLAMPS any level above
 *    the ceiling — so even a forged request body cannot raise autonomy past the cap. This
 *    controller never writes `ai.autonomy.*` directly and never weakens the policy.
 *
 * Gated on `ai.manage` (managing governed AI); tenant-scoped; the change is audited
 * (`ai.autonomy_changed`) in the app layer.
 */
class AgentAutonomyController
{
    /** The reserved demo/echo tool is always registered but is not a production agent. */
    private const HIDDEN_PREFIX = 'demo.';

    public function index(Request $request, ToolRegistry $tools, AutonomyPolicy $policy): Response
    {
        Gate::authorize('ai.manage');
        abort_unless($request->user() instanceof User, 403);

        return Inertia::render('Admin/Agents', [
            'tools' => $this->present($tools, $policy),
            // The ordered level ladder + which ones each tool exposes are computed per tool
            // above; the labels are i18n on the client.
            'levelOrder' => array_keys(AutonomyPolicy::LEVELS),
            'updateUrl' => route('admin.agents.update'),
            'settingsUrl' => route('settings.index'),
        ]);
    }

    public function update(Request $request, ToolRegistry $tools, AutonomyPolicy $policy, AuditService $audit): RedirectResponse
    {
        Gate::authorize('ai.manage');
        abort_unless($request->user() instanceof User, 403);

        $data = $request->validate([
            'levels' => ['required', 'array'],
            // Any valid level is ACCEPTED here; AutonomyPolicy::set() is what clamps it to the
            // tool's ceiling. Validation guards the enum only — the fence is the clamp, not this.
            'levels.*' => ['required', 'string', 'in:'.implode(',', array_keys(AutonomyPolicy::LEVELS))],
        ]);

        $changes = [];

        foreach ($data['levels'] as $key => $requested) {
            if (str_starts_with((string) $key, self::HIDDEN_PREFIX)) {
                continue;
            }

            try {
                $definition = $tools->get((string) $key)->definition();
            } catch (AiCoreException) {
                continue; // unknown key — ignore rather than trust the client
            }

            $before = $policy->levelFor($definition);
            $policy->set($definition, $requested); // clamps to the ceiling — never raises past it
            $after = $policy->levelFor($definition);

            if ($before !== $after) {
                $changes[] = ['tool' => $definition->key, 'from' => $before, 'to' => $after];
            }
        }

        if ($changes !== []) {
            $audit->record([
                'action' => 'ai.autonomy_changed',
                'resource_type' => 'ai_tool',
                'context' => ['changes' => $changes],
            ]);
        }

        return redirect()->route('admin.agents.index')->with('status', 'saved');
    }

    /**
     * Present each registered governed tool with its current level and the levels the ceiling
     * permits. `allowed` marks the selectable levels; the rest render as the locked ceiling.
     *
     * @return list<array<string, mixed>>
     */
    private function present(ToolRegistry $tools, AutonomyPolicy $policy): array
    {
        $out = [];

        foreach ($tools->all() as $key => $tool) {
            if (str_starts_with((string) $key, self::HIDDEN_PREFIX)) {
                continue;
            }

            $definition = $tool->definition();
            $ceiling = $policy->effectiveCeiling($definition);
            $ceilingRank = AutonomyPolicy::LEVELS[$ceiling];

            $out[] = [
                'key' => $definition->key,
                'name' => $definition->name,
                'category' => $definition->category,
                'permission' => $definition->permission,
                'ceiling' => $ceiling,
                'level' => $policy->levelFor($definition),
                'levels' => array_map(
                    fn (string $level): array => [
                        'value' => $level,
                        'allowed' => AutonomyPolicy::LEVELS[$level] <= $ceilingRank,
                    ],
                    array_keys(AutonomyPolicy::LEVELS),
                ),
            ];
        }

        // Stable, human-scannable order: clinical first (the sharpest fence), then financial,
        // then operational; alphabetical within a category. Presentation only.
        $rank = [
            ToolDefinition::CATEGORY_CLINICAL => 0,
            ToolDefinition::CATEGORY_FINANCIAL => 1,
            ToolDefinition::CATEGORY_OPERATIONAL => 2,
        ];
        usort($out, function (array $a, array $b) use ($rank): int {
            return [$rank[$a['category']] ?? 9, $a['name']] <=> [$rank[$b['category']] ?? 9, $b['name']];
        });

        return $out;
    }
}
