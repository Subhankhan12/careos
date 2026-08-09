<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\Comms\Services\ThreadService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Permission;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * APPROVAL.P7 — bulk-approve, LOW-RISK only. Bulk is a LOOP over the real per-action approve gate, not
 * a batch path that skips it: each selected action still runs re-authorise + re-ground + assert-pending.
 * THE SAFETY GATE (server-enforced, not just a disabled checkbox): clinical AND financial actions are
 * NEVER bulk-approved — a forged bulk including one is refused for that item (it stays pending). The
 * fence still fires per item (P5), and per-item RBAC still binds. These tests ADD coverage; no existing
 * behaviour test is modified.
 */

function bkCtx(): TenantContext
{
    return app(TenantContext::class);
}

function bkTenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    bkCtx()->set($tenant);

    return $tenant;
}

function bkAdmin(Tenant $tenant): User
{
    bkCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create(); // org_admin: ai.manage + note.write + billing.manage + comms.manage
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id]);

    return $user;
}

/** ai.manage only (queue access + demo.echo) — lacks appointment.manage/note.write/billing.manage. */
function bkAiOnly(Tenant $tenant): User
{
    bkCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $role = Role::query()->create(['key' => 'ai_only', 'name' => 'AI Only', 'is_system' => false]);
    $role->permissions()->sync(Permission::query()->where('key', 'ai.manage')->pluck('id'));
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id]);

    return $user;
}

/** A real pending low-risk (operational) demo.echo action, proposed through the real path. */
function bkEcho(Tenant $tenant, User $actor): AgentAction
{
    bkCtx()->set($tenant);

    return app(ApprovalQueue::class)->propose('demo.echo', ['message' => 'hi'], $actor, 'demo.echo', 'inbox', 'A demo no-op', 'approve');
}

/** A pending action for the given tool key (used to place a clinical/financial/scheduler action in the queue). */
function bkPending(Tenant $tenant, User $actor, string $toolKey, string $feature): AgentAction
{
    bkCtx()->set($tenant);

    return AgentAction::query()->create([
        'interaction_id' => null,
        'feature' => $feature,
        'agent' => 'agent',
        'tool_key' => $toolKey,
        'autonomy_level' => 'approve',
        'status' => AgentAction::STATUS_PENDING,
        'proposed_by' => (string) $actor->id,
        'why' => 'bulk probe',
        'input_payload' => ['x' => 'y'],
        'proposed_output' => ['ok' => true],
    ]);
}

/** A real pending low-risk handoff draft (comms.draft_reply) — approving it fires the fence (P5). */
function bkHandoffDraft(Tenant $tenant, User $actor): AgentAction
{
    bkCtx()->set($tenant);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Fence', 'last_name' => 'Probe', 'date_of_birth' => '1990-01-01', 'sex' => 'female',
    ]);
    ConsentTemplate::query()->firstOrCreate(
        ['key' => 'portal', 'version' => 1],
        ['title' => 'Portal Access', 'body' => 'Portal access consent', 'scope_keys' => ['portal.access'], 'is_active' => true],
    );
    app(ConsentService::class)->grant($patient, 'portal', 'Fence Probe', $actor);
    PortalAccount::query()->create([
        'patient_id' => $patient->id, 'email' => 'fence.'.$patient->id.'@portal.test',
        'password' => bcrypt('secret-portal-pass'), 'status' => PortalAccount::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $thread = app(ThreadService::class)->openPatientThread($patient, 'Question', $actor);
    app(ThreadService::class)->postPatientMessage($thread, $patient, 'Vielen Dank für Ihre Hilfe!');

    return app(ApprovalQueue::class)->propose('comms.draft_reply', ['thread_id' => $thread->id], $actor, 'comms.draft_reply', 'inbox', 'A non-groundable patient message; the draft hands off.', AutonomyPolicy::SUGGEST);
}

// ── Bulk-approve of low-risk actions loops the full per-action gate ─────────────────────────────────

test('bulk-approve of low-risk actions approves each through the full per-action gate', function () {
    $tenant = bkTenant();
    $admin = bkAdmin($tenant);
    $a = bkEcho($tenant, $admin);
    $b = bkEcho($tenant, $admin);

    bkCtx()->forget();
    $this->actingAs($admin)
        ->post(route('governance.approvals.bulk_approve'), ['ids' => [$a->id, $b->id]])
        ->assertRedirect(route('governance.approvals.index'))
        ->assertSessionHas('bulk', ['approved' => 2, 'excluded' => 0, 'skipped' => 0]);

    expect($a->refresh()->status)->toBe(AgentAction::STATUS_EXECUTED)
        ->and($a->reviewed_by)->toBe((string) $admin->id)
        ->and($b->refresh()->status)->toBe(AgentAction::STATUS_EXECUTED);
});

// ── THE EXCLUSION PROOF: clinical AND financial are refused for bulk, server-side ───────────────────

test('a bulk that includes a clinical OR financial action refuses those items server-side — they stay pending', function () {
    $tenant = bkTenant();
    $admin = bkAdmin($tenant); // holds note.write + billing.manage, yet clinical/financial are STILL excluded from bulk

    $low = bkEcho($tenant, $admin);
    $clinical = bkPending($tenant, $admin, 'clinical.summarize_since_last_visit', 'clinical.summarize_since_last_visit');
    $financial = bkPending($tenant, $admin, 'billing.suggest_charge_codes', 'billing.suggest_charge_codes');

    // Forge ALL THREE into the bulk request — the server must exclude the clinical + financial ones.
    bkCtx()->forget();
    $this->actingAs($admin)
        ->post(route('governance.approvals.bulk_approve'), ['ids' => [$low->id, $clinical->id, $financial->id]])
        ->assertRedirect(route('governance.approvals.index'))
        ->assertSessionHas('bulk', ['approved' => 1, 'excluded' => 2, 'skipped' => 0]);

    // The low-risk item approved; the clinical AND financial items were NOT approved via bulk (still pending).
    expect($low->refresh()->status)->toBe(AgentAction::STATUS_EXECUTED)
        ->and($clinical->refresh()->status)->toBe(AgentAction::STATUS_PENDING)
        ->and($financial->refresh()->status)->toBe(AgentAction::STATUS_PENDING);
});

// ── The fence still fires per bulk item — a handoff draft is recorded fence_refused, not forced ─────

test('a bulk item that hits the fence is recorded fence_refused (P5), not forced through', function () {
    $tenant = bkTenant();
    $admin = bkAdmin($tenant);

    $low = bkEcho($tenant, $admin);
    $handoff = bkHandoffDraft($tenant, $admin); // operational (low-risk) but re-grounds to a handoff → fence

    bkCtx()->forget();
    $this->actingAs($admin)
        ->post(route('governance.approvals.bulk_approve'), ['ids' => [$low->id, $handoff->id]])
        ->assertRedirect(route('governance.approvals.index'))
        // The fenced item counts as skipped (not approved, not forced through).
        ->assertSessionHas('bulk', ['approved' => 1, 'excluded' => 0, 'skipped' => 1]);

    expect($low->refresh()->status)->toBe(AgentAction::STATUS_EXECUTED)
        // The fence fired and RECORDED the refusal (P5) — the item was not forced through.
        ->and($handoff->refresh()->status)->toBe(AgentAction::STATUS_FENCE_REFUSED)
        ->and($handoff->result)->toBeNull();
});

// ── Per-item RBAC: an item the reviewer cannot review is skipped; the rest still approve ─────────────

test('bulk re-authorises per item — an item the reviewer lacks permission for is skipped, the low-risk item still approves', function () {
    $tenant = bkTenant();
    $admin = bkAdmin($tenant);
    $reviewer = bkAiOnly($tenant); // ai.manage only

    // Both operational (low-risk, so neither is excluded by the safety gate) but with different permissions.
    $echo = bkEcho($tenant, $admin);                                                  // demo.echo → ai.manage (reviewer HAS it)
    $scheduler = bkPending($tenant, $admin, 'scheduler.suggest_slots', 'scheduler.suggest_slots'); // → appointment.manage (reviewer lacks)

    bkCtx()->forget();
    $this->actingAs($reviewer)
        ->post(route('governance.approvals.bulk_approve'), ['ids' => [$echo->id, $scheduler->id]])
        ->assertRedirect(route('governance.approvals.index'))
        ->assertSessionHas('bulk', ['approved' => 1, 'excluded' => 0, 'skipped' => 1]);

    expect($echo->refresh()->status)->toBe(AgentAction::STATUS_EXECUTED)
        // Re-authorised per item: the reviewer lacks appointment.manage → the scheduler action is NOT approved.
        ->and($scheduler->refresh()->status)->toBe(AgentAction::STATUS_PENDING);
});
