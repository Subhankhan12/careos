<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AiCore\Models\AgentAction;
use Modules\Comms\Models\Thread;
use Modules\Platform\Models\Permission;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * QA-FIX.9a — `P10-C3`: the single-item agent approve route is gated, and every caller of
 * ApprovalQueue::approve() is pinned.
 *
 * The defect: POST /comms/inbox/send-draft called ApprovalQueue::approve() with no permission check at
 * all and resolved the action with a bare whereKey(), so it reached the whole agent_actions table. A
 * clinician holding only the TOOL's permission executed a CLINICAL action through it — the category
 * bulkApprove refuses server-side.
 *
 * These tests ADD coverage. No existing behaviour test is modified: the end-to-end send in
 * tests/Feature/AiCore/InboxAgentTest.php acts as org_admin, which holds comms.manage, and still passes.
 */

function iagCtx(): TenantContext
{
    return app(TenantContext::class);
}

function iagTenant(): Tenant
{
    $tenant = Tenant::query()->create(['name' => 'Inbox Gate Care', 'slug' => 'inbox-gate', 'region' => 'eu', 'status' => 'active']);
    iagCtx()->set($tenant);

    return $tenant;
}

/** A user holding exactly the permissions named — never a system template, so the set is exact. */
function iagUserWith(Tenant $tenant, string $key, array $permissions): User
{
    iagCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $role = Role::query()->create(['key' => $key, 'name' => ucfirst($key), 'is_system' => false]);
    $role->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id'));
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id]);

    return $user;
}

/** A real thread, so a draft that passes the gates reaches the TOOL rather than a missing-model 404. */
function iagThread(Tenant $tenant, User $actor): Thread
{
    iagCtx()->set($tenant);

    return Thread::query()->create([
        'subject' => 'Gate probe',
        'type' => Thread::TYPE_INTERNAL,
        'status' => Thread::STATUS_OPEN,
        'created_by' => (string) $actor->id,
    ]);
}

/** A pending action for any tool key, placed directly so the category under test is exact. */
function iagPending(Tenant $tenant, User $actor, string $toolKey, ?string $threadId = null): AgentAction
{
    iagCtx()->set($tenant);

    return AgentAction::query()->create([
        'interaction_id' => null,
        'feature' => $toolKey,
        'agent' => 'inbox',
        'tool_key' => $toolKey,
        'autonomy_level' => 'suggest',
        'status' => AgentAction::STATUS_PENDING,
        'proposed_by' => (string) $actor->id,
        'why' => 'gate probe',
        'input_payload' => ['thread_id' => $threadId ?? 'thr_probe', 'recall_id' => 'rec_probe', 'template' => 'hello'],
        'proposed_output' => ['handoff' => true],
    ]);
}

/** The controller sources that call ApprovalQueue::approve(), with comments removed. */
function iagStrippedControllers(): array
{
    $files = [
        base_path('app/Http/Controllers/Comms/InboxAgentController.php'),
        base_path('app/Http/Controllers/AiApprovalQueueController.php'),
    ];

    $out = [];
    foreach ($files as $file) {
        $source = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $source .= $token[1];

                continue;
            }
            $source .= $token;
        }
        $out[$file] = $source;
    }

    return $out;
}

it('refuses the single-item send-draft route to a user without the surface permission', function () {
    $tenant = iagTenant();
    $owner = iagUserWith($tenant, 'iag_owner', ['comms.manage']);
    $outsider = iagUserWith($tenant, 'iag_outsider', ['note.write']); // holds a TOOL permission, not the surface's
    $action = iagPending($tenant, $owner, 'comms.draft_reply');

    $this->actingAs($outsider)
        ->post(route('comms.inbox.send-draft'), ['action_id' => $action->id])
        ->assertForbidden();

    // The refusal must leave the action untouched.
    expect($action->fresh()->status)->toBe(AgentAction::STATUS_PENDING)
        ->and($action->fresh()->reviewed_by)->toBeNull();

    // WHICH LAYER BITES, established by mutation rather than assumed — the two are complementary and
    // neither is decoration:
    //   - THE ROUTE GATE catches someone with no business on this surface at all. That is the P10-C3
    //     attacker: a doctor holds note.write and NOT comms.manage, so posting a clinical action id
    //     here is 403 before the lookup runs. Driven in the browser after the fix.
    //   - THE SCOPE catches a LEGITIMATE surface user reaching outside their surface — reception holds
    //     comms.manage, passes the gate, and is still refused a clinical id (404). The gate cannot see
    //     that case, which is why the scope is what actually closes the finding.
    // In THIS test the two overlap: the outsider holds only note.write, and comms.draft_reply's tool
    // permission is comms.manage, so the service would refuse them too — removing the route gate leaves
    // this one test green. That is stated rather than hidden (D-182): the non-vacuous proofs of the gate
    // are the structural test, and of the scope the three tests below.
});

it('still lets the surface owner send, with no ai.manage anywhere in sight', function () {
    $tenant = iagTenant();
    $owner = iagUserWith($tenant, 'iag_owner', ['comms.manage']);
    $thread = iagThread($tenant, $owner);
    $action = iagPending($tenant, $owner, 'comms.draft_reply', $thread->id);

    // THE POSITIVE CONTROL (D-174): the fix must not turn the inbox into an org_admin-only surface.
    // Not 403 proves the route gate let this user through; not 404 proves the scoped lookup matched
    // their own kind of draft; and the ledger's `approved` row is written by the service AFTER both
    // gates, so its presence is proof of passage whatever the tool then does with the draft. Not 500
    // because this route used to answer a refusal by crashing.
    $response = $this->actingAs($owner)->post(route('comms.inbox.send-draft'), ['action_id' => $action->id]);

    $reachedTheService = DB::table('ai_interactions')
        ->where('output_ref', $action->id)
        ->where('outcome', 'approved')
        ->exists();

    expect($response->status())->not->toBe(403)
        ->and($response->status())->not->toBe(404)
        ->and($response->status())->not->toBe(500)
        ->and($reachedTheService)->toBeTrue();
});

it('does not reach a clinical action from the inbox surface', function () {
    $tenant = iagTenant();
    $owner = iagUserWith($tenant, 'iag_owner', ['comms.manage', 'note.write']); // holds the clinical tool's permission too
    $clinical = iagPending($tenant, $owner, 'clinical.draft_recall_message');

    // BEFORE THE FIX this executed: the route resolved any id and the service only checked note.write.
    $this->actingAs($owner)
        ->post(route('comms.inbox.send-draft'), ['action_id' => $clinical->id])
        ->assertNotFound();

    expect($clinical->fresh()->status)->toBe(AgentAction::STATUS_PENDING)
        ->and($clinical->fresh()->reviewed_by)->toBeNull();
});

it('does not reach a financial action from the inbox surface', function () {
    $tenant = iagTenant();
    $owner = iagUserWith($tenant, 'iag_owner', ['comms.manage', 'billing.manage']);
    $financial = iagPending($tenant, $owner, 'billing.preflight_invoice');

    $this->actingAs($owner)
        ->post(route('comms.inbox.send-draft'), ['action_id' => $financial->id])
        ->assertNotFound();

    expect($financial->fresh()->status)->toBe(AgentAction::STATUS_PENDING);
});

it('does not reach an action that is no longer pending', function () {
    $tenant = iagTenant();
    $owner = iagUserWith($tenant, 'iag_owner', ['comms.manage']);
    $action = iagPending($tenant, $owner, 'comms.draft_reply');
    $action->forceFill(['status' => AgentAction::STATUS_REJECTED])->save();

    // A second Send used to be an unhandled AiCoreException, i.e. a 500.
    $this->actingAs($owner)
        ->post(route('comms.inbox.send-draft'), ['action_id' => $action->id])
        ->assertNotFound();
});

it('refuses the draft route to a user without the surface permission', function () {
    $tenant = iagTenant();
    $outsider = iagUserWith($tenant, 'iag_outsider', ['note.write']);

    $this->actingAs($outsider)
        ->post(route('comms.inbox.ai-draft'), ['thread_id' => 'thr_probe'])
        ->assertForbidden();

    expect(AgentAction::query()->count())->toBe(0);
});

it('pins that every controller method calling ApprovalQueue::approve() carries an authorization gate', function () {
    $checked = 0;

    foreach (iagStrippedControllers() as $file => $source) {
        // Split on method boundaries; each chunk is one method body in the stripped source.
        $chunks = preg_split('/(?=public function )/', $source) ?: [];

        foreach ($chunks as $chunk) {
            if (! str_contains($chunk, 'ApprovalQueue::class)->approve(') && ! str_contains($chunk, '$queue->approve(')) {
                continue;
            }

            $checked++;
            // assertStringContainsString, not expect()->toContain(): Pest treats every extra argument
            // to toContain() as ANOTHER NEEDLE, so a message passed there is asserted as content.
            $this->assertStringContainsString(
                'Gate::authorize(',
                $chunk,
                basename($file).': a method calling ApprovalQueue::approve() has no Gate::authorize() — this is the P10-C3 shape.'
            );
        }
    }

    // The guard must not pass by finding nothing (D-174): three call sites exist today —
    // the queue's single approve, the queue's bulk loop, and the inbox send-draft.
    expect($checked)->toBe(3);
});
