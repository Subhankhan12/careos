<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Setting;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * SETTINGS.P3 — the "Scheduling" settings card is presentation over settings the schedulers ALREADY
 * read. It writes ONLY the existing keys (scheduling.portal.cancel_min_hours,
 * nursing.dispatch.average_speed_kmh) so a saved value is honored. The "default buffer" is a per-service
 * pointer, NOT a global setting — nothing global is persisted.
 */

function schedSetCtx(): TenantContext
{
    return app(TenantContext::class);
}

function schedSetTenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    schedSetCtx()->set($tenant);

    return $tenant;
}

function schedSetUser(Tenant $tenant, string $roleKey): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    if ($roleKey !== '') {
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id]);
    }

    return $user;
}

/** Read a setting with an explicit tenant context (request context is gone after the response). */
function schedSetGet(Tenant $tenant, string $key, mixed $default = null): mixed
{
    schedSetCtx()->set($tenant);

    return app(SettingsService::class)->get($key, $default);
}

// ── The card reads the real settings (defaults when unset) ────────────────────

test('the scheduling card shows the settings the schedulers read — defaults when unset', function () {
    $tenant = schedSetTenant();
    $admin = schedSetUser($tenant, 'org_admin');

    $this->actingAs($admin)
        ->get('/admin/scheduling')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Scheduling')
            ->where('scheduling.cancelMinHours', 24) // the reader default
            ->where('scheduling.travelSpeedKmh', 40)
            ->has('bounds.cancelMinHours')
            ->has('updateUrl'));
});

// ── Save persists the exact keys the schedulers consume, audited ──────────────

test('saving persists cancel window + travel speed at the keys the schedulers actually read, audited', function () {
    $tenant = schedSetTenant();
    $admin = schedSetUser($tenant, 'org_admin');

    $this->actingAs($admin)
        ->post('/admin/scheduling', ['cancel_min_hours' => 48, 'travel_speed_kmh' => 28])
        ->assertRedirect('/admin/scheduling');

    // The scheduler reads these EXACT expressions — proving the saved value is honored, not ignored.
    expect((int) schedSetGet($tenant, 'scheduling.portal.cancel_min_hours', 24))->toBe(48)
        ->and((int) schedSetGet($tenant, 'nursing.dispatch.average_speed_kmh', 40))->toBe(28);

    $audited = DB::selectOne(
        'SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?',
        [$tenant->id, 'settings.scheduling_changed'],
    )->c;
    expect((int) $audited)->toBe(1);
});

test('an unchanged save writes no audit row (no churn)', function () {
    $tenant = schedSetTenant();
    $admin = schedSetUser($tenant, 'org_admin');

    // Re-submit the current defaults — nothing changes.
    $this->actingAs($admin)
        ->post('/admin/scheduling', ['cancel_min_hours' => 24, 'travel_speed_kmh' => 40])
        ->assertRedirect('/admin/scheduling');

    $audited = DB::selectOne(
        'SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?',
        [$tenant->id, 'settings.scheduling_changed'],
    )->c;
    expect((int) $audited)->toBe(0);
});

// ── Validation bounds ─────────────────────────────────────────────────────────

test('out-of-bounds values are rejected and nothing is written', function () {
    $tenant = schedSetTenant();
    $admin = schedSetUser($tenant, 'org_admin');

    // cancel window above 168h, and travel speed below 1 km/h.
    $this->actingAs($admin)
        ->post('/admin/scheduling', ['cancel_min_hours' => 200, 'travel_speed_kmh' => 0])
        ->assertSessionHasErrors(['cancel_min_hours', 'travel_speed_kmh']);

    // Nothing persisted — the readers still see their defaults.
    expect(schedSetGet($tenant, 'scheduling.portal.cancel_min_hours'))->toBeNull()
        ->and(schedSetGet($tenant, 'nursing.dispatch.average_speed_kmh'))->toBeNull();

    // Travel speed above 200 is also rejected.
    $this->actingAs($admin)
        ->post('/admin/scheduling', ['cancel_min_hours' => 24, 'travel_speed_kmh' => 300])
        ->assertSessionHasErrors('travel_speed_kmh');
});

// ── The buffer is a per-service pointer — NO fake global is persisted ──────────

test('the buffer field persists no global setting — buffers stay per-service', function () {
    $tenant = schedSetTenant();
    $admin = schedSetUser($tenant, 'org_admin');

    // Even a save that includes a stray "default buffer" key writes no such setting.
    $this->actingAs($admin)
        ->post('/admin/scheduling', ['cancel_min_hours' => 24, 'travel_speed_kmh' => 40, 'default_buffer_min' => 15])
        ->assertRedirect('/admin/scheduling');

    schedSetCtx()->set($tenant);
    $bufferKeys = Setting::query()->where('key', 'like', '%buffer%')->count();
    expect($bufferKeys)->toBe(0); // no global buffer setting exists — the card never invents one
});

// ── RBAC + tenant isolation ───────────────────────────────────────────────────

test('scheduling settings are gated on admin.manage — a non-admin is 403 on read and write', function () {
    $tenant = schedSetTenant();
    $reception = schedSetUser($tenant, 'reception'); // no admin.manage

    $this->actingAs($reception)->get('/admin/scheduling')->assertForbidden();
    $this->actingAs($reception)
        ->post('/admin/scheduling', ['cancel_min_hours' => 48, 'travel_speed_kmh' => 28])
        ->assertForbidden();
});

test('scheduling writes are tenant-scoped', function () {
    $alpha = schedSetTenant('alpha');
    $alphaAdmin = schedSetUser($alpha, 'org_admin');
    $beta = schedSetTenant('beta'); // sets context to beta

    $this->actingAs($alphaAdmin)
        ->post('/admin/scheduling', ['cancel_min_hours' => 48, 'travel_speed_kmh' => 28])
        ->assertRedirect('/admin/scheduling');

    // Beta never saw the write — its readers still see the defaults.
    expect(schedSetGet($beta, 'scheduling.portal.cancel_min_hours', 24))->toBe(24)
        ->and(schedSetGet($beta, 'nursing.dispatch.average_speed_kmh', 40))->toBe(40);
});
