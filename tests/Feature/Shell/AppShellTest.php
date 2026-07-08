<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\LoginResponse;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;

uses(RefreshDatabase::class);

function shellTenant(string $status = 'active', string $slug = 'alpha'): Tenant
{
    return Tenant::create(['name' => 'Alpha', 'slug' => $slug, 'region' => 'eu', 'status' => $status]);
}

// --- Public auth pages -------------------------------------------------------

test('the login page renders the Inertia Auth/Login component', function () {
    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

test('the root redirects guests to login', function () {
    $this->get('/')->assertRedirect('/login');
});

// --- Guards: authentication --------------------------------------------------

test('the app shell requires authentication', function () {
    $this->get('/app')->assertRedirect('/login');
});

test('the admin shell requires authentication', function () {
    $this->get('/admin')->assertRedirect('/login');
});

// --- Guards: mandatory MFA ---------------------------------------------------

test('an un-enrolled staff user is routed to 2FA enrollment before the app shell', function () {
    $tenant = shellTenant();
    $user = User::factory()->forTenant($tenant)->create();

    $this->actingAs($user)->get('/app')->assertRedirect(route('two-factor.enrollment'));
});

test('the enrollment page renders for an un-enrolled user', function () {
    $tenant = shellTenant();
    $user = User::factory()->forTenant($tenant)->create();

    $this->actingAs($user)->get('/two-factor/enrollment')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/TwoFactorEnroll'));
});

// --- Guards: role-appropriate landings --------------------------------------

test('an enrolled staff user reaches the app shell', function () {
    $tenant = shellTenant();
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    $this->actingAs($user)->get('/app')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('App/Landing'));
});

test('an enrolled super-admin reaches the admin shell', function () {
    $admin = User::factory()->twoFactorEnabled()->create(); // tenant_id null

    $this->actingAs($admin)->get('/admin')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Landing'));
});

test('tenant staff cannot reach the admin shell', function () {
    $tenant = shellTenant();
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

// --- Guards: suspended tenant ------------------------------------------------

test('a suspended tenant blocks access to the app shell', function () {
    $tenant = shellTenant('suspended');
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    $this->actingAs($user)->get('/app')->assertForbidden();
});

// --- Role-based redirect after login ----------------------------------------

test('the login response redirects super-admins to /admin and staff to /app', function () {
    $tenant = shellTenant();
    $staff = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $admin = User::factory()->twoFactorEnabled()->create();

    $response = app(LoginResponse::class);

    $staffRequest = Request::create('/login', 'POST');
    $staffRequest->setUserResolver(fn () => $staff);
    expect($response->toResponse($staffRequest)->getTargetUrl())->toEndWith('/app');

    $adminRequest = Request::create('/login', 'POST');
    $adminRequest->setUserResolver(fn () => $admin);
    expect($response->toResponse($adminRequest)->getTargetUrl())->toEndWith('/admin');
});
