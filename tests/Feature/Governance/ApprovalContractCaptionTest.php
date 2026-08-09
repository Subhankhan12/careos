<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * APPROVAL.P3 — the pending card SURFACES the approve contract to the reviewer. The caption text is
 * built client-side from two REAL props: the action's tool permission (`permission` — the exact one
 * ApprovalQueue::approve re-authorises against) and `reGroundsDraft` (read from the action's own
 * recorded proposed_output shape, so a draft says "re-grounds the draft before it posts" and a direct
 * action says "re-derives … before it runs"). These tests assert both props are the real ones and that
 * reGroundsDraft tracks the action's actual shape. The approve gate itself is unchanged (that stays
 * covered by the existing chrome/anatomy suites) — this only proves the surfaced contract is honest.
 */

function ap3Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function ap3Tenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    ap3Ctx()->set($tenant);

    return $tenant;
}

function ap3Admin(Tenant $tenant): User
{
    ap3Ctx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id]);

    return $user;
}

function ap3Propose(User $actor): AgentAction
{
    return app(ApprovalQueue::class)->propose('demo.echo', ['message' => 'hi'], $actor, 'demo.echo', 'inbox', 'A demo no-op', 'approve');
}

// ── The caption interpolates the action's REAL tool permission (the re-authorise target) ──────────

test('the surfaced permission is the tool real declared permission — the exact one approve re-authorises against', function () {
    $tenant = ap3Tenant();
    $admin = ap3Admin($tenant);
    ap3Propose($admin);

    // The authoritative value the caption interpolates and the service re-checks are the SAME.
    $realPermission = app(ToolRegistry::class)->get('demo.echo')->definition()->permission;

    $this->actingAs($admin)
        ->get('/governance/approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Governance/ApprovalQueue')
            ->where('pending.0.permission', $realPermission) // caption interpolates this — not a hardcode
            ->has('pending.0.reGroundsDraft')
            ->etc());
});

// ── reGroundsDraft reflects the action's REAL shape — accuracy over uniform copy ──────────────────

test('reGroundsDraft is false for a direct (non-draft) action so the caption never claims a draft', function () {
    $tenant = ap3Tenant();
    $admin = ap3Admin($tenant);
    ap3Propose($admin); // demo.echo records {message,label,human_handoff} — no body/lines/handoff → not a draft

    $this->actingAs($admin)
        ->get('/governance/approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('pending.0.reGroundsDraft', false));
});

test('reGroundsDraft is true when the action carries a draft-shaped proposed_output', function () {
    $tenant = ap3Tenant();
    $admin = ap3Admin($tenant);
    $action = ap3Propose($admin);

    // A draft-shaped payload (a body + grounded lines, the shape a draft tool writes) → the caption
    // correctly promises "re-grounds the draft before it posts".
    ap3Ctx()->set($tenant);
    $action->forceFill(['proposed_output' => [
        'handoff' => false,
        'body' => 'Hello — your appointment is confirmed.',
        'lines' => [['text' => 'a', 'source' => ['type' => 'admin_fact', 'key' => 'next_appointment']]],
    ]])->save();

    $this->actingAs($admin)
        ->get('/governance/approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('pending.0.reGroundsDraft', true));
});
