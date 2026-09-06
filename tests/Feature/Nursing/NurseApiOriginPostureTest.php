<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource as BookableResource;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| QA-FIX.4a — the Nurse PWA can talk to its own origin (P4-C1, D-201)
|--------------------------------------------------------------------------
| Phase 4 measured: POST /api/nurse/login and /api/nurse/sync returned 419
| "CSRF token mismatch" from a real browser, while GET /api/nurse/day-pack
| returned 200 — patient data reached the field device and no recorded care
| could come back.
|
| THE CAUSE WAS A TEST-POSTURE GAP AS MUCH AS A CONFIG ONE. Every pre-existing
| nurse API test calls postJson()/getJson() with NO Origin header, so the whole
| suite exercised the API in a posture no browser ever uses. Sanctum's
| EnsureFrontendRequestsAreStateful keys off Origin/Referer, so the defect was
| invisible to CI while the product was unusable.
|
| These tests therefore ALWAYS send a browser Origin.
|
| HONEST NOTE ON WHAT ACTUALLY GUARDS THIS, measured by mutation rather than assumed.
| Restoring `statefulApi()` in bootstrap/app.php reddens ONLY the structural control at the bottom
| of this file. The five request-level tests keep passing, because Laravel's ValidateCsrfToken
| self-skips whenever runningUnitTests() is true — so no feature test in this suite can ever observe
| the 419 a real browser gets. The request-level tests below are therefore REGRESSION COVER for the
| auth/ability behaviour of these endpoints, NOT guards against the stateful posture returning.
| The middleware-composition assertion is the guard. Do not delete it thinking the HTTP tests
| duplicate it — they do not, and the browser verification in the gate report is what proved the
| user-visible fix.
*/

/** The origin the PWA is actually served from — and the one SANCTUM_STATEFUL_DOMAINS derives from. */
const NAOP_ORIGIN = 'http://127.0.0.1:8000';

function naopNurse(): User
{
    $tenant = Tenant::query()->create([
        'name' => 'Spitex Origin', 'slug' => 'spitex-origin', 'region' => 'eu', 'status' => 'active',
    ]);
    app(TenantContext::class)->set($tenant);

    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create([
        'password' => bcrypt('demo-password'),
    ]);
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', 'nurse')->firstOrFail()->id,
    ]);

    // A nurse is only a nurse to the day-pack once user → StaffProfile → practitioner Resource
    // exists; DayPackService throws 403 at each missing link. Without this the day-pack 403s for a
    // reason that has nothing to do with the Origin posture under test.
    $branch = Branch::query()->create(['name' => 'Origin Branch', 'code' => 'ORIG', 'timezone' => 'Europe/Zurich']);
    $staff = StaffProfile::query()->create([
        'user_id' => $user->id,
        'first_name' => 'Origin',
        'last_name' => 'Nurse',
        'display_name' => 'Origin Nurse',
        'profession' => 'nurse',
        'primary_branch_id' => $branch->id,
    ]);
    BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Origin Nurse Resource',
        'staff_profile_id' => $staff->id,
        'branch_id' => $branch->id,
    ]);

    return $user;
}

/** Exactly what a browser sends from the PWA: an Origin (and Referer) on its own host. */
function naopBrowserHeaders(array $extra = []): array
{
    return array_merge([
        'Origin' => NAOP_ORIGIN,
        'Referer' => NAOP_ORIGIN.'/nurse-pwa/',
    ], $extra);
}

test('POST /api/nurse/login succeeds WITH a browser Origin header', function () {
    $nurse = naopNurse();

    // Before QA-FIX.4a this was 419 CSRF token mismatch.
    $response = $this->withHeaders(naopBrowserHeaders())
        ->postJson('/api/nurse/login', [
            'email' => $nurse->email,
            'password' => 'demo-password',
        ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'tenant_id']]);

    expect($response->json('token_type'))->toBe('Bearer')
        ->and($response->json('token'))->toBeString()->not->toBeEmpty();
});

test('POST /api/nurse/sync succeeds WITH a browser Origin header — recorded care can come back', function () {
    $nurse = naopNurse();

    $token = $this->withHeaders(naopBrowserHeaders())
        ->postJson('/api/nurse/login', ['email' => $nurse->email, 'password' => 'demo-password'])
        ->json('token');

    // An EMPTY action list is deliberate: this test is about REACHING the endpoint, not about what
    // it does. 419 (the pre-fix result) means the request never arrived; 422 means it arrived and
    // the validator spoke. Either way it must not be 419.
    $response = $this->withHeaders(naopBrowserHeaders(['Authorization' => 'Bearer '.$token]))
        ->postJson('/api/nurse/sync', ['actions' => []]);

    expect($response->status())->not->toBe(419);
    $response->assertStatus(422);
});

test('GET /api/nurse/day-pack still works WITH a browser Origin — the direction that always worked', function () {
    $nurse = naopNurse();

    $token = $this->withHeaders(naopBrowserHeaders())
        ->postJson('/api/nurse/login', ['email' => $nurse->email, 'password' => 'demo-password'])
        ->json('token');

    $this->withHeaders(naopBrowserHeaders(['Authorization' => 'Bearer '.$token]))
        ->getJson('/api/nurse/day-pack?date=2026-09-06')
        ->assertOk();
});

test('the PWA round trip is symmetric — data out and data back both work from one origin', function () {
    $nurse = naopNurse();

    $login = $this->withHeaders(naopBrowserHeaders())
        ->postJson('/api/nurse/login', ['email' => $nurse->email, 'password' => 'demo-password']);
    $token = $login->json('token');

    $out = $this->withHeaders(naopBrowserHeaders(['Authorization' => 'Bearer '.$token]))
        ->getJson('/api/nurse/day-pack?date=2026-09-06');
    $back = $this->withHeaders(naopBrowserHeaders(['Authorization' => 'Bearer '.$token]))
        ->postJson('/api/nurse/sync', ['actions' => []]);

    // P4-C1 was an ASYMMETRY: 200 out, 419 back. Pin the symmetry itself, not just the two statuses.
    expect($login->status())->toBe(200)
        ->and($out->status())->toBe(200)
        ->and($back->status())->not->toBe(419);
});

test('POSITIVE CONTROL — the token guard still refuses: no token, bad password, wrong ability', function () {
    $nurse = naopNurse();

    // Removing the stateful middleware must not have removed AUTHENTICATION.
    $this->withHeaders(naopBrowserHeaders())
        ->postJson('/api/nurse/sync', ['actions' => []])
        ->assertUnauthorized();

    $this->withHeaders(naopBrowserHeaders())
        ->getJson('/api/nurse/day-pack?date=2026-09-06')
        ->assertUnauthorized();

    $this->withHeaders(naopBrowserHeaders())
        ->postJson('/api/nurse/login', ['email' => $nurse->email, 'password' => 'wrong-password'])
        ->assertStatus(422);

    // A token WITHOUT the nurse:day-pack ability is refused by NurseSyncController's tokenCan check.
    $weak = $nurse->createToken('weak-device', ['something:else'])->plainTextToken;
    $this->withHeaders(naopBrowserHeaders(['Authorization' => 'Bearer '.$weak]))
        ->postJson('/api/nurse/sync', ['actions' => []])
        ->assertForbidden();
});

test('POSITIVE CONTROL — CSRF did NOT disappear wholesale: `web` keeps it, only `api` changed posture', function () {
    // The fix narrows ONE thing: `api/*` is no longer treated as a stateful first-party SPA. The web
    // group — the Inertia app, the patient portal, the kiosk — must still be CSRF-protected.
    //
    // This is asserted STRUCTURALLY rather than by driving a web route, because Laravel's
    // ValidateCsrfToken self-skips whenever runningUnitTests() is true: a feature test can never
    // observe a 419 from a web route, so a request-based control here would assert nothing at all
    // and pass just as happily with CSRF removed.
    $groups = app('router')->getMiddlewareGroups();

    expect($groups)->toHaveKey('web')
        ->and($groups['web'])->toContain(ValidateCsrfToken::class);

    // …and the api group must NOT carry Sanctum's stateful middleware. Restoring `statefulApi()`
    // in bootstrap/app.php turns this red, which is what makes every other test in this file a
    // guard rather than a coincidence.
    expect($groups['api'] ?? [])
        ->not->toContain(EnsureFrontendRequestsAreStateful::class);
});
