<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function activeTenant(string $slug = 'alpha'): Tenant
{
    return Tenant::create([
        'name' => 'Alpha Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

/** A web-group route that echoes the current tenant context id. */
function ctxProbeRoute(): void
{
    Route::middleware('web')->get('/_ctx', fn (TenantContext $ctx) => response()->json([
        'tenant_id' => $ctx->id(),
    ]));
}

// --- Login ------------------------------------------------------------------

test('login succeeds with valid credentials for a user without 2FA', function () {
    User::factory()->create(['email' => 'super@careos.test']);

    $this->postJson('/login', ['email' => 'super@careos.test', 'password' => 'password'])
        ->assertOk();

    $this->assertAuthenticated();
});

test('login fails with an invalid password', function () {
    User::factory()->create(['email' => 'super@careos.test']);

    $this->postJson('/login', ['email' => 'super@careos.test', 'password' => 'wrong-password'])
        ->assertStatus(422);

    $this->assertGuest();
});

test('a 2FA-enrolled user is challenged instead of being logged in', function () {
    User::factory()->twoFactorEnabled()->create(['email' => 'mfa@careos.test']);

    $this->postJson('/login', ['email' => 'mfa@careos.test', 'password' => 'password'])
        ->assertOk()
        ->assertJson(['two_factor' => true]);

    // Not authenticated until the TOTP challenge is completed.
    $this->assertGuest();
});

test('login is denied for staff of a suspended tenant', function () {
    $tenant = Tenant::create([
        'name' => 'Suspended Clinic',
        'slug' => 'suspended',
        'region' => 'eu',
        'status' => 'suspended',
    ]);
    User::factory()->forTenant($tenant)->twoFactorEnabled()->create(['email' => 'susp@careos.test']);

    $this->postJson('/login', ['email' => 'susp@careos.test', 'password' => 'password'])
        ->assertStatus(422);

    $this->assertGuest();
});

// --- EnsureTwoFactorEnabled --------------------------------------------------

test('EnsureTwoFactorEnabled blocks an un-enrolled staff user from app routes', function () {
    $tenant = activeTenant();
    $user = User::factory()->forTenant($tenant)->create();
    ctxProbeRoute();

    $this->actingAs($user)->getJson('/_ctx')->assertForbidden();
});

test('EnsureTwoFactorEnabled allows an enrolled staff user through', function () {
    $tenant = activeTenant();
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    ctxProbeRoute();

    $this->actingAs($user)->getJson('/_ctx')
        ->assertOk()
        ->assertJson(['tenant_id' => $tenant->id]);
});

test('EnsureTwoFactorEnabled lets an un-enrolled user reach the enrollment route', function () {
    $tenant = activeTenant();
    $user = User::factory()->forTenant($tenant)->create();

    $this->actingAs($user)->get('/two-factor/enrollment')->assertOk();
});

// --- IdentifyTenantFromUser --------------------------------------------------

test('IdentifyTenantFromUser sets TenantContext for tenant staff', function () {
    $tenant = activeTenant();
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    ctxProbeRoute();

    $this->actingAs($user)->getJson('/_ctx')
        ->assertOk()
        ->assertExactJson(['tenant_id' => $tenant->id]);
});

test('IdentifyTenantFromUser leaves TenantContext empty for a super-admin', function () {
    $admin = User::factory()->twoFactorEnabled()->create(); // tenant_id null
    ctxProbeRoute();

    $this->actingAs($admin)->getJson('/_ctx')
        ->assertOk()
        ->assertExactJson(['tenant_id' => null]);
});

test('IdentifyTenantFromUser denies an authenticated user whose tenant is suspended', function () {
    $tenant = Tenant::create([
        'name' => 'Suspended Clinic',
        'slug' => 'suspended',
        'region' => 'eu',
        'status' => 'suspended',
    ]);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    ctxProbeRoute();

    $this->actingAs($user)->getJson('/_ctx')->assertForbidden();
});
