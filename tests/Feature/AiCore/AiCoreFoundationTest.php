<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\AiInteraction;
use Modules\AiCore\Services\AgentRuntime;
use Modules\AiCore\Services\AiInteractionRecorder;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\BudgetGate;
use Modules\AiCore\Services\CircuitBreaker;
use Modules\AiCore\Services\KillSwitch;
use Modules\AiCore\Services\LlmManager;
use Modules\AiCore\Services\PromptRegistry;
use Modules\AiCore\Services\ToolDefinition;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function aiTenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function aiCtx(): TenantContext
{
    return app(TenantContext::class);
}

function aiManager(Tenant $tenant): User
{
    aiCtx()->set($tenant);

    $user = User::factory()->forTenant($tenant)->create();
    $role = Role::where('key', 'org_admin')->firstOrFail();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id]);

    return $user;
}

test('ai_interactions is append-only and fail closed', function () {
    $tenant = aiTenant('alpha');
    aiCtx()->set($tenant);

    $interaction = app(AiInteractionRecorder::class)->record(
        'demo.echo',
        'demo-agent',
        'internal',
        'tool-runtime',
        '1',
        app(PromptRegistry::class)->get('demo.echo')->hash(),
        'completed',
    );

    expect(fn () => DB::table('ai_interactions')->where('id', $interaction->id)->update(['outcome' => 'tampered']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('ai_interactions')->where('id', $interaction->id)->delete())
        ->toThrow(QueryException::class);

    aiCtx()->forget();

    expect(fn () => AiInteraction::query()->get())->toThrow(TenantContextMissingException::class);
});

test('llm gateway uses mocked HTTP and records a completed ledger row', function () {
    config()->set('aicore.provider', 'anthropic');
    config()->set('aicore.providers.anthropic.api_key', 'test-key-not-logged');
    config()->set('aicore.providers.anthropic.endpoint', 'https://anthropic.test/v1/messages');

    $tenant = aiTenant('alpha');
    aiCtx()->set($tenant);

    Http::fake([
        'anthropic.test/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Echo draft']],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 4],
        ]),
    ]);

    $response = app(LlmManager::class)->complete(
        'demo.echo',
        'demo-agent',
        app(PromptRegistry::class)->get('demo.echo'),
        'Say hello',
    );

    expect($response->text)->toBe('Echo draft')
        ->and(AiInteraction::where('outcome', 'completed')->count())->toBe(1)
        ->and(AiInteraction::firstOrFail()->prompt_hash)->toHaveLength(64);
});

test('budget gate hard stops over budget without sending HTTP and records manual fallback', function () {
    config()->set('aicore.providers.anthropic.endpoint', 'https://anthropic.test/v1/messages');
    $tenant = aiTenant('alpha');
    aiCtx()->set($tenant);
    app(SettingsService::class)->set('ai.monthly_budget_minor', 0, 'int');
    Http::fake();

    expect(fn () => app(LlmManager::class)->complete(
        'demo.echo',
        'demo-agent',
        app(PromptRegistry::class)->get('demo.echo'),
        'This should not leave the process',
    ))->toThrow(AiCoreException::class);

    Http::assertNothingSent();

    expect(app(BudgetGate::class)->spentThisMonth())->toBe(0)
        ->and(AiInteraction::where('outcome', 'budget_blocked')->count())->toBe(1);
});

test('circuit breaker opens on repeated provider failures and degrades to manual', function () {
    config()->set('aicore.circuit_failure_threshold', 2);
    config()->set('aicore.circuit_open_seconds', 60);
    config()->set('aicore.providers.anthropic.endpoint', 'https://anthropic.test/v1/messages');

    $tenant = aiTenant('alpha');
    aiCtx()->set($tenant);

    Http::fake(['anthropic.test/*' => Http::response(['error' => 'down'], 500)]);
    $prompt = app(PromptRegistry::class)->get('demo.echo');

    expect(fn () => app(LlmManager::class)->complete('demo.echo', 'demo-agent', $prompt, 'one'))
        ->toThrow(AiCoreException::class)
        ->and(fn () => app(LlmManager::class)->complete('demo.echo', 'demo-agent', $prompt, 'two'))
        ->toThrow(AiCoreException::class)
        ->and(fn () => app(CircuitBreaker::class)->assertClosed('anthropic', 'demo.echo'))
        ->toThrow(AiCoreException::class);

    expect(fn () => app(LlmManager::class)->complete('demo.echo', 'demo-agent', $prompt, 'three'))
        ->toThrow(AiCoreException::class);

    expect(AiInteraction::where('outcome', 'failed')->count())->toBe(2)
        ->and(AiInteraction::where('outcome', 'circuit_open')->count())->toBe(1);
});

test('autonomy defaults to suggest and clinical or financial tools cannot exceed approve', function () {
    $tenant = aiTenant('alpha');
    aiCtx()->set($tenant);

    $policy = app(AutonomyPolicy::class);
    $operational = new ToolDefinition('ops.noop', 'Ops no-op', ToolDefinition::CATEGORY_OPERATIONAL, 'ai.manage', []);
    $clinical = new ToolDefinition('clinical.noop', 'Clinical no-op', ToolDefinition::CATEGORY_CLINICAL, 'ai.manage', []);
    $financial = new ToolDefinition('financial.noop', 'Financial no-op', ToolDefinition::CATEGORY_FINANCIAL, 'ai.manage', []);

    expect($policy->levelFor($operational))->toBe(AutonomyPolicy::SUGGEST);

    $policy->set($clinical, AutonomyPolicy::AUTO);
    $policy->set($financial, AutonomyPolicy::AUTO);
    $policy->set($operational, AutonomyPolicy::AUTO);

    expect($policy->levelFor($clinical))->toBe(AutonomyPolicy::APPROVE)
        ->and($policy->levelFor($financial))->toBe(AutonomyPolicy::APPROVE)
        ->and($policy->levelFor($operational))->toBe(AutonomyPolicy::AUTO);
});

test('approval queue propose approve edit and reject flow writes ledger and audit chain', function () {
    $tenant = aiTenant('alpha');
    $user = aiManager($tenant);

    $runtime = app(AgentRuntime::class);
    $result = $runtime->runTool('demo.echo', ['message' => 'draft'], $user);

    /** @var AgentAction $action */
    $action = $result['action'];

    expect($result['status'])->toBe('pending')
        ->and($result['label'])->toBe('AI draft - requires human review')
        ->and($action->status)->toBe(AgentAction::STATUS_PENDING);

    $approved = app(ApprovalQueue::class)->approve($action, $user, ['message' => 'edited']);

    $second = app(ApprovalQueue::class)->propose(
        'demo.echo',
        ['message' => 'reject me'],
        $user,
        'demo.echo',
        'demo-agent',
        'Exercise rejection',
        AutonomyPolicy::SUGGEST,
    );
    $rejected = app(ApprovalQueue::class)->reject($second, $user, 'Not useful');

    expect($approved->status)->toBe(AgentAction::STATUS_EXECUTED)
        ->and($approved->result['message'])->toBe('edited')
        ->and($rejected->status)->toBe(AgentAction::STATUS_REJECTED)
        ->and($rejected->rejection_reason)->toBe('Not useful')
        ->and(AiInteraction::whereIn('outcome', ['proposed', 'approved', 'executed', 'rejected'])->count())->toBe(5)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('kill switch disables a feature and still writes ledger and audit', function () {
    $tenant = aiTenant('alpha');
    $user = aiManager($tenant);
    app(KillSwitch::class)->disable('demo.echo');

    $result = app(AgentRuntime::class)->runTool('demo.echo', ['message' => 'hello'], $user);

    expect($result['status'])->toBe('disabled')
        ->and(AgentAction::count())->toBe(0)
        ->and(AiInteraction::where('outcome', 'disabled')->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('agent actions and interactions are tenant isolated', function () {
    $alpha = aiTenant('alpha');
    $beta = aiTenant('beta');

    $alphaUser = aiManager($alpha);
    app(AgentRuntime::class)->runTool('demo.echo', ['message' => 'alpha'], $alphaUser);

    $betaUser = aiManager($beta);
    app(AgentRuntime::class)->runTool('demo.echo', ['message' => 'beta'], $betaUser);

    expect(AgentAction::pluck('proposed_by')->all())->toBe([(string) $betaUser->id])
        ->and(AiInteraction::pluck('agent')->all())->toBe(['demo-agent']);

    aiCtx()->set($alpha);

    expect(AgentAction::pluck('proposed_by')->all())->toBe([(string) $alphaUser->id])
        ->and(AiInteraction::pluck('agent')->all())->toBe(['demo-agent']);
});
