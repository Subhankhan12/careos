<?php

/*
| CROSS-CUTTING AGENT EVALS — invariants that hold for EVERY agent tool.
|
| 1. Autonomy caps: every registered agent tool has the category + ceiling it was
|    built with; asking for `auto` degrades to the effective ceiling; a clinical or
|    financial tool can NEVER exceed `approve`.
| 2. Every governed path writes an append-only ai_interactions row and the audit
|    chain verifies.
| 3. The kill switch disables any feature (no agent action, ledger + audit still written).
| 4. Over-budget degrades to manual without leaving the process.
| 5. Agent actions and interactions are tenant-isolated.
*/

require_once __DIR__.'/Support/EvalHarness.php';

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\AiInteraction;
use Modules\AiCore\Services\AgentRuntime;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\KillSwitch;
use Modules\AiCore\Services\ToolDefinition;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Services\SettingsService;

uses(RefreshDatabase::class);

/**
 * The full agent tool surface with the {category, ceiling} each was built with.
 *
 * @return array<string, array{category: string, ceiling: string}>
 */
function xcToolMatrix(): array
{
    return [
        'scheduler.fill_from_waitlist' => ['category' => ToolDefinition::CATEGORY_OPERATIONAL, 'ceiling' => AutonomyPolicy::APPROVE],
        'scheduler.suggest_slots' => ['category' => ToolDefinition::CATEGORY_OPERATIONAL, 'ceiling' => AutonomyPolicy::APPROVE],
        'clinical.summarize_since_last_visit' => ['category' => ToolDefinition::CATEGORY_CLINICAL, 'ceiling' => AutonomyPolicy::SUGGEST],
        'clinical.draft_recall_message' => ['category' => ToolDefinition::CATEGORY_CLINICAL, 'ceiling' => AutonomyPolicy::SUGGEST],
        'nursing.propose_assignments' => ['category' => ToolDefinition::CATEGORY_OPERATIONAL, 'ceiling' => AutonomyPolicy::APPROVE],
        'nursing.replan_day' => ['category' => ToolDefinition::CATEGORY_OPERATIONAL, 'ceiling' => AutonomyPolicy::APPROVE],
        'billing.suggest_charge_codes' => ['category' => ToolDefinition::CATEGORY_FINANCIAL, 'ceiling' => AutonomyPolicy::APPROVE],
        'billing.preflight_invoice' => ['category' => ToolDefinition::CATEGORY_FINANCIAL, 'ceiling' => AutonomyPolicy::APPROVE],
        'comms.draft_reply' => ['category' => ToolDefinition::CATEGORY_OPERATIONAL, 'ceiling' => AutonomyPolicy::SUGGEST],
        'comms.classify_document' => ['category' => ToolDefinition::CATEGORY_OPERATIONAL, 'ceiling' => AutonomyPolicy::SUGGEST],
    ];
}

test('EVAL cross-cutting: every agent tool holds its category + ceiling and auto degrades to the cap', function () {
    evNoNetwork();
    evTenant('alpha');

    $registry = app(ToolRegistry::class);
    $policy = app(AutonomyPolicy::class);

    foreach (xcToolMatrix() as $key => $expected) {
        $definition = $registry->get($key)->definition();

        // Built-in category + ceiling are as declared.
        expect($definition->category)->toBe($expected['category'])
            ->and($definition->autonomyCeiling)->toBe($expected['ceiling']);

        // Asking for the maximum autonomy degrades to the effective ceiling.
        $policy->set($definition, AutonomyPolicy::AUTO);
        expect($policy->levelFor($definition))->toBe($expected['ceiling']);

        // A clinical or financial tool can NEVER be effectively above approve.
        if ($definition->isClinicalOrFinancial()) {
            expect(AutonomyPolicy::LEVELS[$policy->levelFor($definition)])
                ->toBeLessThanOrEqual(AutonomyPolicy::LEVELS[AutonomyPolicy::APPROVE]);
        }
    }
});

test('EVAL cross-cutting: a governed path writes an append-only ledger row and the audit chain verifies', function () {
    evNoNetwork();
    $tenant = evTenant('alpha');
    $user = evUser($tenant, 'org_admin');

    $result = app(AgentRuntime::class)->runTool('demo.echo', ['message' => 'draft'], $user);

    expect($result['status'])->toBe('pending')
        ->and($result['label'])->toBe('AI draft - requires human review')
        ->and(AiInteraction::query()->where('outcome', 'proposed')->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('EVAL cross-cutting: the kill switch disables a feature and still writes ledger + audit', function () {
    evNoNetwork();
    $tenant = evTenant('alpha');
    $user = evUser($tenant, 'org_admin');
    app(KillSwitch::class)->disable('demo.echo');

    $result = app(AgentRuntime::class)->runTool('demo.echo', ['message' => 'hello'], $user);

    expect($result['status'])->toBe('disabled')
        ->and(AgentAction::query()->count())->toBe(0)
        ->and(AiInteraction::query()->where('outcome', 'disabled')->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('EVAL cross-cutting: over-budget degrades to manual without leaving the process', function () {
    evNoNetwork();
    $tenant = evTenant('alpha');
    $user = evUser($tenant, 'org_admin');
    app(SettingsService::class)->set('ai.monthly_budget_minor', 0, 'int');

    $result = app(AgentRuntime::class)->runTool('demo.echo', ['message' => 'blocked'], $user);

    expect($result['status'])->toBe('budget_blocked')
        ->and(AgentAction::query()->count())->toBe(0)
        ->and(AiInteraction::query()->where('outcome', 'budget_blocked')->count())->toBe(1);

    Http::assertNothingSent();
});

test('EVAL cross-cutting: agent actions and interactions are tenant-isolated', function () {
    evNoNetwork();
    $alpha = evTenant('alpha');
    $alphaUser = evUser($alpha, 'org_admin');
    app(AgentRuntime::class)->runTool('demo.echo', ['message' => 'alpha'], $alphaUser);

    $beta = evTenant('beta');
    $betaUser = evUser($beta, 'org_admin');
    app(AgentRuntime::class)->runTool('demo.echo', ['message' => 'beta'], $betaUser);

    // In beta's context only beta's rows are visible.
    expect(AgentAction::query()->pluck('proposed_by')->all())->toBe([(string) $betaUser->id]);

    // In alpha's context only alpha's rows are visible.
    evCtx()->set($alpha);
    expect(AgentAction::query()->pluck('proposed_by')->all())->toBe([(string) $alphaUser->id]);
});
