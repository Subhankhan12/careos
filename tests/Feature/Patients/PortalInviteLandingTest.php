<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Models\PortalLoginToken;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Patients\Services\PortalAccessService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * THE THROTTLE BUCKET SURVIVES BETWEEN TESTS ON CI, AND ONLY ON CI.
 *
 * phpunit.xml sets CACHE_STORE=array, but a non-forced <env> does NOT override a real environment
 * variable — and the CI workflow exports CACHE_STORE=redis. So locally the limiter starts empty for
 * every test, while on CI it is one long-lived Redis key. A file like this one, which makes a dozen
 * requests to a 10/min route, then poisons its own later tests: the first version of this file was
 * green here and red there for exactly that reason.
 *
 * Worse, the signature for a guest is sha1(domain|ip) — NOT the path — so every `throttle:10,1`
 * guest route shares one bucket per visitor. The staff-invite requests in another test count too.
 *
 * Laravel's RateLimiter uses the `cache.limiter` store (null falls through to the default one), so
 * emptying it is the supported way to start each test from a known bucket. Nothing is relaxed: the
 * throttle is still asserted below, just from a defined starting point.
 */
beforeEach(function (): void {
    Cache::store(config('cache.limiter'))->flush();
});

/*
 * PT.P6 — the patient invite landing page.
 *
 * A guest surface reached from an email, so the property under test is not "does it look right"
 * but "what does it tell someone who is guessing". The fixture therefore carries FOUR tokens —
 * valid, expired, consumed, and one bound to an account outside its own tenant — and every refusal
 * below is built so that WITHOUT its guard the request would SUCCEED (D-182):
 *
 *   - the expired token is otherwise perfect: right purpose, unconsumed, real account. Only
 *     `expires_at >= now()` stands in the way.
 *   - the consumed token is otherwise perfect and its account is real and reachable. Only
 *     `consumed_at IS NULL` stands in the way — and it was consumed by a REAL redemption, not by
 *     a hand-set column.
 *   - the cross-tenant token is a live, unexpired, unconsumed token whose account lives in a
 *     different tenant. Only resolving the account inside the TOKEN's tenant refuses it.
 *
 * And the point of the whole gate: all four dead cases must be INDISTINGUISHABLE — same status,
 * same body, byte for byte.
 */

function pinCtx(): TenantContext
{
    return app(TenantContext::class);
}

function pinTenant(string $slug): Tenant
{
    return Tenant::query()->create([
        'name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active',
    ]);
}

function pinStaff(Tenant $tenant): User
{
    $staff = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $staff->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    return $staff;
}

function pinConsentedPatient(User $staff, string $first, string $last): Patient
{
    $patient = app(PatientService::class)->create([
        'first_name' => $first, 'last_name' => $last, 'date_of_birth' => '1978-05-04', 'sex' => 'female',
    ]);

    ConsentTemplate::query()->firstOrCreate(
        ['key' => 'portal', 'version' => 1],
        ['title' => 'Portal Access', 'body' => 'Portal access consent', 'scope_keys' => ['portal.access'], 'is_active' => true],
    );
    app(ConsentService::class)->grant($patient, 'portal', $first.' '.$last, $staff);

    return $patient;
}

/**
 * The four-token fixture. Verified by query in the first test rather than assumed.
 *
 * @return array{tenant: Tenant, other: Tenant, staff: User, patient: Patient, valid: string, expired: string, consumed: string, consumedOtp: string, crossTenant: string, otp: string, account: PortalAccount}
 */
function pinFixture(): array
{
    Notification::fake();

    $tenant = pinTenant('alpha');
    $other = pinTenant('beta');

    pinCtx()->set($tenant);
    $staff = pinStaff($tenant);
    $patient = pinConsentedPatient($staff, 'Erika', 'Baumgartner');

    $portal = app(PortalAccessService::class);

    // THE VALID ONE — a real invitation for a patient who has never signed in.
    $invite = $portal->invite($patient, 'erika.invite@example.test');
    $account = $invite->account;
    $otp = $invite->otp;
    $valid = $invite->plainToken;

    // THE EXPIRED ONE — identical in every other respect; only the clock refuses it.
    $expiredPlain = Str::random(64);
    $expiredToken = new PortalLoginToken([
        'purpose' => PortalLoginToken::PURPOSE_INVITE,
        'token_hash' => hash('sha256', $expiredPlain),
        'otp_hash' => bcrypt('123456'),
        'expires_at' => Carbon::now()->subMinute(),
    ]);
    $expiredToken->portal_account_id = $account->id;
    $expiredToken->save();

    // THE CONSUMED ONE — consumed by a REAL redemption below, not by writing a column.
    $consumedInvite = $portal->invite($patient, 'erika.invite@example.test');
    $consumedPlain = $consumedInvite->plainToken;
    $consumedOtp = $consumedInvite->otp;   // each invitation carries its OWN code

    // THE CROSS-TENANT ONE — a live token in tenant BETA pointing at ALPHA's account. The model
    // refuses to create such a row (assertAccountWithinTenant), which is itself a guard; the row is
    // therefore forced into place at the DB level to represent a token that escaped that guard, so
    // the SECOND line of defence — resolving the account inside the token's own tenant — is what
    // this fixture actually tests. Defence in depth needs each layer pinned (D-183).
    $crossPlain = Str::random(64);
    DB::table('portal_login_tokens')->insert([
        'id' => (string) Str::ulid(),
        'tenant_id' => $other->id,
        'portal_account_id' => $account->id,
        'purpose' => PortalLoginToken::PURPOSE_INVITE,
        'token_hash' => hash('sha256', $crossPlain),
        'otp_hash' => bcrypt('123456'),
        'expires_at' => Carbon::now()->addMinutes(30),
        'consumed_at' => null,
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);

    return [
        'tenant' => $tenant,
        'other' => $other,
        'staff' => $staff,
        'patient' => $patient,
        'account' => $account,
        'otp' => $otp,
        'valid' => $valid,
        'expired' => $expiredPlain,
        'consumed' => $consumedPlain,
        'consumedOtp' => $consumedOtp,
        'crossTenant' => $crossPlain,
    ];
}

/** Redeem a token through the real page, exactly as a patient does. */
function pinRedeem($test, string $token, string $otp, string $password = 'a-new-password')
{
    pinCtx()->forget();

    return $test->post('/portal/invite/'.$token, [
        'otp' => $otp,
        'password' => $password,
        'password_confirmation' => $password,
    ]);
}

test('the four-token fixture is the one this gate needs, verified BY QUERY', function () {
    $fx = pinFixture();

    // The valid token: live, unconsumed, right purpose, in the right tenant.
    pinCtx()->set($fx['tenant']);
    $live = PortalLoginToken::query()->where('token_hash', hash('sha256', $fx['valid']))->firstOrFail();
    expect($live->consumed_at)->toBeNull()
        ->and($live->expires_at->isFuture())->toBeTrue()
        ->and($live->purpose)->toBe(PortalLoginToken::PURPOSE_INVITE)
        ->and($live->tenant_id)->toBe($fx['tenant']->id);

    // The expired one differs ONLY in its expiry.
    $expired = PortalLoginToken::query()->where('token_hash', hash('sha256', $fx['expired']))->firstOrFail();
    expect($expired->consumed_at)->toBeNull()
        ->and($expired->expires_at->isPast())->toBeTrue()
        ->and($expired->portal_account_id)->toBe($fx['account']->id);

    // The consumed one is not yet consumed — the SINGLE-USE test consumes it for real.
    $consumed = PortalLoginToken::query()->where('token_hash', hash('sha256', $fx['consumed']))->firstOrFail();
    expect($consumed->consumed_at)->toBeNull()
        ->and($consumed->expires_at->isFuture())->toBeTrue();

    // The cross-tenant one is LIVE — unexpired and unconsumed — and points at an account that is
    // NOT in its tenant. Without the tenant binding it would resolve like any other live token.
    $cross = DB::table('portal_login_tokens')->where('token_hash', hash('sha256', $fx['crossTenant']))->first();
    expect($cross)->not->toBeNull();
    expect($cross->tenant_id)->toBe($fx['other']->id)
        ->and($cross->consumed_at)->toBeNull()
        ->and($cross->portal_account_id)->toBe($fx['account']->id);
    expect(Carbon::parse($cross->expires_at)->isFuture())->toBeTrue();

    // The account is INVITED, not yet active — enrolment has something real to do.
    expect($fx['account']->refresh()->status)->toBe(PortalAccount::STATUS_INVITED)
        ->and($fx['account']->activated_at)->toBeNull();
});

test('a VALID token renders the landing page — practice, address, real expiry, and no clinical data', function () {
    $fx = pinFixture();

    pinCtx()->forget();
    $props = $this->get('/portal/invite/'.$fx['valid'])
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['valid'])->toBeTrue()
        ->and($props['email'])->toBe('erika.invite@example.test')
        ->and($props['practiceName'])->toBe('Alpha Clinic')
        ->and($props['token'])->toBe($fx['valid']);

    // The expiry shown is the ROW's, not the wireframe's "7 days".
    pinCtx()->set($fx['tenant']);
    $row = PortalLoginToken::query()->where('token_hash', hash('sha256', $fx['valid']))->firstOrFail();
    expect($props['expiresAt'])->toBe($row->expires_at->toIso8601String());

    /*
     * A GUEST page must disclose nothing clinical. The patient's own NAME is deliberately absent
     * too: the reader already holds the email, so the address adds nothing, while a name on a
     * token-addressed public URL would be a disclosure the flow does not need.
     */
    $body = strtolower(json_encode($props) ?: '');
    foreach (['baumgartner', 'erika ', 'mrn', 'diagnos', 'allerg', 'appointment', 'document', 'invoice', 'medication'] as $forbidden) {
        expect(str_contains($body, $forbidden))->toBeFalse("the guest invite page leaks '{$forbidden}'");
    }
});

test('enrolment runs through the EXISTING path: account activated, token consumed, audited once, no new consent invented', function () {
    $fx = pinFixture();

    pinRedeem($this, $fx['valid'], $fx['otp'])->assertRedirect(route('portal.home'));

    pinCtx()->set($fx['tenant']);
    $account = $fx['account']->refresh();

    expect($account->status)->toBe(PortalAccount::STATUS_ACTIVE)
        ->and($account->activated_at)->not->toBeNull()
        ->and($account->password)->not->toBeNull();

    // Single-use: the token this redemption used is now consumed.
    $used = PortalLoginToken::query()->where('token_hash', hash('sha256', $fx['valid']))->firstOrFail();
    expect($used->consumed_at)->not->toBeNull();

    // The EXISTING audit path, not a second one: the rows are the ones acceptInvite() always wrote.
    $rows = fn (string $action) => DB::table('audit_events')
        ->where('tenant_id', $fx['tenant']->id)->where('action', $action)->count();
    expect($rows('portal.first_login'))->toBe(1)
        ->and($rows('portal.login'))->toBe(1);

    /*
     * NO CONSENT IS INVENTED HERE (D-170). Enrolment captures none: `portal.access` is a
     * PRECONDITION — invite() refuses without it and acceptInvite() re-checks it — and consent
     * rows are staff-captured (`captured_by` is a NOT NULL user). The patient's consent set is
     * therefore exactly what it was before enrolment.
     */
    $consents = PatientConsent::query()->where('patient_id', $fx['patient']->id)->get();
    expect($consents)->toHaveCount(1);
    expect($consents->first()->template_key)->toBe('portal');
    expect($consents->first()->captured_by)->toBe($fx['staff']->id);

    // ...and the patient can now sign in with the password they just chose.
    pinCtx()->forget();
    $this->postJson(route('portal.login.attempt'), [
        'email' => 'erika.invite@example.test',
        'password' => 'a-new-password',
    ])->assertOk()->assertJsonPath('patient_id', $fx['patient']->id);
});

test('SINGLE-USE — replaying a token that WORKED a moment ago is refused', function () {
    $fx = pinFixture();

    /*
     * POSITIVE CONTROL (D-182), and the strongest form of it: the very same token, with the very
     * same code, SUCCEEDS on the first call. Nothing about the fixture explains the second
     * refusal except the consumption marker.
     */
    pinRedeem($this, $fx['consumed'], $fx['consumedOtp'])->assertRedirect(route('portal.home'));

    pinCtx()->set($fx['tenant']);
    expect(PortalLoginToken::query()->where('token_hash', hash('sha256', $fx['consumed']))->firstOrFail()->consumed_at)
        ->not->toBeNull();

    // The replay gets the generic refusal — not an error, not a hint.
    $replay = pinRedeem($this, $fx['consumed'], $fx['consumedOtp']);
    $replay->assertOk();
    expect($replay->viewData('page')['props']['valid'])->toBeFalse();

    // ...and the GET is refused the same way.
    pinCtx()->forget();
    $page = $this->get('/portal/invite/'.$fx['consumed'])->assertOk();
    expect($page->viewData('page')['props']['valid'])->toBeFalse();
});

test('EXPIRY — a token that is perfect except for its clock is refused', function () {
    $fx = pinFixture();

    pinCtx()->forget();
    $page = $this->get('/portal/invite/'.$fx['expired'])->assertOk();
    expect($page->viewData('page')['props']['valid'])->toBeFalse();

    $post = pinRedeem($this, $fx['expired'], '123456');
    $post->assertOk();
    expect($post->viewData('page')['props']['valid'])->toBeFalse();

    // POSITIVE CONTROL: move the clock back inside the window and the SAME token works — so the
    // expiry check, and nothing else about this row, is what refused it.
    pinCtx()->set($fx['tenant']);
    $row = PortalLoginToken::query()->where('token_hash', hash('sha256', $fx['expired']))->firstOrFail();

    Carbon::setTestNow($row->expires_at->copy()->subMinute());
    try {
        pinCtx()->forget();
        expect($this->get('/portal/invite/'.$fx['expired'])->viewData('page')['props']['valid'])->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }

    // Nothing was activated by the expired attempt.
    pinCtx()->set($fx['tenant']);
    expect($fx['account']->refresh()->status)->toBe(PortalAccount::STATUS_INVITED);
});

test('TENANT BINDING — a live token cannot reach an account outside its own tenant, even from a session in that other tenant', function () {
    $fx = pinFixture();

    /*
     * D-183: pinned where nothing outside can answer first. This route has no tenant middleware,
     * so there is no outer layer to refuse — and the request deliberately carries a session bound
     * to the account's OWN tenant, which is exactly the context that would let a leaked tenant win
     * if the service took the tenant from the session instead of from the token.
     */
    pinCtx()->forget();
    $page = $this->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get('/portal/invite/'.$fx['crossTenant'])
        ->assertOk();
    expect($page->viewData('page')['props']['valid'])->toBeFalse();

    $post = $this->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->post('/portal/invite/'.$fx['crossTenant'], [
            'otp' => '123456',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ]);
    $post->assertOk();
    expect($post->viewData('page')['props']['valid'])->toBeFalse();

    // POSITIVE CONTROL: the token is still live and still unconsumed — it was refused by the
    // binding, not by the clock, not by a marker, and nothing was activated.
    pinCtx()->set($fx['other']);
    $row = PortalLoginToken::query()->where('token_hash', hash('sha256', $fx['crossTenant']))->firstOrFail();
    expect($row->consumed_at)->toBeNull()
        ->and($row->expires_at->isFuture())->toBeTrue();

    pinCtx()->set($fx['tenant']);
    expect($fx['account']->refresh()->status)->toBe(PortalAccount::STATUS_INVITED);
});

test('NO ENUMERATION — unknown, expired, consumed and cross-tenant are IDENTICAL in status and body', function () {
    $fx = pinFixture();

    // Consume one for real first, so "consumed" is a genuine state and not a hand-set column.
    pinRedeem($this, $fx['consumed'], $fx['consumedOtp'])->assertRedirect(route('portal.home'));

    $cases = [
        'unknown' => Str::random(64),
        'expired' => $fx['expired'],
        'consumed' => $fx['consumed'],
        'crossTenant' => $fx['crossTenant'],
    ];

    $bodies = [];
    $statuses = [];
    foreach ($cases as $label => $token) {
        pinCtx()->forget();
        $response = $this->get('/portal/invite/'.$token);
        $statuses[$label] = $response->status();
        // The page JSON, with the URL stripped: the URL necessarily contains the token the visitor
        // already typed, and tells them nothing. EVERYTHING else must match.
        $page = $response->viewData('page');
        unset($page['url']);
        $bodies[$label] = json_encode($page);
    }

    expect(array_unique($statuses))->toHaveCount(1, 'the refusal cases differ in STATUS: '.json_encode($statuses));
    expect(array_unique($bodies))->toHaveCount(1, 'the refusal cases differ in BODY');

    // And nothing that could identify a patient, an account or a reason is in that one body.
    $body = strtolower((string) reset($bodies));
    foreach (['erika', 'baumgartner', 'example.test', 'alpha clinic', 'expired', 'consumed', 'tenant'] as $leak) {
        expect(str_contains($body, $leak))->toBeFalse("the generic refusal leaks '{$leak}'");
    }

    // The POST half is equally uniform.
    $postBodies = [];
    foreach ($cases as $token) {
        pinCtx()->forget();
        $response = $this->post('/portal/invite/'.$token, [
            'otp' => '123456', 'password' => 'a-new-password', 'password_confirmation' => 'a-new-password',
        ]);
        $page = $response->viewData('page');
        unset($page['url']);
        $postBodies[] = $response->status().'|'.json_encode($page);
    }
    expect(array_unique($postBodies))->toHaveCount(1, 'the POST refusal cases are distinguishable');
});

test('both invite routes are THROTTLED, at the established guest ceiling', function () {
    $fx = pinFixture();

    $middleware = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array($route->getName(), ['portal.invite.show', 'portal.invite.accept'], true))
        ->mapWithKeys(fn ($route) => [$route->getName() => $route->gatherMiddleware()]);

    expect($middleware)->toHaveCount(2);
    foreach ($middleware as $name => $stack) {
        expect(in_array('throttle:10,1', $stack, true))->toBeTrue("{$name} is not throttled at the guest ceiling");
    }

    // ...and it actually engages: the 11th request in a minute is refused.
    pinCtx()->forget();
    $statuses = [];
    for ($i = 0; $i < 11; $i++) {
        $statuses[] = $this->get('/portal/invite/'.$fx['expired'])->status();
    }

    expect(array_slice($statuses, 0, 10))->each->toBe(200);
    expect($statuses[10])->toBe(429);
});

test('the AUTH-SEC.2 guest smoke covers BOTH invite routes', function () {
    $source = (string) file_get_contents(base_path('tests/Feature/Smoke/RouteSmokeTest.php'));

    // The list is the thing that must contain them — a route that stops rendering has to fail the
    // smoke, and the smoke can only fail for a route it names.
    expect($source)->toContain("'portal.invite.show' => '/portal/invite/'.\$token");
    expect($source)->toContain("'portal.invite.accept' => ['/portal/invite/'.\$token");
});
