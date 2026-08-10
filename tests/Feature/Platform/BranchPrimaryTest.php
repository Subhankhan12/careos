<?php

use App\Services\BranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Models\AuditEvent;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * BRANCH.P2 — the per-tenant PRIMARY (default) branch (the wireframe's "main" tag) as a REAL,
 * invariant-guarded flag: EXACTLY ONE primary branch per tenant, ALWAYS. The first branch is
 * primary; set-primary moves it atomically (never zero/two); deactivating the primary reassigns to
 * another active branch (or, for the sole branch, keeps it); there is no way to zero-out the primary.
 * These tests ADD coverage; no existing behaviour test is modified.
 */

function bpTenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

function bpAdmin(Tenant $tenant): User
{
    app(TenantContext::class)->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id]);

    return $user;
}

function bpReception(Tenant $tenant): User
{
    app(TenantContext::class)->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', 'reception')->firstOrFail()->id]);

    return $user;
}

function bpBranch(array $overrides = []): Branch
{
    return app(BranchService::class)->create(array_merge([
        'name' => 'Branch '.uniqid(), 'code' => strtoupper(substr(uniqid(), -6)), 'timezone' => 'Europe/Zurich',
    ], $overrides));
}

/** Exactly one primary is the invariant we assert everywhere. */
function bpPrimaryCount(): int
{
    return Branch::query()->where('is_primary', true)->count();
}

// ── The first branch is primary; later branches are not (exactly one) ───────────────────────────────

test('a fresh tenant first branch is primary and later branches are not', function () {
    bpTenant();

    $first = bpBranch(['name' => 'First', 'code' => 'FIRST']);
    expect($first->is_primary)->toBeTrue()->and(bpPrimaryCount())->toBe(1);

    $second = bpBranch(['name' => 'Second', 'code' => 'SECOND']);
    expect($second->refresh()->is_primary)->toBeFalse()->and(bpPrimaryCount())->toBe(1);
});

test('the first-branch-is-primary invariant holds even for a DIRECT Branch::create (the seeder path)', function () {
    bpTenant('gamma');

    // Seeders create branches directly (Branch::query()->create), bypassing BranchService — the
    // model `creating` hook must still make the tenant's first branch primary.
    $first = Branch::query()->create(['name' => 'Seeded', 'code' => 'SEED', 'timezone' => 'UTC']);
    expect($first->is_primary)->toBeTrue()->and(bpPrimaryCount())->toBe(1);

    $second = Branch::query()->create(['name' => 'Seeded 2', 'code' => 'SEED2', 'timezone' => 'UTC']);
    expect($second->is_primary)->toBeFalse()->and(bpPrimaryCount())->toBe(1);
});

// ── Set-primary atomically moves it — never zero, never two ──────────────────────────────────────────

test('set-primary atomically moves the flag: exactly one primary before and after', function () {
    $tenant = bpTenant();
    $admin = bpAdmin($tenant);
    $first = bpBranch(['name' => 'First', 'code' => 'FIRST']);
    $second = bpBranch(['name' => 'Second', 'code' => 'SECOND']);

    expect($first->refresh()->is_primary)->toBeTrue()->and(bpPrimaryCount())->toBe(1);

    app(TenantContext::class)->forget();
    $this->actingAs($admin)
        ->post(route('admin.branches.primary', $second->id))
        ->assertRedirect(route('admin.branches.index'))
        ->assertSessionHas('status', 'primarySet');

    expect($second->refresh()->is_primary)->toBeTrue()
        ->and($first->refresh()->is_primary)->toBeFalse()   // old primary cleared
        ->and(bpPrimaryCount())->toBe(1);                    // still exactly one — never two
});

// ── You cannot set an INACTIVE branch primary (no way to orphan the flag) ────────────────────────────

test('an inactive branch cannot be made primary, and the primary is never left at zero', function () {
    $tenant = bpTenant();
    $admin = bpAdmin($tenant);
    $primary = bpBranch(['name' => 'First', 'code' => 'FIRST']);
    $other = bpBranch(['name' => 'Second', 'code' => 'SECOND']);

    // Deactivate the non-primary (allowed — no future appts) so we have an inactive candidate.
    app(BranchService::class)->setActive($other->refresh(), false);
    expect($other->refresh()->active)->toBeFalse();

    // Trying to make the INACTIVE branch primary is refused; the primary stays put.
    app(TenantContext::class)->forget();
    $this->actingAs($admin)
        ->post(route('admin.branches.primary', $other->id))
        ->assertSessionHasErrors('branch');

    expect($primary->refresh()->is_primary)->toBeTrue()
        ->and($other->refresh()->is_primary)->toBeFalse()
        ->and(bpPrimaryCount())->toBe(1);
});

// ── Deactivating the primary reassigns to another active branch (never zero) ─────────────────────────

test('deactivating the primary reassigns primary to another active branch', function () {
    $tenant = bpTenant();
    $admin = bpAdmin($tenant);
    $first = bpBranch(['name' => 'First', 'code' => 'FIRST']);   // primary
    $second = bpBranch(['name' => 'Second', 'code' => 'SECOND']); // active successor

    app(TenantContext::class)->forget();
    $this->actingAs($admin)
        ->post(route('admin.branches.deactivate', $first->id))
        ->assertRedirect(route('admin.branches.index'))
        ->assertSessionHas('status', 'deactivated');

    expect($first->refresh()->active)->toBeFalse()
        ->and($first->is_primary)->toBeFalse()               // moved off the deactivated branch
        ->and($second->refresh()->is_primary)->toBeTrue()    // reassigned to the active branch
        ->and(bpPrimaryCount())->toBe(1);                    // never zero
});

// ── The single-branch case: always primary; can't be zeroed even when deactivated ───────────────────

test('the sole branch is always primary and keeps primary even if deactivated (never zero)', function () {
    $tenant = bpTenant();
    $admin = bpAdmin($tenant);
    $only = bpBranch(['name' => 'Only', 'code' => 'ONLY']);
    expect($only->is_primary)->toBeTrue();

    // Deactivate the sole branch (no future appts → the P1 hard guard allows it). No active
    // successor exists, so it KEEPS primary — the tenant still has exactly one primary.
    app(TenantContext::class)->forget();
    $this->actingAs($admin)->post(route('admin.branches.deactivate', $only->id))->assertRedirect();

    expect($only->refresh()->active)->toBeFalse()
        ->and($only->is_primary)->toBeTrue()
        ->and(bpPrimaryCount())->toBe(1);
});

// ── ensurePrimary (the runtime mirror of the migration backfill) → exactly one ──────────────────────

test('ensurePrimary establishes exactly one primary from a no-primary or multi-primary state (the backfill logic)', function () {
    bpTenant();
    $first = bpBranch(['name' => 'First', 'code' => 'FIRST']);
    $second = bpBranch(['name' => 'Second', 'code' => 'SECOND']);

    // Simulate a pre-migration state: NO primary anywhere.
    DB::table('branches')->update(['is_primary' => false]);
    expect(bpPrimaryCount())->toBe(0);

    app(BranchService::class)->ensurePrimary();
    expect(bpPrimaryCount())->toBe(1)
        ->and($first->refresh()->is_primary)->toBeTrue();   // earliest active promoted

    // Simulate a corrupt multi-primary state: normalise back to exactly one (the earliest).
    DB::table('branches')->update(['is_primary' => true]);
    expect(bpPrimaryCount())->toBe(2);
    app(BranchService::class)->ensurePrimary();
    expect(bpPrimaryCount())->toBe(1)->and($first->refresh()->is_primary)->toBeTrue();
});

// ── RBAC + tenant-scoped + audited ──────────────────────────────────────────────────────────────────

test('set-primary is admin.manage-gated, tenant-scoped, and audited', function () {
    $tenant = bpTenant();
    $admin = bpAdmin($tenant);
    $first = bpBranch(['name' => 'First', 'code' => 'FIRST']);
    $second = bpBranch(['name' => 'Second', 'code' => 'SECOND']);

    // RBAC: reception (no admin.manage) is denied; the primary is unchanged.
    $reception = bpReception($tenant);
    app(TenantContext::class)->forget();
    $this->actingAs($reception)->post(route('admin.branches.primary', $second->id))->assertForbidden();
    expect($second->refresh()->is_primary)->toBeFalse();

    // admin.manage → allowed + audited distinctly.
    app(TenantContext::class)->forget();
    $this->actingAs($admin)->post(route('admin.branches.primary', $second->id))->assertRedirect();
    expect(AuditEvent::query()->where('tenant_id', $tenant->id)->where('action', 'branch.primary_set')->exists())->toBeTrue();

    // Tenant-scoped: another tenant's admin cannot resolve this branch (404).
    $beta = bpTenant('beta');
    $betaAdmin = bpAdmin($beta);
    app(TenantContext::class)->forget();
    $this->actingAs($betaAdmin)->post(route('admin.branches.primary', $first->id))->assertNotFound();
});
