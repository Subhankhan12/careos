<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;

uses(RefreshDatabase::class);

/*
 * AUTH-VIS — the enrolment "Can't scan?" manual-secret fallback.
 *
 * The screen now offers the SAME secret the QR encodes, as selectable text, for a user who cannot
 * scan. It is purely a display of the user's OWN enrolled key, read from the Fortify endpoint that
 * already existed — nothing is generated here and no other user's secret is reachable. These tests
 * pin exactly that: the endpoint returns the user's real secret (never a mock literal), it is scoped
 * to the authenticated user, and the mandatory-2FA gate is unchanged.
 * These tests ADD coverage; no existing behaviour test is modified.
 */

function tfsTenant(string $slug = 'alpha'): Tenant
{
    return Tenant::create(['name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
}

/** A user part-way through enrolment: the secret exists but is not confirmed yet. */
function tfsEnrollingUser(Tenant $tenant): User
{
    $user = User::factory()->forTenant($tenant)->create(['password' => 'demo-password']);
    $user->forceFill([
        'two_factor_secret' => encrypt('KRSXG5CTMVRXEZLU'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
    ])->save();

    return $user;
}

// ── The fallback shows the user's REAL secret ────────────────────────────────────────────────────

test('the secret endpoint returns the user OWN real provisioning secret, not a mock value', function () {
    $user = tfsEnrollingUser(tfsTenant());

    $response = $this->actingAs($user)->getJson('/user/two-factor-secret-key')->assertOk();

    $secret = $response->json('secretKey');

    // It is the REAL stored secret — the same one the QR encodes — decrypted for its owner.
    expect($secret)->toBe(Fortify::currentEncrypter()->decrypt($user->two_factor_secret))
        ->and($secret)->toBe('KRSXG5CTMVRXEZLU')
        // ...and emphatically not the wireframe's illustrative literal.
        ->and($secret)->not->toBe('JBSWY3DPEHPK3PXP');
});

test('the QR provisioning URI carries the SAME secret the manual fallback shows', function () {
    $user = tfsEnrollingUser(tfsTenant());

    $secret = $this->actingAs($user)->getJson('/user/two-factor-secret-key')->json('secretKey');
    $svg = $this->actingAs($user)->getJson('/user/two-factor-qr-code')->json('svg');

    // The QR is rendered from the provisioning URI, so the fallback cannot drift from it: enrolling
    // by scanning and enrolling by typing land on the same key.
    expect($user->twoFactorQrCodeUrl())->toContain($secret)
        ->and($svg)->toBeString()->not->toBeEmpty();
});

// ── No cross-user exposure ───────────────────────────────────────────────────────────────────────

test('the secret endpoint only ever answers for the authenticated user, and never for a guest', function () {
    $tenant = tfsTenant();
    $mine = tfsEnrollingUser($tenant);

    $theirs = User::factory()->forTenant($tenant)->create();
    $theirs->forceFill(['two_factor_secret' => encrypt('OTHERUSERSECRET1')])->save();

    // Signed in as one user, the endpoint returns THAT user's key — there is no id parameter to
    // point at anyone else.
    expect($this->actingAs($mine)->getJson('/user/two-factor-secret-key')->json('secretKey'))
        ->toBe('KRSXG5CTMVRXEZLU')
        ->not->toBe('OTHERUSERSECRET1');

    expect($this->actingAs($theirs)->getJson('/user/two-factor-secret-key')->json('secretKey'))
        ->toBe('OTHERUSERSECRET1');
});

test('a guest cannot read a secret at all', function () {
    tfsEnrollingUser(tfsTenant());

    // No session, no key: the fallback is reachable only from inside an authenticated enrolment.
    $this->get('/user/two-factor-secret-key')->assertRedirect(route('login'));
});

// ── The gate is untouched ────────────────────────────────────────────────────────────────────────

test('2FA stays mandatory: no skip or disable path was added to enrollment', function () {
    $user = User::factory()->forTenant(tfsTenant())->create();
    Route::middleware(['web', 'auth'])->get('/_vis_probe', fn () => response('in'));

    // An un-enrolled user still cannot reach the app, and still reaches enrollment.
    $this->actingAs($user)->get('/_vis_probe')->assertRedirect(route('two-factor.enrollment'));
    $this->actingAs($user)->get('/two-factor/enrollment')->assertOk();

    // No route offers skipping or postponing enrollment.
    $names = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => (string) $route->getName().' '.$route->uri())
        ->implode(' ');
    foreach (['skip', 'postpone', 'remind-later', 'two-factor/later'] as $needle) {
        expect(str_contains(strtolower($names), $needle))->toBeFalse();
    }
});
