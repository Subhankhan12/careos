<?php

use App\Services\BranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * STEP-1 / QF13c-M2 — the dispatch board must authorise before resolving a branch.
 *
 * The two unauthorised requests below are intentionally asymmetric in their fixtures: one tenant has
 * an active branch and one has none. Before this fix, the first made it to Gate and returned 403 while
 * the second failed `firstOrFail()` with 404. A caller who did not hold dispatch.manage could therefore
 * enumerate whether a tenant had a branch. D-185 requires the *response shape*, not just its words, to
 * be indistinguishable.
 *
 * Helpers are prefixed qf13c because Pest declares test-file functions globally.
 */

function qf13cContext(): TenantContext
{
    return app(TenantContext::class);
}

function qf13cTenant(string $slug): Tenant
{
    return Tenant::query()->create([
        'name' => 'Dispatch '.$slug,
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function qf13cUser(Tenant $tenant, string $role): User
{
    qf13cContext()->set($tenant);

    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $roleModel = Role::query()->where('key', $role)->firstOrFail();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => $roleModel->id,
    ]);

    return $user;
}

function qf13cBranch(Tenant $tenant, string $code): Branch
{
    qf13cContext()->set($tenant);

    return app(BranchService::class)->create([
        'name' => 'Dispatch '.$code,
        'code' => $code,
    ]);
}

it('gives an unauthorised nurse the identical response whether a tenant has an active branch or none', function () {
    $withBranch = qf13cTenant('dispatch-with-branch');
    $withBranchNurse = qf13cUser($withBranch, 'nurse');
    qf13cBranch($withBranch, 'WITH');

    $withoutBranch = qf13cTenant('dispatch-without-branch');
    $withoutBranchNurse = qf13cUser($withoutBranch, 'nurse');

    qf13cContext()->set($withBranch);
    $hasBranch = $this->actingAs($withBranchNurse)->get('/nursing/dispatch');
    $hasBranch->assertForbidden();

    qf13cContext()->set($withoutBranch);
    $hasNoBranch = $this->actingAs($withoutBranchNurse)->get('/nursing/dispatch');
    $hasNoBranch->assertForbidden();

    // D-185: 403 + a different error body would still be an enumeration oracle.
    expect($hasNoBranch->getContent())->toBe($hasBranch->getContent());
});

it('selects only an active branch when the fixture contains both active and inactive branches', function () {
    $tenant = qf13cTenant('dispatch-active-only');
    $coordinator = qf13cUser($tenant, 'coordinator');
    // Ordering is deliberately hostile: without the active scope, first() would choose the closed site.
    $inactive = qf13cBranch($tenant, 'AAA-CLOSED');
    $active = qf13cBranch($tenant, 'ZZZ-LIVE');
    app(BranchService::class)->setActive($inactive, false);

    qf13cContext()->set($tenant);
    $response = $this->actingAs($coordinator)->get('/nursing/dispatch');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Nursing/Dispatch')
        ->where('filters.branch_id', $active->id)
        ->has('branches', 1)
        ->where('branches.0.id', $active->id)
    );

    $branchIds = collect($response->viewData('page')['props']['branches'])->pluck('id')->all();
    expect($branchIds)->toBe([$active->id])
        ->and($branchIds)->not->toContain($inactive->id);
});

it('renders an honest empty dispatch payload after a mature tenant deactivates its only branch', function () {
    $tenant = qf13cTenant('dispatch-mature-empty');
    $coordinator = qf13cUser($tenant, 'coordinator');
    $onlyBranch = qf13cBranch($tenant, 'ONLY');
    app(BranchService::class)->setActive($onlyBranch, false);

    qf13cContext()->set($tenant);
    $this->actingAs($coordinator)
        ->get('/nursing/dispatch')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Nursing/Dispatch')
            ->where('filters.branch_id', null)
            ->where('branches', [])
            ->where('unassignedVisits', [])
            ->where('nurseLanes', [])
            ->where('actions.assignUrl', route('nursing.dispatch.assign'))
            ->where('actions.unassignUrl', route('nursing.dispatch.unassign'))
        );
});

it('keeps the populated dispatch board and action endpoints for a coordinator with an active branch', function () {
    $tenant = qf13cTenant('dispatch-populated-control');
    $coordinator = qf13cUser($tenant, 'coordinator');
    $branch = qf13cBranch($tenant, 'LIVE');

    qf13cContext()->set($tenant);
    $response = $this->actingAs($coordinator)->get('/nursing/dispatch');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Nursing/Dispatch')
        ->where('filters.branch_id', $branch->id)
        ->has('branches', 1)
        ->where('actions.assignUrl', route('nursing.dispatch.assign'))
        ->where('actions.unassignUrl', route('nursing.dispatch.unassign'))
    );
});
