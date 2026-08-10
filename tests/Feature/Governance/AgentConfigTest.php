<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AiCore\Models\Agent;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * AGENT.P2 — the per-agent governance surface: the agent list + detail shell + the AUTONOMY LADDER,
 * presentation over the AGENT.P1 capped resolver. The ladder offers only levels ≤ the agent's
 * effective ceiling (MIN of the tool ceilings it touches); a forged POST of a higher level is
 * clamped server-side and the resolver caps again at call time. These tests ADD coverage; no P1
 * behaviour test is modified.
 */

function acCtx(): TenantContext
{
    return app(TenantContext::class);
}

function acTenant(string $slug = 'alpha'): Tenant
{
    // Tenant::created seeds the 6 canonical agents (AppServiceProvider hook, P1).
    $tenant = Tenant::create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    acCtx()->set($tenant);

    return $tenant;
}

function acUser(Tenant $tenant, string $roleKey): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    if ($roleKey !== '') {
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id]);
    }

    return $user;
}

/** The agent, read fresh with an explicit tenant context (the request context is gone). */
function acAgent(Tenant $tenant, string $key): Agent
{
    acCtx()->set($tenant);

    return Agent::query()->where('key', $key)->firstOrFail();
}

// ── The page lists the tenant's agents (RBAC admin.manage, tenant-scoped) ──────────────────────

test('the agents page lists the tenants governed agents with per-agent ceilings', function () {
    $tenant = acTenant();
    $admin = acUser($tenant, 'org_admin');

    $this->actingAs($admin)
        ->get('/governance/agents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Governance/Agents')
            ->has('agents', 6) // the 6 canonical agents, tenant-scoped
            ->has('levelOrder')
            ->has('agents.0.levels')
            ->has('agents.0.configureUrl'));
});

// ── The ladder offers only levels ≤ the effective ceiling; higher rungs LOCKED ─────────────────

test('the ladder offers only levels at or below the agent effective ceiling', function () {
    $tenant = acTenant();
    $admin = acUser($tenant, 'org_admin');

    $allowed = fn (array $agent): array => collect($agent['levels'])->filter(fn ($l) => $l['allowed'])->pluck('value')->values()->all();

    $this->actingAs($admin)
        ->get('/governance/agents')
        ->assertInertia(fn (Assert $page) => $page->where('agents', function ($agents) use ($allowed) {
            $byKey = collect($agents)->keyBy('key');

            // inbox touches only suggest-ceiling comms tools → ceiling suggest; approve/auto LOCKED.
            // scheduler touches approve-ceiling tools → ceiling approve; auto LOCKED.
            return $byKey['inbox']['ceiling'] === AutonomyPolicy::SUGGEST
                && $allowed($byKey['inbox']) === [AutonomyPolicy::OFF, AutonomyPolicy::SUGGEST]
                && $byKey['scheduler']['ceiling'] === AutonomyPolicy::APPROVE
                && $allowed($byKey['scheduler']) === [AutonomyPolicy::OFF, AutonomyPolicy::SUGGEST, AutonomyPolicy::APPROVE];
        }));
});

// ── Setting a level persists through AgentConfigService + is audited ───────────────────────────

test('setting an agent level within the ceiling persists via configure and is audited', function () {
    $tenant = acTenant();
    $admin = acUser($tenant, 'org_admin');
    $scheduler = acAgent($tenant, 'scheduler'); // ceiling approve

    $this->actingAs($admin)
        ->post("/governance/agents/{$scheduler->id}/configure", ['autonomy_level' => AutonomyPolicy::APPROVE])
        ->assertRedirect('/governance/agents');

    expect(acAgent($tenant, 'scheduler')->autonomy_level)->toBe(AutonomyPolicy::APPROVE);

    $audited = DB::selectOne(
        'SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?',
        [$tenant->id, 'agent.configured'],
    )->c;
    expect((int) $audited)->toBe(1);
});

// ── THE CAP: a forged POST above the effective ceiling is clamped server-side (the key test) ───

test('THE CAP: a forged level above the effective ceiling is clamped through the UI write path', function () {
    $tenant = acTenant();
    $admin = acUser($tenant, 'org_admin');
    $inbox = acAgent($tenant, 'inbox');       // ceiling suggest
    $scheduler = acAgent($tenant, 'scheduler'); // ceiling approve

    // Forge the maximum level for both agents.
    $this->actingAs($admin)
        ->post("/governance/agents/{$inbox->id}/configure", ['autonomy_level' => AutonomyPolicy::AUTO])
        ->assertRedirect('/governance/agents');
    $this->actingAs($admin)
        ->post("/governance/agents/{$scheduler->id}/configure", ['autonomy_level' => AutonomyPolicy::AUTO])
        ->assertRedirect('/governance/agents');

    // Clamped: inbox can never exceed suggest; scheduler can never exceed approve — never 'auto'.
    expect(acAgent($tenant, 'inbox')->autonomy_level)->toBe(AutonomyPolicy::SUGGEST)
        ->and(acAgent($tenant, 'scheduler')->autonomy_level)->toBe(AutonomyPolicy::APPROVE);
});

// ── Pause / resume persists (paused → the agent is off, P1) ─────────────────────────────────────

test('pausing an agent persists and makes it non-callable', function () {
    $tenant = acTenant();
    $admin = acUser($tenant, 'org_admin');
    $inbox = acAgent($tenant, 'inbox');

    $this->actingAs($admin)
        ->post("/governance/agents/{$inbox->id}/configure", ['status' => Agent::STATUS_PAUSED])
        ->assertRedirect('/governance/agents');

    $paused = acAgent($tenant, 'inbox');
    expect($paused->status)->toBe(Agent::STATUS_PAUSED)
        ->and($paused->mayCall('comms.draft_reply'))->toBeFalse(); // paused → off (P1)
});

// ── RBAC admin.manage + tenant isolation ───────────────────────────────────────────────────────

test('the agents page is gated on admin.manage — a non-admin is 403 on read and write', function () {
    $tenant = acTenant();
    $reception = acUser($tenant, 'reception'); // no admin.manage
    $inbox = acAgent($tenant, 'inbox');

    $this->actingAs($reception)->get('/governance/agents')->assertForbidden();
    $this->actingAs($reception)
        ->post("/governance/agents/{$inbox->id}/configure", ['autonomy_level' => AutonomyPolicy::OFF])
        ->assertForbidden();
});

test('configuring another tenants agent id fails closed (404)', function () {
    $alpha = acTenant('alpha');
    $alphaAdmin = acUser($alpha, 'org_admin');
    $beta = acTenant('beta');
    $betaInbox = acAgent($beta, 'inbox'); // a beta-owned agent id

    // Alpha's admin, acting in alpha's context, cannot reach beta's agent → 404 (tenant scope).
    acCtx()->set($alpha);
    $this->actingAs($alphaAdmin)
        ->post("/governance/agents/{$betaInbox->id}/configure", ['autonomy_level' => AutonomyPolicy::OFF])
        ->assertNotFound();
});

// ── The per-tool SETTINGS.P2 card is unaffected ────────────────────────────────────────────────

test('the per-tool SETTINGS.P2 agents card still works unchanged', function () {
    $tenant = acTenant();
    $admin = acUser($tenant, 'org_admin');

    $this->actingAs($admin)
        ->get('/admin/agents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Agents')->has('tools', 10));
});
