<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * SETTINGS.P2 — the "Agents & automation" settings card is presentation over the existing
 * AutonomyPolicy. It reads the real registered governed tools and can LOWER a tool's autonomy,
 * but it writes ONLY through AutonomyPolicy::set() (which clamps to the ceiling) and can NEVER
 * raise autonomy past a tool's cap. These tests assert that behavior; they add no policy.
 */

function agAutoCtx(): TenantContext
{
    return app(TenantContext::class);
}

function agAutoTenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    agAutoCtx()->set($tenant);

    return $tenant;
}

function agAutoUser(Tenant $tenant, string $roleKey): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    if ($roleKey !== '') {
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id]);
    }

    return $user;
}

/** Level a tool currently resolves to, read with an explicit tenant context (request context is gone). */
function agAutoLevel(Tenant $tenant, string $key): string
{
    agAutoCtx()->set($tenant);

    return app(AutonomyPolicy::class)->levelFor(app(ToolRegistry::class)->get($key)->definition());
}

// ── The card reflects the real governed-tool set at real levels ───────────────

test('the agents card lists the real governed tools with their ceilings — and hides the demo tool', function () {
    $tenant = agAutoTenant();
    $admin = agAutoUser($tenant, 'org_admin');

    $this->actingAs($admin)
        ->get('/admin/agents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Agents')
            ->has('tools', 10) // the 10 real tools; the reserved demo.echo is excluded
            ->has('updateUrl')
            ->has('levelOrder')
            ->has('tools.0.levels'));

    // The presented tools are exactly the registered set minus demo.*.
    agAutoCtx()->set($tenant);
    $keys = collect(app(ToolRegistry::class)->all())->keys();
    expect($keys)->toContain('demo.echo'); // registered…
    // …but the card must not surface it.
    $this->actingAs($admin)->get('/admin/agents')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tools', fn ($tools) => collect($tools)->pluck('key')->doesntContain('demo.echo')));
});

test('a clinical tool caps at suggest and a financial tool caps at approve — the visible locked ceiling', function () {
    $tenant = agAutoTenant();
    agAutoCtx()->set($tenant);
    $policy = app(AutonomyPolicy::class);
    $tools = app(ToolRegistry::class);

    // The effective ceiling the card renders as the locked limit is the SAME cap the runtime applies.
    expect($policy->effectiveCeiling($tools->get('clinical.summarize_since_last_visit')->definition()))->toBe(AutonomyPolicy::SUGGEST)
        ->and($policy->effectiveCeiling($tools->get('billing.preflight_invoice')->definition()))->toBe(AutonomyPolicy::APPROVE);
});

// ── Save LOWERS autonomy through AutonomyPolicy, audited ───────────────────────

test('saving lowers a tool autonomy through AutonomyPolicy and audits the change', function () {
    $tenant = agAutoTenant();
    $admin = agAutoUser($tenant, 'org_admin');

    // comms.draft_reply defaults to suggest; lower it to off.
    expect(agAutoLevel($tenant, 'comms.draft_reply'))->toBe(AutonomyPolicy::SUGGEST);

    $this->actingAs($admin)
        ->post('/admin/agents', ['levels' => ['comms.draft_reply' => AutonomyPolicy::OFF]])
        ->assertRedirect('/admin/agents');

    expect(agAutoLevel($tenant, 'comms.draft_reply'))->toBe(AutonomyPolicy::OFF);

    // The change is audited (app-layer), tenant-scoped.
    $audited = DB::selectOne(
        'SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?',
        [$tenant->id, 'ai.autonomy_changed'],
    )->c;
    expect((int) $audited)->toBe(1);
});

test('an unchanged save writes no audit row (no churn)', function () {
    $tenant = agAutoTenant();
    $admin = agAutoUser($tenant, 'org_admin');

    // Re-submit the current level — nothing changes.
    $this->actingAs($admin)
        ->post('/admin/agents', ['levels' => ['comms.draft_reply' => AutonomyPolicy::SUGGEST]])
        ->assertRedirect('/admin/agents');

    $audited = DB::selectOne(
        'SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?',
        [$tenant->id, 'ai.autonomy_changed'],
    )->c;
    expect((int) $audited)->toBe(0);
});

// ── THE FENCE: the card cannot raise autonomy past a tool's ceiling ────────────

test('THE FENCE: a level above the ceiling is clamped server-side, never raised', function () {
    $tenant = agAutoTenant();
    $admin = agAutoUser($tenant, 'org_admin');

    // Forge the request body with the maximum level for a clinical AND a financial tool.
    $this->actingAs($admin)
        ->post('/admin/agents', ['levels' => [
            'clinical.summarize_since_last_visit' => AutonomyPolicy::AUTO,
            'billing.preflight_invoice' => AutonomyPolicy::AUTO,
        ]])
        ->assertRedirect('/admin/agents');

    // Clamped: clinical can never exceed suggest; financial can never exceed approve.
    expect(agAutoLevel($tenant, 'clinical.summarize_since_last_visit'))->toBe(AutonomyPolicy::SUGGEST)
        ->and(agAutoLevel($tenant, 'billing.preflight_invoice'))->toBe(AutonomyPolicy::APPROVE);
});

test('THE FENCE: even an operational suggest-ceiling tool cannot be raised to approve or auto', function () {
    $tenant = agAutoTenant();
    $admin = agAutoUser($tenant, 'org_admin');

    $this->actingAs($admin)
        ->post('/admin/agents', ['levels' => ['comms.classify_document' => AutonomyPolicy::AUTO]])
        ->assertRedirect('/admin/agents');

    expect(agAutoLevel($tenant, 'comms.classify_document'))->toBe(AutonomyPolicy::SUGGEST); // ceiling is suggest
});

// ── RBAC + tenant isolation ───────────────────────────────────────────────────

test('the agents card is gated on ai.manage — a non-admin is 403 on read and write', function () {
    $tenant = agAutoTenant();
    $reception = agAutoUser($tenant, 'reception'); // no ai.manage

    $this->actingAs($reception)->get('/admin/agents')->assertForbidden();
    $this->actingAs($reception)
        ->post('/admin/agents', ['levels' => ['comms.draft_reply' => AutonomyPolicy::OFF]])
        ->assertForbidden();
});

test('autonomy writes are tenant-scoped', function () {
    $alpha = agAutoTenant('alpha');
    $alphaAdmin = agAutoUser($alpha, 'org_admin');
    $beta = agAutoTenant('beta'); // sets context to beta

    $this->actingAs($alphaAdmin)
        ->post('/admin/agents', ['levels' => ['comms.draft_reply' => AutonomyPolicy::OFF]])
        ->assertRedirect('/admin/agents');

    // Beta never saw the write — its tool is still at the default suggest.
    expect(agAutoLevel($beta, 'comms.draft_reply'))->toBe(AutonomyPolicy::SUGGEST);
});
