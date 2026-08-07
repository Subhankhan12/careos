<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Setting;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * SETTINGS.P4 — the "Security" card is a strictly READ-ONLY reflection of the real enforced
 * controls. Two-factor is mandatory (EnsureTwoFactorEnabled middleware); the card renders it
 * locked and has NO action that can disable it. Session timeout / PWA idle wipe are platform
 * config/constant, rendered read-only. Nothing is written.
 */

function secSetCtx(): TenantContext
{
    return app(TenantContext::class);
}

function secSetTenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    secSetCtx()->set($tenant);

    return $tenant;
}

function secSetUser(Tenant $tenant, string $roleKey): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    if ($roleKey !== '') {
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id]);
    }

    return $user;
}

// ── The card reflects the real enforced controls, read-only ───────────────────

test('the security card renders 2FA mandatory-locked plus the read-only session + wipe values', function () {
    $tenant = secSetTenant();
    $admin = secSetUser($tenant, 'org_admin');

    $this->actingAs($admin)
        ->get('/admin/security')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Security')
            ->where('security.twoFactor', 'mandatory') // reflects EnsureTwoFactorEnabled — locked
            ->where('security.sessionTimeoutMin', (int) config('session.lifetime'))
            ->where('security.nursePwaIdleMin', 15));
});

test('viewing the security card writes nothing — it is read-only', function () {
    $tenant = secSetTenant();
    $admin = secSetUser($tenant, 'org_admin');

    $before = Setting::query()->count();
    $this->actingAs($admin)->get('/admin/security')->assertOk();

    secSetCtx()->set($tenant);
    expect(Setting::query()->count())->toBe($before); // no setting created by rendering the card
});

// ── THE GATE: there is NO path from this card to disable/weaken 2FA ────────────

test('THE GATE: the security card exposes no action that can disable or weaken 2FA', function () {
    // Structural proof: only a GET exists under /admin/security — no POST/PUT/PATCH/DELETE, and no
    // "update"/"disable" named route. A card with no write action cannot turn a control off.
    expect(Route::has('admin.security.update'))->toBeFalse();

    $methods = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'admin/security'))
        ->flatMap(fn ($route) => $route->methods())
        ->unique()
        ->values()
        ->all();

    // Only read verbs (GET/HEAD) are routed under admin/security.
    expect($methods)->toContain('GET')
        ->and(collect($methods)->diff(['GET', 'HEAD'])->all())->toBe([]);
});

test('THE GATE: 2FA stays enforced by the middleware — an un-enrolled user cannot reach app routes', function () {
    $tenant = secSetTenant();
    // A user WITHOUT two-factor enrollment (no ->twoFactorEnabled()).
    $unenrolled = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $unenrolled->id, 'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id]);

    // The middleware redirects an un-enrolled user to enrollment — the card can't relax this.
    $this->actingAs($unenrolled)->get('/admin/security')->assertRedirect(route('two-factor.enrollment'));
});

// ── RBAC ──────────────────────────────────────────────────────────────────────

test('the security card is gated on admin.manage — a non-admin is 403', function () {
    $tenant = secSetTenant();
    $reception = secSetUser($tenant, 'reception'); // no admin.manage

    $this->actingAs($reception)->get('/admin/security')->assertForbidden();
});
