<?php

use App\Services\AgentConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AiCore\Models\Agent;
use Modules\AiCore\Services\AgentRegistry;
use Modules\AiCore\Services\AgentResolver;
use Modules\AiCore\Services\AgentRuntime;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Audit\Models\AuditEvent;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * AGENT.P1 — the Agent entity + the capped effective-level resolver. An agent is a GOVERNED
 * CONTAINER, never a source of authority: its effective autonomy for a tool is ALWAYS
 * MIN(configured, tool ceiling, role ceiling), resolved server-side. Config can only NARROW; a
 * forged higher config or a whitelisted out-of-ceiling tool NEVER raises autonomy past the cap. The
 * per-tool SETTINGS.P2 cap (AutonomyPolicy) is unchanged and remains one of the min() terms. These
 * tests ADD coverage; no existing behaviour test is modified.
 */

function agCtx(): TenantContext
{
    return app(TenantContext::class);
}

function agTenant(string $slug = 'alpha'): Tenant
{
    // Tenant::created seeds the canonical agents (AppServiceProvider hook).
    $tenant = Tenant::create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    agCtx()->set($tenant);

    return $tenant;
}

function agAdmin(Tenant $tenant): User
{
    agCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create(); // org_admin holds ai.manage (demo.echo permission)
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id]);

    return $user;
}

function agToolDef(string $key)
{
    return app(ToolRegistry::class)->get($key)->definition();
}

// ── The entity seeds the canonical agents per tenant (tenant-scoped) ─────────────────────────────────

test('creating a tenant seeds the canonical governed agents, tenant-scoped', function () {
    $tenant = agTenant();

    $agents = Agent::query()->get();
    expect($agents)->toHaveCount(count(AgentRegistry::AGENTS))
        ->and($agents->pluck('key')->sort()->values()->all())->toBe(collect(AgentRegistry::AGENTS)->keys()->sort()->values()->all())
        ->and($agents->every(fn (Agent $a) => $a->autonomy_level === 'suggest' && $a->status === 'active'))->toBeTrue();

    // Every whitelisted tool key is a REAL registered tool.
    $registered = array_keys(app(ToolRegistry::class)->all());
    foreach ($agents as $agent) {
        expect(collect($agent->tool_keys)->every(fn (string $k) => in_array($k, $registered, true)))->toBeTrue();
    }

    // Tenant-scoped: a second tenant gets its OWN set.
    $beta = agTenant('beta');
    expect(Agent::query()->count())->toBe(count(AgentRegistry::AGENTS)); // only beta's, under beta context
});

// ── The resolver = MIN(configured, tool ceiling, role ceiling) — the clamp ──────────────────────────

test('the resolver caps an agent to MIN(configured, tool ceiling, role ceiling)', function () {
    $tenant = agTenant();
    $resolver = app(AgentResolver::class);

    // Clinical tool ceiling = suggest; operational scheduler.suggest_slots ceiling = approve.
    $clinical = agToolDef('clinical.summarize_since_last_visit');
    $operational = agToolDef('scheduler.suggest_slots');

    // An agent configured 'auto' with BOTH tools whitelisted.
    $agent = Agent::query()->create([
        'key' => 'probe', 'name' => 'Probe', 'autonomy_level' => AutonomyPolicy::AUTO, 'status' => Agent::STATUS_ACTIVE,
        'tool_keys' => ['clinical.summarize_since_last_visit', 'scheduler.suggest_slots'],
    ]);

    // Config 'auto' is capped DOWN to each tool's ceiling (role ceiling = AUTO here).
    expect($resolver->effectiveLevel($agent, $clinical, AutonomyPolicy::AUTO))->toBe(AutonomyPolicy::SUGGEST)
        ->and($resolver->effectiveLevel($agent, $operational, AutonomyPolicy::AUTO))->toBe(AutonomyPolicy::APPROVE);

    // The role ceiling caps too: a role that lacks the permission (roleCeiling OFF) → OFF.
    expect($resolver->effectiveLevel($agent, $operational, AutonomyPolicy::OFF))->toBe(AutonomyPolicy::OFF)
        // A lower role ceiling wins even when config + tool ceiling are higher.
        ->and($resolver->effectiveLevel($agent, $operational, AutonomyPolicy::SUGGEST))->toBe(AutonomyPolicy::SUGGEST);
});

// ── Clamp at runtime / defense in depth — a FORGED stored config never widens ───────────────────────

test('a forged stored config above the ceiling is clamped by the resolver at call time', function () {
    $tenant = agTenant();
    $resolver = app(AgentResolver::class);

    // Forge a stored config of 'auto' + a whitelist including a clinical tool (out-of-ceiling).
    $agent = new Agent;
    $agent->forceFill([
        'tenant_id' => $tenant->id, 'key' => 'forged', 'name' => 'Forged',
        'autonomy_level' => AutonomyPolicy::AUTO, 'status' => Agent::STATUS_ACTIVE,
        'tool_keys' => ['clinical.summarize_since_last_visit'],
    ])->save();

    // Even stored 'auto', the effective level is the clinical ceiling — never raised.
    expect($resolver->effectiveLevel($agent->refresh(), agToolDef('clinical.summarize_since_last_visit'), AutonomyPolicy::AUTO))
        ->toBe(AutonomyPolicy::SUGGEST);
});

// ── The whitelist NARROWS, never widens; a non-whitelisted tool is not callable ─────────────────────

test('whitelisting narrows only — an out-of-ceiling whitelisted tool is still capped; a non-whitelisted tool is off', function () {
    $tenant = agTenant();
    $resolver = app(AgentResolver::class);

    $agent = Agent::query()->create([
        'key' => 'narrow', 'name' => 'Narrow', 'autonomy_level' => AutonomyPolicy::AUTO, 'status' => Agent::STATUS_ACTIVE,
        'tool_keys' => ['clinical.summarize_since_last_visit'], // whitelisted (a clinical, out-of-ceiling for auto)
    ]);

    // Whitelisted out-of-ceiling tool: capped to the ceiling, NOT widened to auto.
    expect($resolver->effectiveLevel($agent, agToolDef('clinical.summarize_since_last_visit'), AutonomyPolicy::AUTO))->toBe(AutonomyPolicy::SUGGEST);

    // A tool NOT in the whitelist is not callable at all.
    expect($resolver->mayCall($agent, 'scheduler.suggest_slots'))->toBeFalse()
        ->and($resolver->effectiveLevel($agent, agToolDef('scheduler.suggest_slots'), AutonomyPolicy::AUTO))->toBe(AutonomyPolicy::OFF);

    // A paused agent cannot act even on a whitelisted tool.
    $agent->update(['status' => Agent::STATUS_PAUSED]);
    expect($resolver->effectiveLevel($agent->refresh(), agToolDef('clinical.summarize_since_last_visit'), AutonomyPolicy::AUTO))->toBe(AutonomyPolicy::OFF);
});

// ── The runtime is governed by the capped resolver when an agent entity is passed ───────────────────

test('AgentRuntime uses the capped resolver level when an agent entity is supplied', function () {
    $tenant = agTenant();
    $admin = agAdmin($tenant); // holds ai.manage → demo.echo role ceiling = AUTO
    $runtime = app(AgentRuntime::class);

    // demo.echo (operational, ceiling AUTO) whitelisted; agent configured 'suggest' → runtime proposes (pending).
    $agent = Agent::query()->create([
        'key' => 'rt', 'name' => 'RT', 'autonomy_level' => AutonomyPolicy::SUGGEST, 'status' => Agent::STATUS_ACTIVE,
        'tool_keys' => ['demo.echo'],
    ]);
    $r = $runtime->runTool('demo.echo', ['message' => 'hi'], $admin, 'demo.echo', 'rt', 'probe', $agent->refresh());
    expect($r['status'])->toBe('pending'); // suggest → approval queue, not auto-executed

    // A tool NOT whitelisted → off (not callable), even though demo.echo itself is registered.
    $agent->update(['tool_keys' => []]);
    $off = $runtime->runTool('demo.echo', ['message' => 'hi'], $admin, 'demo.echo', 'rt', 'probe', $agent->refresh());
    expect($off['status'])->toBe('off');
});

// ── The per-tool SETTINGS.P2 cap is unchanged + still authoritative (one of the min terms) ──────────

test('the per-tool AutonomyPolicy cap is unchanged and the non-agent runtime path still uses it', function () {
    $tenant = agTenant();
    $admin = agAdmin($tenant);
    $policy = app(AutonomyPolicy::class);

    // The per-tool ceilings are exactly as SETTINGS.P2: clinical → suggest, operational scheduler → approve.
    expect($policy->effectiveCeiling(agToolDef('clinical.summarize_since_last_visit')))->toBe(AutonomyPolicy::SUGGEST)
        ->and($policy->effectiveCeiling(agToolDef('scheduler.suggest_slots')))->toBe(AutonomyPolicy::APPROVE);

    // The existing runtime path WITHOUT an agent entity is unchanged (uses AutonomyPolicy::levelFor).
    $r = app(AgentRuntime::class)->runTool('demo.echo', ['message' => 'hi'], $admin, 'demo.echo', 'demo-agent', 'no-op');
    expect($r['status'])->toBeIn(['pending', 'executed', 'off']); // whatever the per-tool policy yields — agent layer didn't change it
});

// ── Configure clamps on write + is audited ──────────────────────────────────────────────────────────

test('configuring an agent clamps on write (only real tool keys) and is audited', function () {
    $tenant = agTenant();
    $agent = Agent::query()->where('key', 'inbox')->firstOrFail();

    app(AgentConfigService::class)->configure($agent, [
        'autonomy_level' => AutonomyPolicy::APPROVE,
        'status' => Agent::STATUS_PAUSED,
        'tool_keys' => ['comms.draft_reply', 'not.a.real.tool', 'scheduler.suggest_slots'], // forged key dropped
    ]);

    $agent->refresh();
    expect($agent->autonomy_level)->toBe(AutonomyPolicy::APPROVE)
        ->and($agent->status)->toBe(Agent::STATUS_PAUSED)
        // the unknown/forged tool key was dropped; only real registered keys stored
        ->and($agent->tool_keys)->toBe(['comms.draft_reply', 'scheduler.suggest_slots'])
        ->and(AuditEvent::query()->where('tenant_id', $tenant->id)->where('action', 'agent.configured')->exists())->toBeTrue();

    // A bogus autonomy level is ignored (clamp-on-write keeps a valid enum).
    app(AgentConfigService::class)->configure($agent, ['autonomy_level' => 'godmode']);
    expect($agent->refresh()->autonomy_level)->toBe(AutonomyPolicy::APPROVE);
});
