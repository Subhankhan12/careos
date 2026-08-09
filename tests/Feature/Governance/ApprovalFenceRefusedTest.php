<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AiCore\Exceptions\FenceRefusalException;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\AiInteraction;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\Audit\Models\AuditEvent;
use Modules\Comms\Models\Message;
use Modules\Comms\Services\ThreadService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * APPROVAL.P5 — model the ELECTRIC FENCE refusal as a countable, recorded AgentAction outcome, and
 * compute the governance stat strip from REAL records only. The fence itself is UNCHANGED — a
 * handed-off draft still throws on approve, exactly when it did before (a FenceRefusalException,
 * still a subclass of AiCoreException). What is new is that the refusal is now RECORDED: a terminal
 * `fence_refused` status (with the fence's own reason), an append-only `fence_refused` ledger row,
 * and an audited lifecycle event — so it can be counted and listed. Every stat is computed from real
 * records; a metric with no real source is honestly absent (null → "—"), never fabricated. These
 * tests ADD coverage; no existing behaviour test is modified, and the fence eval is untouched.
 */

function fenceCtx(): TenantContext
{
    return app(TenantContext::class);
}

function fenceTenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    fenceCtx()->set($tenant);

    return $tenant;
}

function fenceAdmin(Tenant $tenant): User
{
    fenceCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create(); // org_admin: comms.manage + ai.manage
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id]);

    return $user;
}

/**
 * Propose a REAL comms.draft_reply action whose draft the engine hands off (a non-groundable patient
 * message → handoff:true) — the genuine fence path. Approving it fires the fence on execute.
 */
function fenceProposeHandoffDraft(Tenant $tenant, User $actor): AgentAction
{
    fenceCtx()->set($tenant);

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
    // A polite, non-groundable, NON-clinical message → the engine can ground nothing → handoff.
    app(ThreadService::class)->postPatientMessage($thread, $patient, 'Vielen Dank für Ihre Hilfe!');

    return app(ApprovalQueue::class)->propose(
        'comms.draft_reply', ['thread_id' => $thread->id], $actor, 'comms.draft_reply', 'inbox',
        'A non-groundable patient message; the draft hands off.', AutonomyPolicy::SUGGEST,
    );
}

/** A pending demo-echo action (ai.manage; executes cleanly — a non-fence control). */
function fenceProposeEcho(Tenant $tenant, User $actor): AgentAction
{
    fenceCtx()->set($tenant);

    return app(ApprovalQueue::class)->propose('demo.echo', ['message' => 'hi'], $actor, 'demo.echo', 'inbox', 'A demo no-op', 'approve');
}

// ── The fence refusal is RECORDED as a distinguishable outcome (fence unchanged) ────────────────────

test('a fence refusal is recorded as a fence_refused outcome (append-only ledger + reason + audit); nothing executes', function () {
    $tenant = fenceTenant();
    $admin = fenceAdmin($tenant);
    $action = fenceProposeHandoffDraft($tenant, $admin);

    // The action was proposed as a HANDOFF draft — the pre-existing fence trigger, unchanged.
    expect($action->proposed_output['handoff'] ?? null)->toBeTrue();

    // Approving it fires the fence exactly as before (execute throws) — now a FenceRefusalException.
    fenceCtx()->set($tenant);
    expect(fn () => app(ApprovalQueue::class)->approve($action, $admin))
        ->toThrow(FenceRefusalException::class, 'This draft handed off to a human; there is nothing to send.');

    $action->refresh();

    // The refusal is now a terminal, recorded outcome — distinguishable from a human reject.
    expect($action->status)->toBe(AgentAction::STATUS_FENCE_REFUSED)
        ->and($action->fence_refused_at)->not->toBeNull()
        ->and($action->rejection_reason)->toBe('This draft handed off to a human; there is nothing to send.')
        ->and($action->reviewed_by)->toBe((string) $admin->id)
        // Nothing executed: no result, and no staff message was posted.
        ->and($action->result)->toBeNull()
        ->and($action->executed_at)->toBeNull()
        ->and(Message::query()->where('author_type', Message::AUTHOR_STAFF)->count())->toBe(0);

    // Append-only ledger row + audited lifecycle event, both carrying the fence's own reason.
    fenceCtx()->set($tenant);
    $ledger = AiInteraction::query()->where('output_ref', $action->id)->where('outcome', 'fence_refused')->first();
    expect($ledger)->not->toBeNull()
        ->and($ledger->metadata['reason'] ?? null)->toBe('This draft handed off to a human; there is nothing to send.')
        ->and(AuditEvent::query()->where('tenant_id', $tenant->id)->where('action', 'agent_action.fence_refused')->exists())->toBeTrue();
});

test('only the fence path records fence_refused — a clean action still executes (the trigger is unchanged)', function () {
    $tenant = fenceTenant();
    $admin = fenceAdmin($tenant);
    $echo = fenceProposeEcho($tenant, $admin);

    fenceCtx()->set($tenant);
    app(ApprovalQueue::class)->approve($echo, $admin);

    // A non-handoff action executes normally — the fence did not fire, nothing is fence_refused.
    expect($echo->refresh()->status)->toBe(AgentAction::STATUS_EXECUTED)
        ->and(AgentAction::query()->where('status', AgentAction::STATUS_FENCE_REFUSED)->count())->toBe(0);
});

// ── The stat strip is computed from REAL records; honest absence where no source ────────────────────

test('the stat strip shows real pending, a real fence count, and honest "—" for approved-%/avg-review when nothing is resolved', function () {
    $tenant = fenceTenant();
    $admin = fenceAdmin($tenant);
    fenceProposeEcho($tenant, $admin); // one pending, nothing resolved yet

    fenceCtx()->forget();
    $this->actingAs($admin)
        ->get(route('governance.approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Governance/ApprovalQueue')
            ->where('stats.pending', 1)
            ->where('stats.fenceRefused', 0)
            // No resolved action in the window → no denominator → honestly absent (null → "—"), NOT 0.
            ->where('stats.approvedPct', null)
            ->where('stats.avgReviewMinutes', null)
            ->where('stats.windowDays', 30)
            ->etc());
});

test('the fence count reflects a real fence refusal, and approved-%/avg-review compute from real resolved records', function () {
    $tenant = fenceTenant();
    $admin = fenceAdmin($tenant);

    // One clean action (approved/executed) + one handoff draft refused by the fence via the controller.
    $echo = fenceProposeEcho($tenant, $admin);
    $handoff = fenceProposeHandoffDraft($tenant, $admin);

    fenceCtx()->forget();
    $this->actingAs($admin)->post(route('governance.approvals.approve', $echo->id))->assertRedirect();

    // Approving the handoff draft is refused by the fence — surfaced honestly (status 'fence_refused').
    fenceCtx()->forget();
    $this->actingAs($admin)
        ->post(route('governance.approvals.approve', $handoff->id))
        ->assertRedirect(route('governance.approvals.index'))
        ->assertSessionHas('status', 'fence_refused');

    expect($handoff->refresh()->status)->toBe(AgentAction::STATUS_FENCE_REFUSED);

    fenceCtx()->forget();
    $this->actingAs($admin)
        ->get(route('governance.approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.pending', 0)
            ->where('stats.fenceRefused', 1)            // the real fence_refused count
            ->where('stats.approvedPct', 50)            // 1 executed of 2 resolved (executed + fence_refused)
            ->where('stats.avgReviewMinutes', fn ($v) => $v !== null) // computed from real timestamps (not absent)
            ->has('resolved', 2)
            ->etc());
});
