<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AiCore\Models\Agent;
use Modules\AiCore\Services\AgentResolver;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\RbacProvisioner;
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

/*
 * AGENT.P3 — the two REFLECT-ONLY / TOGGLE-FREE governance panels: the per-agent permission-ceiling
 * MIRROR (role-derived, read-only) and the electric-fence VAULT (code-enforced invariants). Both are
 * DISPLAY of real gates — no permission-edit path, no fence-disable path exists on this page.
 */

// ── The permission mirror renders the agent's REAL permission states ────────────────────────────

test('the permission mirror reflects the agents REAL exercised permissions (the approve re-authorize targets)', function () {
    $tenant = acTenant();
    $admin = acUser($tenant, 'org_admin');

    // inbox whitelists comms.draft_reply (comms.manage) + comms.classify_document (note.write).
    $this->actingAs($admin)
        ->get('/governance/agents')
        ->assertInertia(fn (Assert $page) => $page->where('agents', function ($agents) {
            $inbox = collect($agents)->firstWhere('key', 'inbox');
            $exercised = collect($inbox['permissions']['exercised'])->pluck('permission')->sort()->values()->all();

            // The exercised permissions are EXACTLY the real permissions of the agent's whitelisted
            // tools — nothing fabricated. These are the same permissions Gate-checked at approve.
            $registry = app(ToolRegistry::class);
            $expected = collect(['comms.draft_reply', 'comms.classify_document'])
                ->map(fn ($k) => $registry->get($k)->definition()->permission)->unique()->sort()->values()->all();

            return $exercised === $expected
                // Each exercised permission is a REAL RBAC permission (labelled from the catalog).
                && collect($inbox['permissions']['exercised'])->every(
                    fn ($p) => array_key_exists($p['permission'], RbacProvisioner::PERMISSIONS)
                );
        }));
});

test('the permission mirror withheld list is REAL and human-only — no registered tool exercises it', function () {
    $tenant = acTenant();
    $admin = acUser($tenant, 'org_admin');

    // The set of permissions ANY registered tool exercises.
    acCtx()->set($tenant);
    $toolPermissions = collect(app(ToolRegistry::class)->all())
        ->map(fn ($t) => $t->definition()->permission)->unique()->values()->all();

    $this->actingAs($admin)
        ->get('/governance/agents')
        ->assertInertia(fn (Assert $page) => $page->where('agents', function ($agents) use ($toolPermissions) {
            $inbox = collect($agents)->firstWhere('key', 'inbox');
            $withheld = collect($inbox['permissions']['withheld']);

            return $withheld->isNotEmpty()
                // Every withheld row is a REAL RBAC permission…
                && $withheld->every(fn ($p) => array_key_exists($p['permission'], RbacProvisioner::PERMISSIONS))
                // …and NO registered tool exercises it (so the denial is derived, not fabricated)…
                && $withheld->every(fn ($p) => ! in_array($p['permission'], $toolPermissions, true))
                // …and it includes the canonical human-only clinical/record actions.
                && $withheld->pluck('permission')->contains('note.sign')
                && $withheld->pluck('permission')->contains('patient.edit');
        }));
});

// ── REFLECT-ONLY: no permission-edit path + no fence-disable path from this page ─────────────────

test('the page exposes NO permission-edit and NO fence-disable route — only index + configure', function () {
    // Structural proof: the ONLY routes under governance.agents are the read (index) and the
    // level/status write (configure). There is no permission-edit or fence-toggle route.
    $names = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->getName())
        ->filter(fn ($n) => is_string($n) && str_starts_with($n, 'governance.agents'))
        ->sort()->values()->all();

    expect($names)->toBe(['governance.agents.configure', 'governance.agents.index']);

    // And there is no permission/fence route name anywhere in this area.
    $forbidden = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => (string) $r->getName())
        ->filter(fn ($n) => str_starts_with($n, 'governance.agents') && preg_match('/permission|fence|invariant/i', $n));
    expect($forbidden)->toBeEmpty();
});

test('the configure endpoint IGNORES forged permission/fence fields — the mirror cannot be edited here', function () {
    $tenant = acTenant();
    $admin = acUser($tenant, 'org_admin');
    $inbox = acAgent($tenant, 'inbox');
    $before = $inbox->tool_keys;

    // Forge a body trying to grant a withheld permission and disable the fence. (tool_keys is a real
    // P4 field with its own clamp — the point HERE is that permission/fence fields are ignored.)
    $this->actingAs($admin)
        ->post("/governance/agents/{$inbox->id}/configure", [
            'autonomy_level' => AutonomyPolicy::SUGGEST,
            'permissions' => ['note.sign' => 'allow', 'patient.edit' => 'allow'],
            'fence' => ['clinical_reviewed' => false],
            'fenceDisabled' => true,
        ])
        ->assertRedirect('/governance/agents');

    // The forged permission/fence fields grant nothing: the whitelist (and thus the mirror) is unchanged.
    expect(acAgent($tenant, 'inbox')->tool_keys)->toBe($before);
});

// ── The fence vault lists only enforced invariants (representative) ──────────────────────────────

test('the fence vault surfaces the code-enforced invariants (toggle-free)', function () {
    $tenant = acTenant();
    $admin = acUser($tenant, 'org_admin');

    $this->actingAs($admin)
        ->get('/governance/agents')
        ->assertInertia(fn (Assert $page) => $page
            ->has('fenceInvariants')
            ->has('rolesUrl')
            ->where('fenceInvariants', fn ($inv) => collect($inv)->contains('human_approves_send')
                && collect($inv)->contains('clinical_reviewed')
                && collect($inv)->contains('immutable_ledger')
                && collect($inv)->contains('reground_at_approve')));
});

/*
 * AGENT.P4 — the EDITABLE tool whitelist. Enabling/disabling changes WHICH tools the agent may call
 * (narrowing the callable set) via AgentConfigService (P1 clamp). Whitelisting NEVER grants a tool
 * past its ceiling (the resolver caps per-tool at runtime, P1). Out-of-remit tools are LOCKED; a
 * forged enable of a locked/unregistered tool is dropped server-side.
 */

// ── The whitelist panel renders "N of M" + remit tools toggle-able, out-of-remit LOCKED ─────────

test('the whitelist lists the agents remit tools as toggle-able and other tools as locked', function () {
    $tenant = acTenant();
    $admin = acUser($tenant, 'org_admin');

    $this->actingAs($admin)
        ->get('/governance/agents')
        ->assertInertia(fn (Assert $page) => $page->where('agents', function ($agents) {
            $inbox = collect($agents)->firstWhere('key', 'inbox');
            $wl = $inbox['whitelist'];
            $byKey = collect($wl['tools'])->keyBy('key');

            // inbox remit = comms.draft_reply + comms.classify_document (both enabled, unlocked).
            return $wl['candidateCount'] === 2 && $wl['enabledCount'] === 2
                && $byKey['comms.draft_reply']['locked'] === false && $byKey['comms.draft_reply']['enabled'] === true
                && $byKey['comms.classify_document']['locked'] === false
                // a tool outside inbox's remit is LOCKED (can't be enabled here)…
                && $byKey['billing.preflight_invoice']['locked'] === true
                && $byKey['billing.preflight_invoice']['enabled'] === false
                // …and the reserved demo tool is never listed.
                && ! $byKey->has('demo.echo');
        }));
});

// ── Enabling/disabling writes the whitelist via configure (real remit keys only, audited) ────────

test('disabling a tool removes it from the whitelist and it becomes non-callable (OFF, P1)', function () {
    $tenant = acTenant();
    $admin = acUser($tenant, 'org_admin');
    $inbox = acAgent($tenant, 'inbox');

    // Disable comms.classify_document — keep only comms.draft_reply.
    $this->actingAs($admin)
        ->post("/governance/agents/{$inbox->id}/configure", ['tool_keys' => ['comms.draft_reply']])
        ->assertRedirect('/governance/agents');

    $updated = acAgent($tenant, 'inbox');
    expect($updated->tool_keys)->toBe(['comms.draft_reply'])
        // the removed tool is no longer callable (narrowed) — the P1 resolver returns OFF.
        ->and($updated->mayCall('comms.classify_document'))->toBeFalse()
        ->and(app(AgentResolver::class)->effectiveLevel($updated, app(ToolRegistry::class)->get('comms.classify_document')->definition()))->toBe(AutonomyPolicy::OFF);

    // Audited (agent.configured), tenant-scoped.
    $audited = DB::selectOne('SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?', [$tenant->id, 'agent.configured'])->c;
    expect((int) $audited)->toBe(1);
});

// ── THE CAP: a forged tool_keys with a locked/unregistered key is DROPPED; a whitelisted tool is
//    STILL capped at runtime (effective = MIN, P1 — never widened by whitelisting) ────────────────

test('THE CAP: a forged tool_keys with a locked or unregistered key is dropped server-side', function () {
    $tenant = acTenant();
    $admin = acUser($tenant, 'org_admin');
    $inbox = acAgent($tenant, 'inbox');

    // Try to force in: a real-but-out-of-remit tool (billing), an unregistered key, plus a legit one.
    $this->actingAs($admin)
        ->post("/governance/agents/{$inbox->id}/configure", ['tool_keys' => [
            'comms.draft_reply',            // legit remit tool → kept
            'billing.preflight_invoice',    // real, but OUTSIDE inbox's remit → dropped (locked)
            'not.a.real.tool',              // unregistered → dropped
        ]])
        ->assertRedirect('/governance/agents');

    $updated = acAgent($tenant, 'inbox');
    // Only the legit remit tool survives — the forged locked + unregistered keys are gone.
    expect($updated->tool_keys)->toBe(['comms.draft_reply'])
        ->and($updated->mayCall('billing.preflight_invoice'))->toBeFalse();
});

test('THE CAP: whitelisting a tool never widens it — effective autonomy stays MIN(config, ceiling, role)', function () {
    $tenant = acTenant();
    $admin = acUser($tenant, 'org_admin');
    $scheduler = acAgent($tenant, 'scheduler'); // remit = scheduler.suggest_slots (ceiling approve) + fill_from_waitlist

    // Force the agent's config to AUTO, keep the tool whitelisted.
    $scheduler->update(['autonomy_level' => AutonomyPolicy::AUTO]);
    $this->actingAs($admin)
        ->post("/governance/agents/{$scheduler->id}/configure", ['tool_keys' => ['scheduler.suggest_slots', 'scheduler.fill_from_waitlist']])
        ->assertRedirect('/governance/agents');

    $updated = acAgent($tenant, 'scheduler');
    // Whitelisted + configured AUTO, but the tool ceiling is approve → effective is still APPROVE, not AUTO.
    expect($updated->mayCall('scheduler.suggest_slots'))->toBeTrue()
        ->and(app(AgentResolver::class)->effectiveLevel($updated, app(ToolRegistry::class)->get('scheduler.suggest_slots')->definition(), AutonomyPolicy::AUTO))
        ->toBe(AutonomyPolicy::APPROVE); // capped by the tool ceiling — whitelisting did not widen it
});

// ── The P3 permission mirror reflects the real updated whitelist (still read-only) ───────────────

test('the P3 permission mirror reflects the real updated whitelist (read-only)', function () {
    $tenant = acTenant();
    $admin = acUser($tenant, 'org_admin');
    $inbox = acAgent($tenant, 'inbox');

    // Disable comms.classify_document (note.write) — leave only comms.draft_reply (comms.manage).
    $this->actingAs($admin)
        ->post("/governance/agents/{$inbox->id}/configure", ['tool_keys' => ['comms.draft_reply']])
        ->assertRedirect('/governance/agents');

    // The mirror's exercised set now reflects the narrowed whitelist: only comms.manage remains.
    $this->actingAs($admin)
        ->get('/governance/agents')
        ->assertInertia(fn (Assert $page) => $page->where('agents', function ($agents) {
            $inbox = collect($agents)->firstWhere('key', 'inbox');
            $exercised = collect($inbox['permissions']['exercised'])->pluck('permission')->sort()->values()->all();

            return $exercised === ['comms.manage']; // note.write dropped with its only tool
        }));
});
