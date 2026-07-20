<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\Audit\Models\AuditEvent;
use Modules\Platform\Models\Permission;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * CLINIC.W9 — the AI approval queue is a READ + ACT-THROUGH-EXISTING-PATH window. These
 * tests prove the safety line: approve/reject go ONLY through AiCore's ApprovalQueue (the
 * eval-harness-locked path), the screen introduces NO new autonomy and NO create/execute
 * path of its own, a human CANNOT push through an action the caps/permissions forbid (the
 * service re-authorizes against the tool's own permission → 403), reject executes nothing,
 * and every decision is audited by the existing app-layer glue. It is ai.manage-gated and
 * tenant-scoped. The agent eval harness (P.4) and audit/immutability suites stay untouched.
 */

function aqCtx(): TenantContext
{
    return app(TenantContext::class);
}

function aqTenant(string $slug): Tenant
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    aqCtx()->set($tenant);

    return $tenant;
}

function aqUser(Tenant $tenant, string $role): User
{
    aqCtx()->set($tenant); // the Role/RoleAssignment models are tenant-scoped
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** A user holding ai.manage (queue access) but NOT the scheduler tool's appointment.manage. */
function aqAiOnlyUser(Tenant $tenant): User
{
    aqCtx()->set($tenant); // the Role/RoleAssignment models are tenant-scoped
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $role = Role::query()->create(['key' => 'ai_only', 'name' => 'AI Only', 'is_system' => false]);
    $role->permissions()->sync(Permission::query()->where('key', 'ai.manage')->pluck('id'));
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id]);

    return $user;
}

/** Propose a pending demo-echo action through the real ApprovalQueue (the path the UI surfaces). */
function aqPropose(User $actor): AgentAction
{
    return app(ApprovalQueue::class)->propose('demo.echo', ['message' => 'hi'], $actor, 'demo.echo', 'demo-agent', 'A demo no-op', 'approve');
}

function aqAudited(Tenant $tenant, string $action): bool
{
    return AuditEvent::query()->where('tenant_id', $tenant->id)->where('action', $action)->exists();
}

test('the queue lists pending actions, is ai.manage gated, tenant-scoped, and exposes no create path', function () {
    $tenant = aqTenant('alpha');
    $admin = aqUser($tenant, 'org_admin');
    aqPropose($admin);

    aqCtx()->forget();
    $this->actingAs($admin)
        ->get(route('governance.approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Governance/ApprovalQueue')
            ->has('pending', 1)
            ->where('pending.0.toolKey', 'demo.echo')
            ->where('pending.0.canReview', true)
            ->has('resolved'));

    // reception lacks ai.manage → denied.
    aqCtx()->forget();
    $this->actingAs(aqUser($tenant, 'reception'))->get(route('governance.approvals.index'))->assertForbidden();

    // A second tenant's admin sees none of alpha's pending actions.
    $beta = aqTenant('beta');
    aqCtx()->forget();
    $this->actingAs(aqUser($beta, 'org_admin'))
        ->get(route('governance.approvals.index'))
        ->assertInertia(fn (Assert $page) => $page->has('pending', 0));

    // There is NO route to create/propose an action from the UI — a human cannot inject one.
    expect(Route::has('governance.approvals.create'))->toBeFalse()
        ->and(Route::has('governance.approvals.store'))->toBeFalse()
        ->and(Route::has('governance.approvals.approve'))->toBeTrue()
        ->and(Route::has('governance.approvals.reject'))->toBeTrue();
});

test('approve executes ONLY through the existing path, is audited, and cannot raise autonomy via the UI', function () {
    $tenant = aqTenant('alpha');
    $admin = aqUser($tenant, 'org_admin');
    $action = aqPropose($admin);
    $level = $action->autonomy_level;

    // Smuggle an autonomy override in the request body — it must be ignored.
    aqCtx()->forget();
    $this->actingAs($admin)
        ->post(route('governance.approvals.approve', $action->id), ['autonomy_level' => 'auto'])
        ->assertRedirect(route('governance.approvals.index'))
        ->assertSessionHas('status', 'approved');

    $action->refresh();
    expect($action->status)->toBe(AgentAction::STATUS_EXECUTED)
        ->and($action->result)->not->toBeNull()
        ->and($action->reviewed_by)->toBe((string) $admin->id)
        ->and($action->executed_at)->not->toBeNull()
        // The UI did NOT raise the autonomy level — the caps still bind.
        ->and($action->autonomy_level)->toBe($level);

    // Approval went through the existing path, so the app-layer glue audited it.
    expect(aqAudited($tenant, 'agent_action.approved'))->toBeTrue()
        ->and(aqAudited($tenant, 'agent_action.executed'))->toBeTrue();
});

test('reject records the decision, executes nothing, requires a reason, and is audited', function () {
    $tenant = aqTenant('alpha');
    $admin = aqUser($tenant, 'org_admin');
    $action = aqPropose($admin);

    // Blank reason → refused by validation; the action stays pending.
    aqCtx()->forget();
    $this->actingAs($admin)
        ->post(route('governance.approvals.reject', $action->id), ['reason' => ''])
        ->assertSessionHasErrors('reason');
    expect($action->refresh()->status)->toBe(AgentAction::STATUS_PENDING);

    aqCtx()->forget();
    $this->actingAs($admin)
        ->post(route('governance.approvals.reject', $action->id), ['reason' => 'Not appropriate right now'])
        ->assertRedirect(route('governance.approvals.index'))
        ->assertSessionHas('status', 'rejected');

    $action->refresh();
    expect($action->status)->toBe(AgentAction::STATUS_REJECTED)
        ->and($action->rejection_reason)->toBe('Not appropriate right now')
        // Reject does NOTHING: no tool ran.
        ->and($action->result)->toBeNull()
        ->and($action->executed_at)->toBeNull();

    expect(aqAudited($tenant, 'agent_action.rejected'))->toBeTrue();
});

test('a human CANNOT exceed the caps via the UI: the service denies a tool the reviewer lacks permission for', function () {
    $tenant = aqTenant('alpha');
    $admin = aqUser($tenant, 'org_admin');

    // A pending action for a tool that requires appointment.manage (not ai.manage).
    $action = AgentAction::query()->create([
        'interaction_id' => null,
        'feature' => 'scheduler.suggest_slots',
        'agent' => 'scheduler-agent',
        'tool_key' => 'scheduler.suggest_slots',
        'autonomy_level' => 'approve',
        'status' => AgentAction::STATUS_PENDING,
        'proposed_by' => (string) $admin->id,
        'why' => 'cap probe',
        'input_payload' => ['service_id' => 'x', 'branch_id' => 'y', 'date' => '2026-07-20'],
        'proposed_output' => ['slots' => []],
    ]);

    // The reviewer holds ai.manage (reaches the queue) but NOT appointment.manage. The
    // ApprovalQueue re-authorizes against the tool's own permission → 403. The UI cannot
    // override the cap, and authorize() runs before execute() so nothing runs.
    $reviewer = aqAiOnlyUser($tenant);
    aqCtx()->forget();
    $this->actingAs($reviewer)->post(route('governance.approvals.approve', $action->id))->assertForbidden();

    expect($action->refresh()->status)->toBe(AgentAction::STATUS_PENDING)
        ->and($action->result)->toBeNull()
        ->and(aqAudited($tenant, 'agent_action.approved'))->toBeFalse();

    // A cross-tenant action id fails closed as 404 (no implicit route-model binding, FIX.1).
    $beta = aqTenant('beta');
    aqCtx()->forget();
    $this->actingAs(aqUser($beta, 'org_admin'))->post(route('governance.approvals.approve', $action->id))->assertNotFound();

    // Nothing executed the action across all of the above.
    expect($action->refresh()->status)->toBe(AgentAction::STATUS_PENDING);
});
