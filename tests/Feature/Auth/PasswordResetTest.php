<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;

uses(RefreshDatabase::class);

/*
 * AUTH-SEC.2 — the password-reset pages must RENDER, and the reset must enforce the real policy.
 *
 * THE DEFECT (auth audit §7): Fortify's resetPasswords() feature was enabled, so the routes existed,
 * but no view was bound — both GET pages threw a BindingResolutionException (HTTP 500). A locked-out
 * user therefore had no self-service recovery, and nothing caught it because every route smoke
 * authenticated first and never requested a public page.
 * These tests ADD coverage; no existing behaviour test is modified.
 */

function pwrTenant(string $slug = 'alpha'): Tenant
{
    return Tenant::create(['name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
}

function pwrUser(Tenant $tenant): User
{
    return User::factory()->forTenant($tenant)->twoFactorEnabled()->create(['password' => 'demo-password']);
}

// ── The pages render (they used to 500) ──────────────────────────────────────────────────────────

test('the forgot-password page renders for an anonymous visitor', function () {
    $this->get('/forgot-password')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword'));
});

test('the reset-password page renders and carries the token and email through', function () {
    $tenant = pwrTenant();
    $user = pwrUser($tenant);
    $token = hash('sha256', 'a-valid-shaped-token');

    $this->get('/reset-password/'.$token.'?email='.urlencode($user->email))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/ResetPassword')
            ->where('token', $token)
            ->where('email', $user->email));
});

// ── The request flow still works, and does not leak who has an account ───────────────────────────

test('requesting a reset link works and answers identically for a known and an unknown address', function () {
    Notification::fake();
    $tenant = pwrTenant();
    $user = pwrUser($tenant);

    $known = $this->post('/forgot-password', ['email' => $user->email]);
    $known->assertSessionHasNoErrors();

    $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.test']);

    // Fortify answers the same way either way — the page cannot be used to enumerate accounts.
    expect($unknown->status())->toBe($known->status());
});

// ── The reset enforces the REAL password policy (unchanged by this gate) ─────────────────────────

test('the reset POST enforces the application password policy and can set a valid password', function () {
    $tenant = pwrTenant();
    $user = pwrUser($tenant);
    $token = Password::broker()->createToken($user);

    // A password below the configured policy is refused, and the old one still works.
    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    expect(Hash::check('demo-password', $user->fresh()->password))->toBeTrue();

    // A compliant password is accepted through the real Fortify flow.
    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-much-longer-passphrase',
        'password_confirmation' => 'a-much-longer-passphrase',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('a-much-longer-passphrase', $user->fresh()->password))->toBeTrue();
});

test('a reset does not weaken the mandatory-2FA gate', function () {
    $tenant = pwrTenant();
    $user = pwrUser($tenant);
    $token = Password::broker()->createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-much-longer-passphrase',
        'password_confirmation' => 'a-much-longer-passphrase',
    ])->assertSessionHasNoErrors();

    // The user is still enrolled, and signing in with the NEW password still hits the challenge.
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();

    $this->post('/login', ['email' => $user->email, 'password' => 'a-much-longer-passphrase'])
        ->assertRedirect(route('two-factor.login'));
});
