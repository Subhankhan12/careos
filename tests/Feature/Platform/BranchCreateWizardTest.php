<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * BRANCH.P5 — the create-branch 3-step wizard is PURELY a UX flow over the EXISTING store endpoint.
 * The wizard's final submit posts the SAME payload to the SAME `admin.branches.store` route, whose
 * validation is unchanged and authoritative: the unique-Code rule and the required fields still
 * refuse bad input server-side, and the P2 primary invariant on create is intact (a non-first branch
 * is created non-primary). These tests exercise that submit path; they ADD coverage and modify no
 * existing behaviour test.
 */

function cwTenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

function cwUser(Tenant $tenant, string $role): User
{
    app(TenantContext::class)->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** The full payload the wizard collects across its 3 steps. */
function cwPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Zürich Wiedikon',
        'code' => 'ZH-WDK',
        'phone' => '+41 44 111 22 33',
        'address_line1' => 'Birmensdorferstrasse 210',
        'city' => 'Zürich',
        'postal_code' => '8003',
        'country' => 'CH',
        'timezone' => 'Europe/Zurich',
    ], $overrides);
}

// ── The wizard's final submit creates the branch via the existing store endpoint ─────────────────────

test('the wizard final submit creates the branch through the existing store endpoint with all fields persisted', function () {
    $tenant = cwTenant();
    $admin = cwUser($tenant, 'org_admin');

    app(TenantContext::class)->forget();
    $this->actingAs($admin)
        ->post(route('admin.branches.store'), cwPayload())
        ->assertRedirect(route('admin.branches.index'))
        ->assertSessionHas('status', 'created');

    app(TenantContext::class)->set($tenant);
    $branch = Branch::query()->where('code', 'ZH-WDK')->firstOrFail();
    expect($branch->name)->toBe('Zürich Wiedikon')
        ->and($branch->phone)->toBe('+41 44 111 22 33')
        ->and($branch->address_line1)->toBe('Birmensdorferstrasse 210')
        ->and($branch->city)->toBe('Zürich')
        ->and($branch->postal_code)->toBe('8003')
        ->and($branch->country)->toBe('CH')
        ->and($branch->timezone)->toBe('Europe/Zurich');
});

// ── The unique-Code rule is UNCHANGED + authoritative (surfaced in the wizard) ───────────────────────

test('a duplicate Code is refused server-side (the unique rule is unchanged)', function () {
    $tenant = cwTenant();
    $admin = cwUser($tenant, 'org_admin');

    app(TenantContext::class)->forget();
    $this->actingAs($admin)->post(route('admin.branches.store'), cwPayload(['code' => 'DUP']))->assertRedirect();

    // A second branch reusing the code is refused — the wizard surfaces this as errors.code = 'taken'.
    app(TenantContext::class)->forget();
    $this->actingAs($admin)
        ->post(route('admin.branches.store'), cwPayload(['name' => 'Second', 'code' => 'DUP']))
        ->assertSessionHasErrors('code');

    app(TenantContext::class)->set($tenant);
    expect(Branch::query()->where('code', 'DUP')->count())->toBe(1); // no duplicate created
});

// ── Required-field validation is UNCHANGED + authoritative ────────────────────────────────────────────

test('missing required fields are refused server-side (validation unchanged)', function () {
    $tenant = cwTenant();
    $admin = cwUser($tenant, 'org_admin');

    // Missing name (step 1).
    app(TenantContext::class)->forget();
    $this->actingAs($admin)->post(route('admin.branches.store'), cwPayload(['name' => '']))->assertSessionHasErrors('name');

    // Missing timezone (step 2).
    app(TenantContext::class)->forget();
    $this->actingAs($admin)->post(route('admin.branches.store'), cwPayload(['code' => 'TZX', 'timezone' => '']))->assertSessionHasErrors('timezone');

    app(TenantContext::class)->set($tenant);
    expect(Branch::query()->count())->toBe(0); // nothing created by the refused attempts
});

// ── The P2 primary invariant on create is intact (non-primary unless first) ──────────────────────────

test('a branch created via the wizard is non-primary when it is not the tenant first (P2 intact)', function () {
    $tenant = cwTenant();
    $admin = cwUser($tenant, 'org_admin');

    // First branch → primary.
    app(TenantContext::class)->forget();
    $this->actingAs($admin)->post(route('admin.branches.store'), cwPayload(['name' => 'First', 'code' => 'FIRST']))->assertRedirect();

    // Second branch via the wizard → NON-primary; the first stays primary; exactly one.
    app(TenantContext::class)->forget();
    $this->actingAs($admin)->post(route('admin.branches.store'), cwPayload(['name' => 'Second', 'code' => 'SECOND']))->assertRedirect();

    app(TenantContext::class)->set($tenant);
    expect(Branch::query()->where('code', 'FIRST')->first()->is_primary)->toBeTrue()
        ->and(Branch::query()->where('code', 'SECOND')->first()->is_primary)->toBeFalse()
        ->and(Branch::query()->where('is_primary', true)->count())->toBe(1);
});

// ── RBAC + tenant-scoped ─────────────────────────────────────────────────────────────────────────────

test('branch create is admin.manage-gated and creates only in the acting tenant', function () {
    $tenant = cwTenant();

    // reception (no admin.manage) is denied.
    $reception = cwUser($tenant, 'reception');
    app(TenantContext::class)->forget();
    $this->actingAs($reception)->post(route('admin.branches.store'), cwPayload())->assertForbidden();

    app(TenantContext::class)->set($tenant);
    expect(Branch::query()->count())->toBe(0);

    // A second tenant's admin creates in ITS OWN tenant only (tenant-scoped by context).
    $beta = cwTenant('beta');
    $betaAdmin = cwUser($beta, 'org_admin');
    app(TenantContext::class)->forget();
    $this->actingAs($betaAdmin)->post(route('admin.branches.store'), cwPayload(['code' => 'BETA1']))->assertRedirect();

    app(TenantContext::class)->set($beta);
    expect(Branch::query()->where('code', 'BETA1')->count())->toBe(1);
    app(TenantContext::class)->set($tenant);
    expect(Branch::query()->where('code', 'BETA1')->count())->toBe(0); // not visible in alpha
});
