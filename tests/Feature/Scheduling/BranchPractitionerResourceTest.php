<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource as BookableResource;

uses(RefreshDatabase::class);

/*
 * BRANCH.P3 — practitioner-resource type correctness. A practitioner resource is person-backed; its
 * TYPE is what it IS and cannot be edited via the admin room/chair/vehicle select (which can't even
 * represent a practitioner). This is a FIX (stop an invalid edit), NOT a trim: the type is read-only
 * for practitioners (UI + server-enforced), but name/status stay editable, and facility-resource CRUD
 * (room/chair/vehicle) is unchanged. These tests ADD coverage; no existing behaviour test is modified.
 */

function prTenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

function prUser(Tenant $tenant, string $role): User
{
    app(TenantContext::class)->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

function prBranch(): Branch
{
    return Branch::create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
}

/** A practitioner (person-backed) resource. staff_profile_id null keeps the fixture light and PROVES
 * the controller-level fix works on its own (the model's staff-profile guard is not what blocks it). */
function prPractitioner(Branch $branch): BookableResource
{
    return BookableResource::create(['type' => BookableResource::TYPE_PRACTITIONER, 'name' => 'Dr M. Brunner', 'branch_id' => $branch->id, 'active' => true]);
}

function prRoom(Branch $branch): BookableResource
{
    return BookableResource::create(['type' => BookableResource::TYPE_ROOM, 'name' => 'Room 1', 'branch_id' => $branch->id, 'active' => true]);
}

// ── The payload marks practitioner rows so the UI renders type read-only ─────────────────────────────

test('the branches payload exposes the practitioner type so the UI can render it read-only', function () {
    $tenant = prTenant();
    $admin = prUser($tenant, 'org_admin');
    $branch = prBranch();
    prPractitioner($branch);
    prRoom($branch);

    app(TenantContext::class)->forget();
    $this->actingAs($admin)
        ->get(route('admin.branches.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Branches')
            ->where('branches.0.resources', fn ($resources) => collect($resources)->contains(fn ($r) => $r['type'] === 'practitioner')
                && collect($resources)->contains(fn ($r) => $r['type'] === 'room'))
            // The type select the UI offers is facility-only — it can't represent a practitioner.
            ->where('resourceTypes', ['room', 'chair', 'vehicle'])
            ->etc());
});

// ── THE FIX: a practitioner's type cannot be edited to a facility type (server-enforced) ─────────────

test('the server ignores a request to retype a practitioner to a facility type — the practitioner stays a practitioner', function () {
    $tenant = prTenant();
    $admin = prUser($tenant, 'org_admin');
    $branch = prBranch();
    $practitioner = prPractitioner($branch);

    // A forged update tries to turn the practitioner into a room AND rename it.
    app(TenantContext::class)->forget();
    $this->actingAs($admin)
        ->post(route('admin.resources.update', $practitioner->id), ['name' => 'Dr M. Brunner (renamed)', 'type' => 'room'])
        ->assertRedirect(route('admin.branches.index'));

    $practitioner->refresh();
    expect($practitioner->type)->toBe(BookableResource::TYPE_PRACTITIONER) // type IGNORED — stays practitioner
        ->and($practitioner->name)->toBe('Dr M. Brunner (renamed)');       // name IS editable
});

// ── A facility resource keeps its editable type select (unchanged — no trim) ─────────────────────────

test('a facility resource keeps its editable type (room can become chair) — facility CRUD unchanged', function () {
    $tenant = prTenant();
    $admin = prUser($tenant, 'org_admin');
    $branch = prBranch();
    $room = prRoom($branch);

    app(TenantContext::class)->forget();
    $this->actingAs($admin)
        ->post(route('admin.resources.update', $room->id), ['name' => 'Treatment Room', 'type' => 'chair'])
        ->assertRedirect(route('admin.branches.index'));

    expect($room->refresh()->type)->toBe(BookableResource::TYPE_CHAIR)
        ->and($room->name)->toBe('Treatment Room');
});

// ── The reverse is blocked too: a facility cannot be retyped to practitioner ─────────────────────────

test('a facility resource cannot be retyped to practitioner (validation refuses the non-facility type)', function () {
    $tenant = prTenant();
    $admin = prUser($tenant, 'org_admin');
    $branch = prBranch();
    $room = prRoom($branch);

    app(TenantContext::class)->forget();
    $this->actingAs($admin)
        ->post(route('admin.resources.update', $room->id), ['name' => 'Room 1', 'type' => 'practitioner'])
        ->assertSessionHasErrors('type');

    expect($room->refresh()->type)->toBe(BookableResource::TYPE_ROOM); // unchanged
});

// ── Other practitioner fields stay editable — it's a FIX, not a trim ─────────────────────────────────

test('practitioner status stays editable — deactivate/activate still work (no functionality removed)', function () {
    $tenant = prTenant();
    $admin = prUser($tenant, 'org_admin');
    $branch = prBranch();
    $practitioner = prPractitioner($branch); // no future appointments

    app(TenantContext::class)->forget();
    $this->actingAs($admin)->post(route('admin.resources.deactivate', $practitioner->id))->assertRedirect();
    expect($practitioner->refresh()->active)->toBeFalse();

    app(TenantContext::class)->forget();
    $this->actingAs($admin)->post(route('admin.resources.activate', $practitioner->id))->assertRedirect();
    expect($practitioner->refresh()->active)->toBeTrue();
});

// ── RBAC + tenant-scoped ─────────────────────────────────────────────────────────────────────────────

test('resource update is admin.manage-gated and tenant-scoped', function () {
    $tenant = prTenant();
    $branch = prBranch();
    $practitioner = prPractitioner($branch);

    // reception (no admin.manage) is denied.
    $reception = prUser($tenant, 'reception');
    app(TenantContext::class)->forget();
    $this->actingAs($reception)->post(route('admin.resources.update', $practitioner->id), ['name' => 'X'])->assertForbidden();

    // Another tenant's admin cannot resolve this resource (404).
    $beta = prTenant('beta');
    $betaAdmin = prUser($beta, 'org_admin');
    app(TenantContext::class)->forget();
    $this->actingAs($betaAdmin)->post(route('admin.resources.update', $practitioner->id), ['name' => 'X'])->assertNotFound();

    expect($practitioner->refresh()->name)->toBe('Dr M. Brunner'); // unchanged by either attempt
});
