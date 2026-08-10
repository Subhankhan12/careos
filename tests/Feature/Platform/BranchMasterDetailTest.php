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
 * BRANCH.P4 — the master-detail re-skin is PURELY presentational over the P1–P3 backend + W8b/W8c.
 * These light tests assert the page still delivers the data contract the master-detail renders (the
 * branch list + the 4 detail cards) with every control's real endpoint URL, and that the re-skinned
 * controls still route to the existing, unchanged endpoints (P1 soft-suspend, P2 set-primary). They
 * add coverage; the full P1/P2/P3 behaviour + guards remain locked by their own suites (unchanged).
 * Per the UI rule these assert props/behaviour, never markup.
 */

function mdTenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

function mdAdmin(Tenant $tenant): User
{
    app(TenantContext::class)->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id]);

    return $user;
}

function mdBranch(string $code): Branch
{
    return Branch::create(['name' => $code.' Branch', 'code' => $code, 'timezone' => 'Europe/Zurich']);
}

// ── The page delivers the master-detail data contract (list + 4 detail cards + real URLs) ───────────

test('the branches page renders the master-detail data contract with every action URL', function () {
    $tenant = mdTenant();
    $admin = mdAdmin($tenant);
    $branch = mdBranch('MAIN'); // first branch → primary (P2)
    BookableResource::create(['type' => BookableResource::TYPE_PRACTITIONER, 'name' => 'Dr R', 'branch_id' => $branch->id, 'active' => true]);
    BookableResource::create(['type' => BookableResource::TYPE_ROOM, 'name' => 'Room 1', 'branch_id' => $branch->id, 'active' => true]);

    app(TenantContext::class)->forget();
    $this->actingAs($admin)
        ->get(route('admin.branches.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Branches')
            // Left list + profile card data: P1 (phone/accepts_online_bookings) + P2 (is_primary) + structured fields.
            ->where('branches.0.is_primary', true)
            ->where('branches.0.accepts_online_bookings', true)
            ->has('branches.0.phone')
            ->has('branches.0.code')
            ->has('branches.0.timezone')
            // Hours card + resources card data.
            ->has('branches.0.hours', 7)
            ->has('branches.0.resources', 2)
            // Every re-skinned control wires to a real endpoint URL.
            ->has('branches.0.updateUrl')
            ->has('branches.0.hoursUrl')
            ->has('branches.0.onlineBookingsUrl')     // P1 soft-suspend
            ->has('branches.0.setPrimaryUrl')          // P2
            ->has('branches.0.deactivateUrl')          // hard deactivate (distinct)
            ->has('branches.0.resourceStoreUrl')
            // Practitioner read-only (P3): the roster carries a practitioner + the select is facility-only.
            ->where('branches.0.resources', fn ($r) => collect($r)->contains(fn ($x) => $x['type'] === 'practitioner'))
            ->where('resourceTypes', ['room', 'chair', 'vehicle'])
            ->etc());
});

// ── The re-skinned profile card save routes to the real endpoint ────────────────────────────────────

test('the profile card save (name + phone) routes to the existing update endpoint and persists', function () {
    $tenant = mdTenant();
    $admin = mdAdmin($tenant);
    $branch = mdBranch('MAIN');

    app(TenantContext::class)->forget();
    $this->actingAs($admin)
        ->post(route('admin.branches.update', $branch->id), [
            'name' => 'Main Clinic', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich', 'phone' => '+41 44 000 00 00',
        ])
        ->assertRedirect(route('admin.branches.index'));

    expect($branch->refresh()->name)->toBe('Main Clinic')->and($branch->phone)->toBe('+41 44 000 00 00');
});

// ── P1 soft-suspend + P2 set-primary still work through the re-skinned URLs ──────────────────────────

test('the re-skinned soft-suspend and set-primary controls still route to the unchanged endpoints', function () {
    $tenant = mdTenant();
    $admin = mdAdmin($tenant);
    $first = mdBranch('MAIN');    // primary
    $second = mdBranch('ALT');    // active successor

    app(TenantContext::class)->forget();
    // P1 soft-suspend (the danger card's soft action) — keeps the branch active.
    $this->actingAs($admin)->post(route('admin.branches.online_bookings', $first->id), ['accepts_online_bookings' => false])->assertRedirect();
    expect($first->refresh()->accepts_online_bookings)->toBeFalse()->and($first->active)->toBeTrue();

    // P2 set-primary (the profile card / list action) — atomic move, exactly one primary.
    app(TenantContext::class)->forget();
    $this->actingAs($admin)->post(route('admin.branches.primary', $second->id))->assertRedirect();
    expect($second->refresh()->is_primary)->toBeTrue()
        ->and($first->refresh()->is_primary)->toBeFalse()
        ->and(Branch::query()->where('is_primary', true)->count())->toBe(1);
});
