<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * APPROVAL.P2 — the pending-card anatomy surfaces REAL provenance the action already carries:
 * the tool's declared permission (the one approve re-authorises against), the AutonomyPolicy
 * CEILING (the cap, distinct from the proposed level), the action's recorded grounding sources
 * (or an honest absence — never fabricated), and the What/Why from real fields. These tests assert
 * the surfaced values are the real ones; the gate itself is unchanged.
 */

function ap2Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function ap2Tenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    ap2Ctx()->set($tenant);

    return $tenant;
}

function ap2Admin(Tenant $tenant): User
{
    ap2Ctx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id]);

    return $user;
}

/** Propose a pending demo-echo action through the real ApprovalQueue at a given autonomy level. */
function ap2Propose(User $actor, string $level = 'approve'): AgentAction
{
    return app(ApprovalQueue::class)->propose('demo.echo', ['message' => 'hi'], $actor, 'demo.echo', 'inbox', 'A demo no-op', $level);
}

// ── Permission + ceiling are the REAL tool provenance ──────────────────────────────────────────

test('the card surfaces the tool real required permission and the AutonomyPolicy ceiling (not the proposed level)', function () {
    $tenant = ap2Tenant();
    $admin = ap2Admin($tenant);
    ap2Propose($admin, 'approve');

    // The authoritative values, read from the tool's own declaration + the cap.
    $definition = app(ToolRegistry::class)->get('demo.echo')->definition();
    $realPermission = $definition->permission;                     // the one approve re-authorises against
    $realCeiling = app(AutonomyPolicy::class)->effectiveCeiling($definition); // the cap

    $this->actingAs($admin)
        ->get('/governance/approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Governance/ApprovalQueue')
            ->where('pending.0.permission', $realPermission)
            ->where('pending.0.ceiling', $realCeiling)
            ->where('pending.0.autonomyLevel', 'approve') // the PROPOSED level is distinct from the ceiling
            ->etc());

    expect($realCeiling)->not->toBe('approve'); // proof the ceiling is the cap, not the proposed level
});

// ── Sources are REAL recorded grounding — honest absence when none ─────────────────────────────

test('the sources line renders the action real recorded grounding, and nothing is fabricated when none', function () {
    $tenant = ap2Tenant();
    $admin = ap2Admin($tenant);
    $action = ap2Propose($admin);

    // The demo action records no grounding lines → an honest empty sources list.
    $this->actingAs($admin)->get('/governance/approvals')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('pending.0.sources', []));

    // A draft-shaped payload with recorded line sources → those exact refs are surfaced (deduped).
    ap2Ctx()->set($tenant);
    $action->forceFill(['proposed_output' => [
        'handoff' => false,
        'lines' => [
            ['text' => 'a', 'source' => ['type' => 'kb_article', 'id' => 'ART-1']],
            ['text' => 'b', 'source' => ['type' => 'admin_fact', 'key' => 'next_appointment']],
            ['text' => 'c', 'source' => ['type' => 'kb_article', 'id' => 'ART-1']], // duplicate → deduped
        ],
    ]])->save();

    $this->actingAs($admin)->get('/governance/approvals')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('pending.0.sources', [
            ['type' => 'kb_article', 'ref' => 'ART-1'],
            ['type' => 'admin_fact', 'ref' => 'next_appointment'],
        ]));
});

// ── What / Why come from real action fields ────────────────────────────────────────────────────

test('the What/Why surface the real action fields (the tool name + the recorded reason)', function () {
    $tenant = ap2Tenant();
    $admin = ap2Admin($tenant);
    ap2Propose($admin);

    $realName = app(ToolRegistry::class)->get('demo.echo')->definition()->name; // the What = the tool's declared intent

    $this->actingAs($admin)
        ->get('/governance/approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pending.0.toolName', $realName)
            ->where('pending.0.why', 'A demo no-op')
            ->has('pending.0.queuedAt')
            ->etc());
});
