<?php

use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Modules\Platform\Http\Middleware\EnsureTwoFactorEnabled;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

/*
 * AUTH-SEC.1 — "Remember me" must never stand in for the second factor.
 *
 * THE DEFECT (auth audit §4.1): a remember-me cookie re-authenticates a browser with no password
 * prompt, and the mandatory-MFA middleware only asked whether the user had ENROLLED — so the recaller
 * cookie ALONE opened the app with no 2FA challenge. These tests are the bypass-closed proof: a
 * session restored from the recaller is routed to the challenge, the session proof can only be written
 * by a real second factor, and everything that already worked still works.
 * These tests ADD coverage; no existing behaviour test is modified.
 */

const RM_SECRET = 'JBSWY3DPEHPK3PXP';

function rmTenant(string $slug = 'alpha'): Tenant
{
    return Tenant::create(['name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
}

/** A route standing in for "any application route" (web group + auth, like the real ones). */
function rmAppRoute(): void
{
    Route::middleware(['web', 'auth'])->get('/_app_probe', fn () => response('in'));
}

/** An enrolled user whose TOTP secret we know, so the real challenge can be driven. */
function rmEnrolledUser(Tenant $tenant): User
{
    return User::factory()->forTenant($tenant)->create([
        'password' => 'demo-password',
        'two_factor_secret' => encrypt(RM_SECRET),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
    ]);
}

function rmCurrentOtp(): string
{
    return app(Google2FA::class)->getCurrentOtp(RM_SECRET);
}

/** A request carrying a resolved user and a live session, as the middleware sees one. */
function rmRequestFor(User $user, ?string $proofAt = null): Request
{
    $request = Request::create('/patients');
    $request->setUserResolver(fn () => $user);

    $session = app('session.store');
    if ($proofAt !== null) {
        $session->put(EnsureTwoFactorEnabled::CHALLENGE_PASSED_KEY, $proofAt);
    }
    $request->setLaravelSession($session);

    return $request;
}

/*
 * ── THE BYPASS-CLOSED PROOF ──────────────────────────────────────────────────────────────────────
 *
 * The audit reproduced the hole in a real browser: keep only the remember-me cookie, drop the
 * session, and the app opened with no challenge. That end-to-end path is re-verified in a browser for
 * this gate. Here we pin the DECISION itself, because the HTTP test client cannot faithfully replay a
 * recaller cookie — an injected cookie is not put through cookie decryption, so the guard never sees
 * a valid recaller and the request arrives as a plain guest. That is a harness limitation, not app
 * behaviour, so the decision is asserted directly against the middleware.
 */

test('THE BYPASS IS CLOSED: a via-remember session without a passed challenge is sent to the challenge', function () {
    $user = rmEnrolledUser(rmTenant());

    // The web guard resolved this user FROM THE RECALLER, and the session carries no second-factor proof.
    $guard = Mockery::mock(SessionGuard::class);
    $guard->shouldReceive('viaRemember')->andReturnTrue();
    // The middleware must drop the authenticated session before challenging.
    $guard->shouldReceive('logout')->once();
    Auth::shouldReceive('guard')->with('web')->andReturn($guard);

    $request = rmRequestFor($user);
    $response = (new EnsureTwoFactorEnabled)->handle($request, fn () => response('in'));

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe(route('two-factor.login'))
        // ...and the session is left as a PENDING two-factor login so the challenge can complete.
        ->and($request->session()->get('login.id'))->toBe($user->getAuthIdentifier())
        ->and($request->session()->get('login.remember'))->toBeTrue();
});

test('a via-remember session that HAS satisfied the second factor is let straight through', function () {
    $user = rmEnrolledUser(rmTenant());

    $guard = Mockery::mock(SessionGuard::class);
    $guard->shouldReceive('viaRemember')->andReturnTrue();
    Auth::shouldReceive('guard')->with('web')->andReturn($guard);

    $response = (new EnsureTwoFactorEnabled)->handle(
        rmRequestFor($user, now()->toDateTimeString()),
        fn () => response('in'),
    );

    expect($response->getContent())->toBe('in');
});

test('an ordinary (not remembered) session is untouched by the re-challenge', function () {
    $user = rmEnrolledUser(rmTenant());

    $guard = Mockery::mock(SessionGuard::class);
    $guard->shouldReceive('viaRemember')->andReturnFalse();
    Auth::shouldReceive('guard')->with('web')->andReturn($guard);

    $response = (new EnsureTwoFactorEnabled)->handle(rmRequestFor($user), fn () => response('in'));

    expect($response->getContent())->toBe('in');
});

// ── The proof is written ONLY by a real second factor ────────────────────────────────────────────

test('the challenge-passed proof is never written by merely authenticating or being enrolled', function () {
    $user = User::factory()->forTenant(rmTenant())->twoFactorEnabled()->create();
    rmAppRoute();

    // An ordinary authenticated session reaches the app (interactive sessions are untouched)...
    $this->actingAs($user)->get('/_app_probe')->assertOk();

    // ...and nothing on that path wrote the second-factor proof: it is reserved for a real challenge
    // or enrollment confirmation, so it can never be acquired by simply being logged in or enrolled.
    expect(session()->has(EnsureTwoFactorEnabled::CHALLENGE_PASSED_KEY))->toBeFalse();
});

test('completing the 2FA challenge records the proof and opens the app', function () {
    $user = rmEnrolledUser(rmTenant());
    rmAppRoute();

    $this->post('/login', ['email' => $user->email, 'password' => 'demo-password', 'remember' => 'on'])
        ->assertRedirect(route('two-factor.login'));
    $this->post('/two-factor-challenge', ['code' => rmCurrentOtp()])->assertRedirect();

    expect(session()->has(EnsureTwoFactorEnabled::CHALLENGE_PASSED_KEY))->toBeTrue();
    $this->get('/_app_probe')->assertOk();
});

// ── Nothing that worked before is weakened ───────────────────────────────────────────────────────

test('interactive login is unchanged: password alone never reaches the app', function () {
    $user = rmEnrolledUser(rmTenant());
    rmAppRoute();

    $this->post('/login', ['email' => $user->email, 'password' => 'demo-password'])
        ->assertRedirect(route('two-factor.login'));

    // Still pending the second factor — the app stays shut.
    $this->get('/_app_probe')->assertRedirect();
});

test('an un-enrolled user is still forced to enrollment, and can still reach enrollment', function () {
    $user = User::factory()->forTenant(rmTenant())->create();
    rmAppRoute();

    $this->actingAs($user)->get('/_app_probe')->assertRedirect(route('two-factor.enrollment'));
    $this->actingAs($user)->get('/two-factor/enrollment')->assertOk();
});
