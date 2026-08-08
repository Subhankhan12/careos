<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * APPROVAL.P1 — the approval-queue chrome (Pending/Resolved toggle, agent-type filter pills, the
 * dashed-glass/pill visual) is PURELY presentational and client-side over the existing Inertia
 * props. These light tests assert the DATA the chrome renders over is unchanged, and that the
 * approve/reject gate still routes through AiCore's ApprovalQueue (the refactor changed no gate).
 */

function aqcCtx(): TenantContext
{
    return app(TenantContext::class);
}

function aqcTenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    aqcCtx()->set($tenant);

    return $tenant;
}

function aqcAdmin(Tenant $tenant): User
{
    aqcCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id]);

    return $user;
}

/** Propose a pending demo-echo action through the real ApprovalQueue (the path the UI surfaces). */
function aqcPropose(User $actor): AgentAction
{
    return app(ApprovalQueue::class)->propose('demo.echo', ['message' => 'hi'], $actor, 'demo.echo', 'inbox', 'A demo no-op', 'approve');
}

// ── The chrome's data contract (per-action agent for the filter pills + resolved) ──────────────

test('the queue renders each pending action with its agent (the filter pills) and a resolved view', function () {
    $tenant = aqcTenant();
    $admin = aqcAdmin($tenant);
    aqcPropose($admin);

    $this->actingAs($admin)
        ->get('/governance/approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Governance/ApprovalQueue')
            ->has('pending', 1)
            ->where('pending.0.agent', 'inbox') // the agent drives the client-side filter pills
            ->has('pending.0.toolKey')
            ->has('resolved')); // the resolved view lives behind the toggle
});

// ── The gate is UNCHANGED by the presentational refactor ───────────────────────────────────────

test('approve still routes through the ApprovalQueue gate (the visual refactor changed no gate)', function () {
    $tenant = aqcTenant();
    $admin = aqcAdmin($tenant);
    $action = aqcPropose($admin);

    $this->actingAs($admin)
        ->post("/governance/approvals/{$action->id}/approve")
        ->assertRedirect(route('governance.approvals.index'));

    aqcCtx()->set($tenant);
    // Executed only through ApprovalQueue::approve (re-authorise + assert-pending + tool->execute).
    expect($action->fresh()->status)->toBe(AgentAction::STATUS_EXECUTED);
});

test('reject still requires a reason (the gate is unchanged)', function () {
    $tenant = aqcTenant();
    $admin = aqcAdmin($tenant);
    $action = aqcPropose($admin);

    // Empty reason is refused server-side; the action stays pending.
    $this->actingAs($admin)
        ->post("/governance/approvals/{$action->id}/reject", ['reason' => ''])
        ->assertSessionHasErrors('reason');

    aqcCtx()->set($tenant);
    expect($action->fresh()->status)->toBe(AgentAction::STATUS_PENDING);
});
