<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\AiInteraction;
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
 * APPROVAL.P4 — edit-before-sending. A reviewer may edit the drafted payload before approving, but the
 * edit is NOT a bypass: an edited approve runs the SAME gate as an unedited one — it re-authorises the
 * reviewer against the tool's own permission, asserts the action is still pending, and re-runs the tool
 * (re-grounding/re-deriving from live state) on the edited payload. The edit changes the CONTENT the
 * human posts through the gate, never the gate itself; when present it is RECORDED as human-edited so an
 * edited post is distinguishable from an unedited approve. These tests prove the content posts, the gate
 * holds (unauthorized / non-pending edited approves are refused), the human-edited provenance is
 * recorded, RBAC is canReview-gated, and an edited CLINICAL draft still clears the full bar. They ADD
 * coverage; no existing behaviour test is modified.
 */

function apeCtx(): TenantContext
{
    return app(TenantContext::class);
}

function apeTenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    apeCtx()->set($tenant);

    return $tenant;
}

function apeAdmin(Tenant $tenant): User
{
    apeCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id]);

    return $user;
}

/** A user holding ai.manage (queue access) but NO tool permission (not appointment.manage / note.write). */
function apeAiOnlyUser(Tenant $tenant): User
{
    apeCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $role = Role::query()->create(['key' => 'ai_only', 'name' => 'AI Only', 'is_system' => false]);
    $role->permissions()->sync(Permission::query()->where('key', 'ai.manage')->pluck('id'));
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id]);

    return $user;
}

/** A pending demo-echo action (permission ai.manage; execute() echoes the payload's `message`). */
function apePropose(User $actor): AgentAction
{
    return app(ApprovalQueue::class)->propose('demo.echo', ['message' => 'original draft'], $actor, 'demo.echo', 'inbox', 'A demo no-op', 'approve');
}

function apeAudited(Tenant $tenant, string $action): bool
{
    return AuditEvent::query()->where('tenant_id', $tenant->id)->where('action', $action)->exists();
}

// ── The edited CONTENT posts through the gate, and is recorded as human-edited ──────────────────────

test('an edited approve posts the EDITED payload through the gate and is recorded as human-edited', function () {
    $tenant = apeTenant();
    $admin = apeAdmin($tenant); // org_admin holds ai.manage = the demo tool's permission

    $action = apePropose($admin);

    apeCtx()->forget();
    $this->actingAs($admin)
        ->post(route('governance.approvals.approve', $action->id), ['edited_payload' => ['message' => 'edited by the reviewer']])
        ->assertRedirect(route('governance.approvals.index'))
        ->assertSessionHas('status', 'approved_edited');

    $action->refresh();

    expect($action->status)->toBe(AgentAction::STATUS_EXECUTED)
        // The tool re-ran on the EDITED payload (re-ground), so the posted result carries the edit.
        ->and($action->result['message'] ?? null)->toBe('edited by the reviewer')
        // The edit is RECORDED on the action …
        ->and($action->edited_payload)->toBe(['message' => 'edited by the reviewer'])
        // … and the posted result is marked human-edited (beside the tool's own provenance).
        ->and($action->result['human_edited'] ?? null)->toBeTrue()
        ->and($action->reviewed_by)->toBe((string) $admin->id);

    // The append-only ledger records the human-edited marker on the executed interaction.
    apeCtx()->set($tenant);
    $executed = AiInteraction::query()->where('output_ref', $action->id)->where('outcome', 'executed')->firstOrFail();
    expect($executed->metadata['human_edited'] ?? null)->toBeTrue();
});

test('an UNEDITED approve carries no human-edited marker, so an edited post is distinguishable', function () {
    $tenant = apeTenant();
    $admin = apeAdmin($tenant);

    $action = apePropose($admin);

    apeCtx()->forget();
    $this->actingAs($admin)
        ->post(route('governance.approvals.approve', $action->id))
        ->assertRedirect(route('governance.approvals.index'))
        ->assertSessionHas('status', 'approved');

    $action->refresh();

    expect($action->status)->toBe(AgentAction::STATUS_EXECUTED)
        ->and($action->edited_payload)->toBeNull()
        ->and($action->result)->not->toHaveKey('human_edited')
        // Unedited posts the ORIGINAL content.
        ->and($action->result['message'] ?? null)->toBe('original draft');

    apeCtx()->set($tenant);
    $executed = AiInteraction::query()->where('output_ref', $action->id)->where('outcome', 'executed')->firstOrFail();
    expect($executed->metadata['human_edited'] ?? null)->toBeNull();
});

// ── THE GATE HOLDS: an edit does not skip re-authorise or assert-pending ────────────────────────────

test('an edited approve STILL re-authorises: a reviewer lacking the tool permission is refused (403), nothing runs', function () {
    $tenant = apeTenant();
    $admin = apeAdmin($tenant);

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

    // ai.manage reaches the queue but NOT appointment.manage — the edit path re-authorises against the
    // tool's OWN permission exactly as an unedited approve does, so this is denied and nothing runs.
    $reviewer = apeAiOnlyUser($tenant);
    apeCtx()->forget();
    $this->actingAs($reviewer)
        ->post(route('governance.approvals.approve', $action->id), ['edited_payload' => ['service_id' => 'z', 'branch_id' => 'y', 'date' => '2026-07-20']])
        ->assertForbidden();

    expect($action->refresh()->status)->toBe(AgentAction::STATUS_PENDING)
        ->and($action->result)->toBeNull()
        ->and($action->edited_payload)->toBeNull()
        ->and(apeAudited($tenant, 'agent_action.approved'))->toBeFalse();
});

test('an edited approve STILL asserts still-pending: editing an already-resolved action changes nothing', function () {
    $tenant = apeTenant();
    $admin = apeAdmin($tenant);

    $action = apePropose($admin);

    // First approve (unedited) → executed.
    apeCtx()->forget();
    $this->actingAs($admin)->post(route('governance.approvals.approve', $action->id))->assertRedirect();
    $action->refresh();
    expect($action->status)->toBe(AgentAction::STATUS_EXECUTED)
        ->and($action->result['message'] ?? null)->toBe('original draft');

    // A second, EDITED approve of the now-executed action is refused by assert-pending (AiCoreException),
    // surfaced as a form error; the executed result is NOT overwritten with the edit.
    apeCtx()->forget();
    $this->actingAs($admin)
        ->post(route('governance.approvals.approve', $action->id), ['edited_payload' => ['message' => 'a late edit']])
        ->assertSessionHasErrors('action');

    $action->refresh();
    expect($action->result['message'] ?? null)->toBe('original draft')
        ->and($action->edited_payload)->toBeNull()
        ->and($action->result)->not->toHaveKey('human_edited');
});

// ── An edited CLINICAL draft still clears the full individual gate ──────────────────────────────────

test('an edited CLINICAL draft still goes through the full gate — the edit does not lower the clinical bar', function () {
    $tenant = apeTenant();
    $admin = apeAdmin($tenant);

    // A pending action for a CLINICAL tool (clinical.summarize_since_last_visit → permission note.write).
    $action = AgentAction::query()->create([
        'interaction_id' => null,
        'feature' => 'clinical.summarize_since_last_visit',
        'agent' => 'clinical-summary-agent',
        'tool_key' => 'clinical.summarize_since_last_visit',
        'autonomy_level' => 'suggest',
        'status' => AgentAction::STATUS_PENDING,
        'proposed_by' => (string) $admin->id,
        'why' => 'clinical draft probe',
        'input_payload' => ['patient_id' => 'x'],
        'proposed_output' => ['handoff' => false, 'lines' => []],
    ]);

    // ai.manage does NOT confer note.write — an edited clinical approve is re-authorised against the
    // clinical tool's own permission and denied. Editing does not lower the clinical bar.
    $reviewer = apeAiOnlyUser($tenant);
    apeCtx()->forget();
    $this->actingAs($reviewer)
        ->post(route('governance.approvals.approve', $action->id), ['edited_payload' => ['patient_id' => 'x', 'lines' => [['text' => 'edited', 'source' => ['type' => 'note']]]]])
        ->assertForbidden();

    expect($action->refresh()->status)->toBe(AgentAction::STATUS_PENDING)
        ->and($action->result)->toBeNull()
        ->and($action->edited_payload)->toBeNull()
        ->and(apeAudited($tenant, 'agent_action.approved'))->toBeFalse();
});
