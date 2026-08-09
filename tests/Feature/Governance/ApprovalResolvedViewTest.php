<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AiCore\Exceptions\FenceRefusalException;
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
 * APPROVAL.P6 — the Resolved view: a searchable, filterable, grouped, attributed list over the REAL
 * resolved set (executed / rejected / P5 fence_refused). Every filter maps to a real column; counts
 * and rows are real records; a fence refusal is attributed to the system (with the fence reason), a
 * human resolution to the real reviewer. The list + counts are tenant-scoped and RBAC-scoped to the
 * tools the reviewer may review. These tests ADD coverage; no existing behaviour test is modified.
 */

function rvCtx(): TenantContext
{
    return app(TenantContext::class);
}

function rvTenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    rvCtx()->set($tenant);

    return $tenant;
}

function rvAdmin(Tenant $tenant): User
{
    rvCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id]);

    return $user;
}

/** A reviewer holding ai.manage (queue access + demo.echo) but NOT comms.manage/appointment.manage. */
function rvAiOnly(Tenant $tenant): User
{
    rvCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $role = Role::query()->create(['key' => 'ai_only', 'name' => 'AI Only', 'is_system' => false]);
    $role->permissions()->sync(Permission::query()->where('key', 'ai.manage')->pluck('id'));
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id]);

    return $user;
}

function rvExecuted(Tenant $tenant, User $actor, string $why = 'A demo no-op'): AgentAction
{
    rvCtx()->set($tenant);
    $action = app(ApprovalQueue::class)->propose('demo.echo', ['message' => 'hi'], $actor, 'demo.echo', 'inbox', $why, 'approve');
    app(ApprovalQueue::class)->approve($action, $actor);

    return $action->refresh();
}

function rvRejected(Tenant $tenant, User $actor, string $reason): AgentAction
{
    rvCtx()->set($tenant);
    $action = app(ApprovalQueue::class)->propose('demo.echo', ['message' => 'hi'], $actor, 'demo.echo', 'inbox', 'A demo no-op', 'approve');
    app(ApprovalQueue::class)->reject($action, $actor, $reason);

    return $action->refresh();
}

/** A REAL handoff draft (non-groundable message) approved → recorded fence_refused (P5). */
function rvFenceRefused(Tenant $tenant, User $actor): AgentAction
{
    rvCtx()->set($tenant);

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

    $action = app(ApprovalQueue::class)->propose('comms.draft_reply', ['thread_id' => $thread->id], $actor, 'comms.draft_reply', 'inbox', 'A non-groundable patient message; the draft hands off.', AutonomyPolicy::SUGGEST);

    try {
        app(ApprovalQueue::class)->approve($action, $actor);
    } catch (FenceRefusalException) {
        // Expected — the fence refused it; the refusal is recorded as fence_refused (P5).
    }

    return $action->refresh();
}

// ── The resolved list carries real outcome + attribution + reason; counts are real ─────────────────

test('the resolved view lists real actions with real outcome badges, reviewer/system attribution, and real reasons', function () {
    $tenant = rvTenant();
    $admin = rvAdmin($tenant);

    rvExecuted($tenant, $admin);
    rvRejected($tenant, $admin, 'Not appropriate right now');
    rvFenceRefused($tenant, $admin);

    // Real per-status counts over the (RBAC-allowed, tenant-scoped) resolved set.
    rvCtx()->forget();
    $this->actingAs($admin)
        ->get(route('governance.approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Governance/ApprovalQueue')
            ->has('resolved', 3)
            ->where('resolvedCounts.all', 3)
            ->where('resolvedCounts.executed', 1)
            ->where('resolvedCounts.rejected', 1)
            ->where('resolvedCounts.fence_refused', 1)
            ->etc());

    // Executed → the human reviewer, not system.
    rvCtx()->forget();
    $this->actingAs($admin)
        ->get(route('governance.approvals.index', ['rstatus' => 'executed']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('resolved', 1)
            ->where('resolved.0.status', 'executed')
            ->where('resolved.0.systemAttributed', false)
            ->where('resolved.0.reviewerName', $admin->name)
            ->etc());

    // Rejected → the recorded human reason.
    rvCtx()->forget();
    $this->actingAs($admin)
        ->get(route('governance.approvals.index', ['rstatus' => 'rejected']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('resolved', 1)
            ->where('resolved.0.status', 'rejected')
            ->where('resolved.0.systemAttributed', false)
            ->where('resolved.0.rejectionReason', 'Not appropriate right now')
            ->etc());
});

// ── The Fence-refused filter returns the P5 fence_refused actions, system-attributed ────────────────

test('the Fence-refused filter returns only the fence_refused actions — system-attributed, with the fence reason', function () {
    $tenant = rvTenant();
    $admin = rvAdmin($tenant);

    rvExecuted($tenant, $admin);
    rvFenceRefused($tenant, $admin);

    rvCtx()->forget();
    $this->actingAs($admin)
        ->get(route('governance.approvals.index', ['rstatus' => 'fence_refused']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('resolved', 1)
            ->where('resolved.0.status', 'fence_refused')
            // Attributed to the system (the fence), NOT a human reviewer.
            ->where('resolved.0.systemAttributed', true)
            ->where('resolved.0.reviewerName', null)
            ->where('resolved.0.rejectionReason', 'This draft handed off to a human; there is nothing to send.')
            ->etc());
});

// ── Search + reviewer filters query REAL fields ─────────────────────────────────────────────────────

test('search filters by a real field (the recorded why) and the reviewer filter maps to reviewed_by', function () {
    $tenant = rvTenant();
    $admin = rvAdmin($tenant);

    $alpha = rvExecuted($tenant, $admin, 'Alpha marker unique probe');
    rvExecuted($tenant, $admin, 'Beta something else');

    // Search matches the real `why` column → only the alpha action.
    rvCtx()->forget();
    $this->actingAs($admin)
        ->get(route('governance.approvals.index', ['rq' => 'Alpha marker']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('resolved', 1)
            ->where('resolved.0.id', $alpha->id)
            ->where('resolvedCounts.executed', 1)
            ->etc());

    // Reviewer filter maps to reviewed_by — a reviewer who resolved nothing returns an empty set.
    $stranger = rvAdmin(Tenant::query()->where('slug', 'alpha')->firstOrFail()); // same tenant, resolved nothing
    rvCtx()->forget();
    $this->actingAs($admin)
        ->get(route('governance.approvals.index', ['rreviewer' => $stranger->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('resolved', 0)
            ->where('resolvedCounts.all', 0)
            ->etc());
});

// ── Tenant + RBAC scoped: a reviewer sees only actions for tools they may review ────────────────────

test('the resolved view is tenant-scoped and RBAC-scoped — a reviewer never sees actions for tools they cannot review', function () {
    $tenant = rvTenant();
    $admin = rvAdmin($tenant);

    rvExecuted($tenant, $admin);       // demo.echo → requires ai.manage
    rvFenceRefused($tenant, $admin);   // comms.draft_reply → requires comms.manage

    // The ai.manage-only reviewer may review demo.echo but NOT comms.draft_reply → sees only the echo.
    $reviewer = rvAiOnly($tenant);
    rvCtx()->forget();
    $this->actingAs($reviewer)
        ->get(route('governance.approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('resolved', 1)
            ->where('resolved.0.status', 'executed')
            ->where('resolvedCounts.all', 1)
            ->where('resolvedCounts.executed', 1)
            ->where('resolvedCounts.fence_refused', 0) // the comms fence refusal is not theirs to see
            ->etc());

    // A different tenant's admin sees none of alpha's resolved actions (fail-closed tenancy).
    $beta = rvTenant('beta');
    rvCtx()->forget();
    $this->actingAs(rvAdmin($beta))
        ->get(route('governance.approvals.index'))
        ->assertInertia(fn (Assert $page) => $page->where('resolvedCounts.all', 0)->etc());
});
