<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Permission;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\RbacProvisioner;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function rbacTenant(string $slug): Tenant
{
    // Creating a tenant seeds its starter roles (Tenant::created hook).
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function rbacCtx(): TenantContext
{
    return app(TenantContext::class);
}

/** Fetch a seeded starter role in the current tenant context. */
function role(string $key): Role
{
    return Role::where('key', $key)->firstOrFail();
}

// (a) permission granted through a role, denied without it -------------------

test('a user with a role granting patient.view can, and cannot what the role omits', function () {
    $tenant = rbacTenant('alpha');
    rbacCtx()->set($tenant);

    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => role('doctor')->id]);

    expect($user->hasPermission('patient.view'))->toBeTrue()
        ->and($user->can('patient.view'))->toBeTrue()
        ->and($user->hasPermission('billing.view'))->toBeFalse()
        ->and($user->can('billing.view'))->toBeFalse();
});

test('a user with no role assignment holds no permissions', function () {
    $tenant = rbacTenant('alpha');
    rbacCtx()->set($tenant);

    $user = User::factory()->forTenant($tenant)->create();

    expect($user->hasPermission('patient.view'))->toBeFalse();
});

// (b) branch scope narrows access --------------------------------------------

test('a branch-scoped assignment grants on that branch and denies on another', function () {
    $tenant = rbacTenant('alpha');
    rbacCtx()->set($tenant);

    $user = User::factory()->forTenant($tenant)->create();
    $branchX = Branch::create(['name' => 'X', 'code' => 'X']);
    $branchY = Branch::create(['name' => 'Y', 'code' => 'Y']);

    RoleAssignment::create([
        'user_id' => $user->id,
        'role_id' => role('doctor')->id,
        'branch_id' => $branchX->id,
    ]);

    expect($user->hasPermission('patient.view', $branchX->id))->toBeTrue()
        ->and($user->can('patient.view', ['branch_id' => $branchX->id]))->toBeTrue()
        ->and($user->hasPermission('patient.view', $branchY->id))->toBeFalse()
        ->and($user->can('patient.view', ['branch_id' => $branchY->id]))->toBeFalse()
        // No branch context: a branch-scoped grant does not apply globally.
        ->and($user->hasPermission('patient.view'))->toBeFalse();
});

test('an all-branches assignment grants regardless of the branch asked about', function () {
    $tenant = rbacTenant('alpha');
    rbacCtx()->set($tenant);

    $user = User::factory()->forTenant($tenant)->create();
    $branch = Branch::create(['name' => 'X', 'code' => 'X']);

    RoleAssignment::create(['user_id' => $user->id, 'role_id' => role('doctor')->id]); // branch_id null

    expect($user->hasPermission('patient.view'))->toBeTrue()
        ->and($user->hasPermission('patient.view', $branch->id))->toBeTrue();
});

// (c) super-admin bypass ------------------------------------------------------

test('a super-admin bypasses all checks via Gate::before', function () {
    $admin = User::factory()->create(); // tenant_id null

    expect($admin->isSuperAdmin())->toBeTrue()
        ->and($admin->hasPermission('anything.at.all'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('billing.view'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('patient.edit', ['branch_id' => 'nonexistent']))->toBeTrue();
});

test('a tenant user is NOT bypassed and is denied an unheld permission', function () {
    $tenant = rbacTenant('alpha');
    rbacCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->create();

    expect(Gate::forUser($user)->allows('admin.manage'))->toBeFalse();
});

// (d) tenant isolation --------------------------------------------------------

test('a role assignment in tenant A never grants access in tenant B', function () {
    $a = rbacTenant('alpha');
    $b = rbacTenant('beta');

    rbacCtx()->set($a);
    $userA = User::factory()->forTenant($a)->create();
    RoleAssignment::create(['user_id' => $userA->id, 'role_id' => role('doctor')->id]);

    // In A the grant holds.
    expect($userA->hasPermission('patient.view'))->toBeTrue();

    // Under tenant B's context, A's assignment is invisible.
    rbacCtx()->set($b);
    expect($userA->hasPermission('patient.view'))->toBeFalse();

    // A user in B with no assignment holds nothing.
    $userB = User::factory()->forTenant($b)->create();
    expect($userB->hasPermission('patient.view'))->toBeFalse();
});

// (e) fail-closed tenancy on RBAC tables -------------------------------------

test('RBAC tenant-owned tables throw without a tenant context', function () {
    rbacTenant('alpha');
    rbacCtx()->forget();

    expect(fn () => Role::query()->get())->toThrow(TenantContextMissingException::class)
        ->and(fn () => RoleAssignment::query()->get())->toThrow(TenantContextMissingException::class);
});

test('the platform permission catalog is readable without any tenant context', function () {
    rbacTenant('alpha'); // triggers catalog sync
    rbacCtx()->forget();

    expect(Permission::count())->toBe(count(RbacProvisioner::PERMISSIONS));
});
