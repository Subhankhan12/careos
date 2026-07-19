<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * CLINIC.W8 — Settings + Roles/access admin, built OVER existing backends. Settings
 * writes go through SettingsService; role assignment goes through the sanctioned
 * `RoleAssignment::create` path (auto-audited). Both gate admin.manage, are tenant
 * scoped, and cannot grant anything beyond the built role templates.
 */

function w8Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function w8Tenant(string $slug = 'alpha'): Tenant
{
    // Tenant::created provisions the 6 system role templates + permission catalog.
    $tenant = Tenant::create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    w8Ctx()->set($tenant);

    return $tenant;
}

function w8User(Tenant $tenant, string $roleKey): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    if ($roleKey !== '') {
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id]);
    }

    return $user;
}

function w8RoleId(string $key): string
{
    return Role::query()->where('key', $key)->value('id');
}

function w8RoleAssignedCount(Tenant $tenant): int
{
    return (int) DB::selectOne(
        'SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?',
        [$tenant->id, 'role.assigned'],
    )->c;
}

// ── Settings ────────────────────────────────────────────────────────────────

test('an admin views settings and saves currency + invoice identity through the service', function () {
    $tenant = w8Tenant();
    $admin = w8User($tenant, 'org_admin');

    $this->actingAs($admin)
        ->get('/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Settings')
            ->where('billing.currency', 'EUR')
            ->has('branches')
            ->has('profile.name'));

    $this->actingAs($admin)
        ->post('/settings', ['currency' => 'CHF', 'seller_name' => 'Praxis Lindenhof AG', 'seller_vat_id' => 'CHE-123.456.789'])
        ->assertRedirect('/settings');

    // The write went through the EXISTING SettingsService (tenant-scoped).
    w8Ctx()->set($tenant);
    $settings = app(SettingsService::class);
    expect($settings->get('currency'))->toBe('CHF')
        ->and($settings->get('billing.seller_name'))->toBe('Praxis Lindenhof AG')
        ->and($settings->get('billing.seller_vat_id'))->toBe('CHE-123.456.789');
});

test('settings are gated on admin.manage — a non-admin is 403', function () {
    $tenant = w8Tenant();
    $reception = w8User($tenant, 'reception'); // no admin.manage

    $this->actingAs($reception)->get('/settings')->assertForbidden();
    $this->actingAs($reception)->post('/settings', ['currency' => 'CHF'])->assertForbidden();
});

test('currency must be one of the allowed values', function () {
    $tenant = w8Tenant();
    $admin = w8User($tenant, 'org_admin');

    $this->actingAs($admin)
        ->post('/settings', ['currency' => 'XXX'])
        ->assertSessionHasErrors('currency');

    w8Ctx()->set($tenant);
    expect(app(SettingsService::class)->get('currency'))->toBe('EUR'); // unchanged
});

test('settings writes are tenant-scoped', function () {
    $alpha = w8Tenant('alpha');
    $alphaAdmin = w8User($alpha, 'org_admin');
    $beta = w8Tenant('beta'); // sets context to beta

    $this->actingAs($alphaAdmin)->post('/settings', ['currency' => 'CHF'])->assertRedirect();

    // Beta never saw the write — its currency is still the platform default.
    w8Ctx()->set($beta);
    expect(app(SettingsService::class)->get('currency'))->toBe('EUR');
});

// ── Roles / access ──────────────────────────────────────────────────────────

test('an admin assigns a role template — through the audited path, and cannot exceed the template', function () {
    $tenant = w8Tenant();
    $admin = w8User($tenant, 'org_admin');
    $member = w8User($tenant, 'reception');

    $this->actingAs($admin)
        ->get('/admin/roles')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Roles')
            ->has('users')
            ->has('roles')
            ->has('catalog'));

    $before = w8RoleAssignedCount($tenant);

    $this->actingAs($admin)
        ->post('/admin/roles/assign', ['user_id' => $member->id, 'role_id' => w8RoleId('doctor')])
        ->assertRedirect('/admin/roles');

    w8Ctx()->set($tenant);

    // The member now holds exactly the doctor role (the reception one was replaced).
    expect(RoleAssignment::query()->where('user_id', $member->id)->count())->toBe(1)
        ->and(RoleAssignment::query()->where('user_id', $member->id)->where('role_id', w8RoleId('doctor'))->exists())->toBeTrue();

    // Assignment is AUDITED automatically (exactly one new role.assigned row) + chain valid.
    expect(w8RoleAssignedCount($tenant) - $before)->toBe(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();

    // CANNOT EXCEED the template — effective permissions are exactly doctor's.
    $member->refresh();
    expect($member->hasPermission('note.write'))->toBeTrue()   // doctor grants this
        ->and($member->hasPermission('billing.manage'))->toBeFalse()  // doctor does NOT
        ->and($member->hasPermission('admin.manage'))->toBeFalse();
});

test('role assignment is gated on admin.manage — a non-admin is 403', function () {
    $tenant = w8Tenant();
    $reception = w8User($tenant, 'reception');
    $member = w8User($tenant, 'nurse');

    $this->actingAs($reception)->get('/admin/roles')->assertForbidden();
    $this->actingAs($reception)
        ->post('/admin/roles/assign', ['user_id' => $member->id, 'role_id' => w8RoleId('doctor')])
        ->assertForbidden();
});

test('an admin cannot assign to a user in another tenant, nor use a cross-tenant role (404)', function () {
    $alpha = w8Tenant('alpha');
    $alphaAdmin = w8User($alpha, 'org_admin');
    $alphaMember = w8User($alpha, 'reception');
    $alphaDoctorRole = w8RoleId('doctor');

    $beta = w8Tenant('beta');
    $betaMember = w8User($beta, 'reception');
    $betaDoctorRole = w8RoleId('doctor'); // beta's own doctor role id

    // Target user belongs to another tenant → 404.
    $this->actingAs($alphaAdmin)
        ->post('/admin/roles/assign', ['user_id' => $betaMember->id, 'role_id' => $alphaDoctorRole])
        ->assertNotFound();

    // Role id belongs to another tenant → 404 (Role is tenant-scoped).
    $this->actingAs($alphaAdmin)
        ->post('/admin/roles/assign', ['user_id' => $alphaMember->id, 'role_id' => $betaDoctorRole])
        ->assertNotFound();
});

test('the last org_admin cannot be demoted (self-lockout guard)', function () {
    $tenant = w8Tenant();
    $admin = w8User($tenant, 'org_admin'); // the ONLY org_admin

    $this->actingAs($admin)
        ->post('/admin/roles/assign', ['user_id' => $admin->id, 'role_id' => w8RoleId('reception')])
        ->assertRedirect()
        ->assertSessionHasErrors('role');

    // Still an org_admin — nothing changed.
    w8Ctx()->set($tenant);
    $admin->refresh();
    expect($admin->hasPermission('admin.manage'))->toBeTrue()
        ->and(RoleAssignment::query()->where('user_id', $admin->id)->where('role_id', w8RoleId('org_admin'))->exists())->toBeTrue();
});

test('an org_admin can be demoted when another admin remains (guard is not over-broad)', function () {
    $tenant = w8Tenant();
    $admin1 = w8User($tenant, 'org_admin');
    $admin2 = w8User($tenant, 'org_admin');

    $this->actingAs($admin1)
        ->post('/admin/roles/assign', ['user_id' => $admin2->id, 'role_id' => w8RoleId('reception')])
        ->assertRedirect('/admin/roles')
        ->assertSessionHasNoErrors();

    w8Ctx()->set($tenant);
    $admin2->refresh();
    expect($admin2->hasPermission('admin.manage'))->toBeFalse()
        ->and($admin2->hasPermission('comms.manage'))->toBeTrue(); // reception grants this
});
