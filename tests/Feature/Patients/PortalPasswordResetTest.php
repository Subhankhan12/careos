<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Models\PortalLoginToken;
use Modules\Patients\Notifications\PortalPasswordResetNotification;
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
 * PT.P7 — the portal password-reset broker.
 *
 * Fortify's broker runs over `users`; portal accounts were never covered, so until this gate a
 * patient who forgot their password had to ask the practice to re-invite them.
 *
 * The properties under test are security properties, and two of them are about what the flow must
 * REFUSE TO SAY:
 *
 *   - the REQUEST form answers identically to four genuinely different subjects — a live account, an
 *     unknown address, a patient who was never invited, and a disabled account — because a public
 *     recovery form that answers differently is an account-enumeration oracle (D-185);
 *   - every dead RESET token renders one generic page: unknown, expired, already used, WRONG PURPOSE
 *     (an invite token), or bound to an account outside its own tenant.
 *
 * And the third: a reset changes exactly one thing. It does not activate a disabled account, does
 * not touch consent, and does not sign anybody in — so PT.P5's consent gate still decides who gets
 * in afterwards.
 */

beforeEach(function (): void {
    /*
     * D-186 — the throttle bucket survives between tests on CI (a real CACHE_STORE=redis beats
     * phpunit.xml's non-forced array setting, and nothing resets the cache the way RefreshDatabase
     * resets the database). This file makes well over ten requests to `throttle:10,1` routes, so it
     * would poison itself there. Start each test from a known bucket; the throttle is still
     * asserted, from a defined starting point.
     */
    Cache::store(config('cache.limiter'))->flush();
});

function pprCtx(): TenantContext
{
    return app(TenantContext::class);
}

function pprTenant(string $slug): Tenant
{
    return Tenant::query()->create([
        'name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active',
    ]);
}

function pprStaff(Tenant $tenant): User
{
    $staff = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $staff->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    return $staff;
}

function pprConsentedPatient(User $staff, string $first, string $last): Patient
{
    $patient = app(PatientService::class)->create([
        'first_name' => $first, 'last_name' => $last, 'date_of_birth' => '1971-09-19', 'sex' => 'female',
    ]);

    ConsentTemplate::query()->firstOrCreate(
        ['key' => 'portal', 'version' => 1],
        ['title' => 'Portal Access', 'body' => 'Portal access consent', 'scope_keys' => ['portal.access'], 'is_active' => true],
    );
    app(ConsentService::class)->grant($patient, 'portal', $first.' '.$last, $staff);

    return $patient;
}

/** Enrol a patient the real way: invite, then redeem, so the account is genuinely ACTIVE. */
function pprEnrol(Patient $patient, string $email, string $password): PortalAccount
{
    $portal = app(PortalAccessService::class);
    $invite = $portal->invite($patient, $email);

    return $portal->acceptInvite($invite->plainToken, $invite->otp, $password);
}

/**
 * FOUR SUBJECTS the request form must not tell apart, each a real row:
 *
 *   active   — a live account that WILL be sent a link;
 *   unknown  — an address belonging to nobody at all;
 *   noAccount— a real, consented patient who was never invited (no portal account exists);
 *   disabled — a real account that was switched off (recovery must not be a way back in).
 *
 * Plus a fifth in ANOTHER TENANT. The schema makes the gate's "same email in another tenant"
 * impossible — `portal_accounts.email` is globally unique — so the nearest equivalent is a live
 * account in tenant beta, which is what the tenant-binding tests use.
 *
 * @return array{tenant: Tenant, other: Tenant, staff: User, patient: Patient, account: PortalAccount, password: string, disabled: PortalAccount, noAccountPatient: Patient, otherAccount: PortalAccount, otherPatient: Patient}
 */
function pprFixture(): array
{
    Notification::fake();

    $tenant = pprTenant('alpha');
    $other = pprTenant('beta');

    pprCtx()->set($tenant);
    $staff = pprStaff($tenant);

    $patient = pprConsentedPatient($staff, 'Erika', 'Baumgartner');
    $password = 'the-old-password';
    $account = pprEnrol($patient, 'erika.reset@example.test', $password);

    // A real consented patient who was NEVER invited: no portal account exists for this address.
    $noAccountPatient = pprConsentedPatient($staff, 'Viktor', 'Odermatt');

    // A real account, switched off. It is otherwise complete — password set, activated — so only
    // the status check keeps it out of the flow.
    $disabledPatient = pprConsentedPatient($staff, 'Marta', 'Frei');
    $disabled = pprEnrol($disabledPatient, 'marta.disabled@example.test', 'another-old-password');
    $disabled->forceFill(['status' => PortalAccount::STATUS_DISABLED])->save();

    // A live account in the OTHER tenant.
    pprCtx()->set($other);
    $otherStaff = pprStaff($other);
    $otherPatient = pprConsentedPatient($otherStaff, 'Nadia', 'Steiner');
    $otherAccount = pprEnrol($otherPatient, 'nadia.beta@example.test', 'beta-old-password');

    /*
     * Enrolment logs the account into the patient guard (acceptInvite does, by design), so the
     * fixture signs OUT before handing back. Otherwise "the reset did not sign anyone in" would
     * be measuring the fixture rather than the reset — the assertion would be true before the
     * code under test ever ran.
     */
    Auth::guard('patient')->logout();
    pprCtx()->forget();

    return compact(
        'tenant', 'other', 'staff', 'patient', 'account', 'password',
        'disabled', 'noAccountPatient', 'otherAccount', 'otherPatient'
    );
}

/**
 * The plaintext token and code from the LAST reset email — captured the way the portal suite
 * already captures the invite email (`b5CapturedInvite`), through the on-demand mail route the
 * service really uses.
 */
function pprTokenFor(string $email): array
{
    $captured = [];

    Notification::assertSentOnDemand(
        PortalPasswordResetNotification::class,
        function (PortalPasswordResetNotification $notification, array $channels, object $notifiable) use (&$captured, $email): bool {
            // Several subjects may be emailed in one test; keep only the one addressed to $email,
            // and keep the LAST such mail, which is the live token after a supersede.
            if ($channels !== ['mail'] || ($notifiable->routes['mail'] ?? null) !== $email) {
                return false;
            }

            $captured = ['token' => $notification->token, 'otp' => $notification->otp];

            return true;
        }
    );

    expect($captured)->not->toBeEmpty("no reset email was sent to {$email}");

    return $captured;
}

/** Ask for a reset through the real guest form. */
function pprRequest($test, string $email)
{
    pprCtx()->forget();

    return $test->post('/portal/forgot-password', ['email' => $email]);
}

/** Complete a reset through the real guest form. */
function pprReset($test, string $token, string $otp, string $password = 'a-brand-new-password')
{
    pprCtx()->forget();

    return $test->post('/portal/reset/'.$token, [
        'otp' => $otp,
        'password' => $password,
        'password_confirmation' => $password,
    ]);
}

test('the four-subject fixture is the one this gate needs, verified BY QUERY', function () {
    $fx = pprFixture();

    pprCtx()->set($fx['tenant']);

    // ACTIVE: a real, enrolled, signed-in-capable account.
    expect($fx['account']->refresh()->status)->toBe(PortalAccount::STATUS_ACTIVE)
        ->and($fx['account']->password)->not->toBeNull()
        ->and($fx['account']->email)->toBe('erika.reset@example.test');

    // NO ACCOUNT: a real consented patient with no portal account at all.
    expect(PortalAccount::query()->where('patient_id', $fx['noAccountPatient']->id)->exists())->toBeFalse();
    expect(app(ConsentService::class)->has($fx['noAccountPatient'], 'portal.access'))->toBeTrue();

    // DISABLED: complete in every other way — only the status stands in the way.
    $disabled = $fx['disabled']->refresh();
    expect($disabled->status)->toBe(PortalAccount::STATUS_DISABLED)
        ->and($disabled->password)->not->toBeNull()
        ->and($disabled->activated_at)->not->toBeNull();

    // ANOTHER TENANT: a live account, in beta.
    pprCtx()->set($fx['other']);
    expect($fx['otherAccount']->refresh()->status)->toBe(PortalAccount::STATUS_ACTIVE)
        ->and($fx['otherAccount']->tenant_id)->toBe($fx['other']->id);

    /*
     * The gate asks for "another tenant with the SAME email if the schema allows it". It does not:
     * `portal_accounts.email` carries a global unique index, so one address can exist once across
     * the whole platform. This assertion pins that fact, so the choice above is documented by the
     * schema rather than by a comment.
     */
    pprCtx()->set($fx['tenant']);
    expect(fn () => PortalAccount::query()->create([
        'patient_id' => $fx['patient']->id,
        'email' => 'nadia.beta@example.test',
        'password' => bcrypt('x'),
        'status' => PortalAccount::STATUS_ACTIVE,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('NO ENUMERATION — the request form answers four different subjects IDENTICALLY', function () {
    $fx = pprFixture();

    $subjects = [
        'active' => 'erika.reset@example.test',
        'unknown' => 'nobody-at-all@example.test',
        'noAccount' => 'viktor.never-invited@example.test',
        'disabled' => 'marta.disabled@example.test',
        'otherTenant' => 'nadia.beta@example.test',
    ];

    $responses = [];
    foreach ($subjects as $label => $email) {
        $response = pprRequest($this, $email);
        // The redirect target and the flash are part of the answer: a status that differed by
        // subject would be as good an oracle as a different message.
        $responses[$label] = $response->status().'|'.($response->headers->get('Location') ?? '');
    }

    expect(array_unique($responses))->toHaveCount(1, 'the request form distinguishes subjects: '.json_encode($responses));

    // ...and the RENDERED page is identical too, since that is what a person actually sees.
    $bodies = [];
    foreach (['active' => $subjects['active'], 'unknown' => $subjects['unknown']] as $label => $email) {
        pprRequest($this, $email);
        pprCtx()->forget();
        $page = $this->get('/portal/forgot-password')->viewData('page');
        unset($page['url']);
        $bodies[$label] = json_encode($page);
    }
    expect(array_unique($bodies))->toHaveCount(1, 'the confirmation page differs by subject');

    /*
     * POSITIVE CONTROL (D-182/D-174): the responses are identical because they are BUILT identically,
     * not because nothing happened. Exactly one subject got an email — the live account — and the
     * disabled one did not, which is the behaviour the identical responses are concealing.
     */
    pprCtx()->set($fx['tenant']);
    $tokens = PortalLoginToken::query()->where('purpose', PortalLoginToken::PURPOSE_PASSWORD_RESET)->get();
    expect($tokens->pluck('portal_account_id')->unique()->all())->toBe([$fx['account']->id]);

    // Nothing about the subjects leaks into the page either.
    $body = strtolower((string) reset($bodies));
    foreach (['erika', 'baumgartner', 'example.test', 'alpha clinic', 'disabled', 'unknown'] as $leak) {
        expect(str_contains($body, $leak))->toBeFalse("the confirmation page leaks '{$leak}'");
    }
});

test('a reset sets the password through the existing path, stamps consumed_at, and audits once', function () {
    $fx = pprFixture();

    pprRequest($this, 'erika.reset@example.test')->assertRedirect(route('portal.password.request'));
    $link = pprTokenFor('erika.reset@example.test');

    // The landing page renders for a live token, and discloses no address.
    pprCtx()->forget();
    $props = $this->get('/portal/reset/'.$link['token'])->assertOk()->viewData('page')['props'];
    expect($props['valid'])->toBeTrue()
        ->and($props['practiceName'])->toBe('Alpha Clinic')
        ->and(array_key_exists('email', $props))->toBeFalse('the reset page discloses the address');

    pprReset($this, $link['token'], $link['otp'])->assertRedirect(route('portal.login'));

    pprCtx()->set($fx['tenant']);
    $account = $fx['account']->refresh();

    // The password really changed — old out, new in — through the model's hashed cast.
    expect(Hash::check('a-brand-new-password', (string) $account->password))->toBeTrue();
    expect(Hash::check($fx['password'], (string) $account->password))->toBeFalse();

    // Single-use marker stamped on the token that was used.
    $used = PortalLoginToken::query()->where('token_hash', hash('sha256', $link['token']))->firstOrFail();
    expect($used->consumed_at)->not->toBeNull();

    // Exactly ONE audit row on the existing path, with the patient attributed.
    $rows = DB::table('audit_events')
        ->where('tenant_id', $fx['tenant']->id)
        ->where('action', 'portal.password_reset')
        ->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->actor_type)->toBe('patient')
        ->and($rows->first()->patient_id)->toBe($fx['patient']->id);

    /*
     * NOT SIGNED IN BY THE RESET. Asserted as the property itself — nobody is on the patient
     * guard — rather than as a particular refusal shape: with no portal session the tenant
     * middleware refuses first with a 403, which proves the same thing but for a reason one
     * layer out. Both are checked.
     */
    pprCtx()->forget();
    expect(Auth::guard('patient')->check())->toBeFalse('the reset signed the patient in');
    expect($this->get(route('portal.home'))->status())->not->toBe(200);

    // ...and the new password works at the real sign-in.
    $this->postJson(route('portal.login.attempt'), [
        'email' => 'erika.reset@example.test',
        'password' => 'a-brand-new-password',
    ])->assertOk()->assertJsonPath('patient_id', $fx['patient']->id);
});

test('TOKEN HYGIENE — the raw token is never stored, and a new request supersedes the old link', function () {
    $fx = pprFixture();

    pprRequest($this, 'erika.reset@example.test');
    $first = pprTokenFor('erika.reset@example.test');

    pprCtx()->set($fx['tenant']);
    $stored = PortalLoginToken::query()
        ->where('purpose', PortalLoginToken::PURPOSE_PASSWORD_RESET)
        ->firstOrFail();

    // Only the hash is on the row, and the raw token appears in NO column of it.
    expect($stored->token_hash)->toBe(hash('sha256', $first['token']))
        ->and($stored->token_hash)->not->toBe($first['token']);
    $row = (array) DB::table('portal_login_tokens')->where('id', $stored->id)->first();
    foreach ($row as $column => $value) {
        expect(str_contains((string) $value, $first['token']))->toBeFalse("the raw token is stored in '{$column}'");
    }
    // The OTP is hashed too, not held in the clear.
    expect($stored->otp_hash)->not->toBe($first['otp']);

    // A SECOND request supersedes the first link.
    pprRequest($this, 'erika.reset@example.test');
    $second = pprTokenFor('erika.reset@example.test');
    expect($second['token'])->not->toBe($first['token']);

    // POSITIVE CONTROL (D-182): the NEW link works — so the old one below is refused by the
    // supersede rule, not by the flow being broken.
    $old = pprReset($this, $first['token'], $first['otp']);
    $old->assertOk();
    expect($old->viewData('page')['props']['valid'])->toBeFalse();

    pprReset($this, $second['token'], $second['otp'])->assertRedirect(route('portal.login'));
});

test('SINGLE-USE — replaying a token that WORKED a moment ago is refused', function () {
    pprFixture();

    pprRequest($this, 'erika.reset@example.test');
    $link = pprTokenFor('erika.reset@example.test');

    // POSITIVE CONTROL in its strongest form: the same token, same code, succeeds first.
    pprReset($this, $link['token'], $link['otp'])->assertRedirect(route('portal.login'));

    $replay = pprReset($this, $link['token'], $link['otp']);
    $replay->assertOk();
    expect($replay->viewData('page')['props']['valid'])->toBeFalse();

    pprCtx()->forget();
    $page = $this->get('/portal/reset/'.$link['token'])->assertOk();
    expect($page->viewData('page')['props']['valid'])->toBeFalse();
});

test('EXPIRY — a token that is perfect except for its clock is refused', function () {
    $fx = pprFixture();

    pprRequest($this, 'erika.reset@example.test');
    $link = pprTokenFor('erika.reset@example.test');

    pprCtx()->set($fx['tenant']);
    $row = PortalLoginToken::query()->where('token_hash', hash('sha256', $link['token']))->firstOrFail();
    $row->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();

    pprCtx()->forget();
    expect($this->get('/portal/reset/'.$link['token'])->viewData('page')['props']['valid'])->toBeFalse();

    $post = pprReset($this, $link['token'], $link['otp']);
    $post->assertOk();
    expect($post->viewData('page')['props']['valid'])->toBeFalse();

    // POSITIVE CONTROL: wind the clock back inside the window and the SAME token works.
    Carbon::setTestNow($row->expires_at->copy()->subMinute());
    try {
        pprCtx()->forget();
        expect($this->get('/portal/reset/'.$link['token'])->viewData('page')['props']['valid'])->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }

    // Nothing changed while it was refused.
    pprCtx()->set($fx['tenant']);
    expect(Hash::check($fx['password'], (string) $fx['account']->refresh()->password))->toBeTrue();
});

test('PURPOSE SCOPING — an invite token cannot reset a password, and a reset token cannot enrol', function () {
    $fx = pprFixture();

    // A live INVITE token for a real patient.
    pprCtx()->set($fx['tenant']);
    $invite = app(PortalAccessService::class)->invite($fx['noAccountPatient'], 'viktor.invite@example.test');

    // A live RESET token for the enrolled account.
    pprRequest($this, 'erika.reset@example.test');
    $reset = pprTokenFor('erika.reset@example.test');

    /*
     * POSITIVE CONTROL (D-182): both tokens are live RIGHT NOW on their own routes. Only the purpose
     * clause refuses each on the other's route — without it, both would resolve.
     */
    pprCtx()->forget();
    expect($this->get('/portal/invite/'.$invite->plainToken)->viewData('page')['props']['valid'])->toBeTrue();
    pprCtx()->forget();
    expect($this->get('/portal/reset/'.$reset['token'])->viewData('page')['props']['valid'])->toBeTrue();

    // Crossed over, each is refused exactly like a token that never existed.
    pprCtx()->forget();
    expect($this->get('/portal/reset/'.$invite->plainToken)->viewData('page')['props']['valid'])->toBeFalse();
    pprCtx()->forget();
    expect($this->get('/portal/invite/'.$reset['token'])->viewData('page')['props']['valid'])->toBeFalse();

    // ...and on the POST halves too, where the damage would actually be done.
    $crossReset = pprReset($this, $invite->plainToken, $invite->otp);
    $crossReset->assertOk();
    expect($crossReset->viewData('page')['props']['valid'])->toBeFalse();

    pprCtx()->forget();
    $crossInvite = $this->post('/portal/invite/'.$reset['token'], [
        'otp' => $reset['otp'], 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
    ]);
    $crossInvite->assertOk();
    expect($crossInvite->viewData('page')['props']['valid'])->toBeFalse();

    // Neither crossed attempt changed anything.
    pprCtx()->set($fx['tenant']);
    expect(Hash::check($fx['password'], (string) $fx['account']->refresh()->password))->toBeTrue();
    expect(PortalAccount::query()->where('patient_id', $fx['noAccountPatient']->id)->firstOrFail()->status)
        ->toBe(PortalAccount::STATUS_INVITED);
});

test('TENANT BINDING — a reset token resolves in its OWN tenant, even from a session in another', function () {
    $fx = pprFixture();

    // A live reset token for the BETA account.
    pprRequest($this, 'nadia.beta@example.test');
    $link = pprTokenFor('nadia.beta@example.test');

    /*
     * D-183 — pinned where nothing outside can answer first. These routes carry no tenant
     * middleware, and the request deliberately carries a session bound to the OTHER tenant (alpha),
     * which is exactly the context that would win if the service took the tenant from the session
     * instead of from the token.
     */
    pprCtx()->forget();
    $props = $this->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get('/portal/reset/'.$link['token'])
        ->assertOk()
        ->viewData('page')['props'];

    // It resolves as BETA's — not alpha's, and not refused.
    expect($props['valid'])->toBeTrue()
        ->and($props['practiceName'])->toBe('Beta Clinic');

    pprCtx()->forget();
    $this->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->post('/portal/reset/'.$link['token'], [
            'otp' => $link['otp'], 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('portal.login'));

    // BETA's account changed; ALPHA's — the tenant in the session — did not.
    pprCtx()->set($fx['other']);
    expect(Hash::check('a-brand-new-password', (string) $fx['otherAccount']->refresh()->password))->toBeTrue();

    pprCtx()->set($fx['tenant']);
    expect(Hash::check($fx['password'], (string) $fx['account']->refresh()->password))->toBeTrue();
});

test('TENANT BINDING — a token cannot reach an account outside its own tenant', function () {
    $fx = pprFixture();

    /*
     * THE SECOND HALF OF THE BINDING, and the half a mutation caught me missing.
     *
     * The test above proves the tenant comes from the TOKEN rather than the session — but a lookup
     * that ignored tenancy altogether would satisfy it too, because an account id is globally
     * unique and resolves either way. What it did NOT prove is the case where the token's tenant
     * and its account's tenant DISAGREE: only a lookup scoped to the token's own tenant refuses
     * that one. Replacing the scoped lookup with an unscoped one left the earlier test green.
     *
     * So: a live, unexpired, unconsumed reset token in tenant BETA pointing at ALPHA's account. The
     * model's own guard refuses to create such a row, which is a first line of defence; the row is
     * therefore forced in at the DB level so the SECOND line — resolving inside the token's tenant —
     * is what is actually under test (D-183).
     */
    $crossPlain = Str::random(64);
    DB::table('portal_login_tokens')->insert([
        'id' => (string) Str::ulid(),
        'tenant_id' => $fx['other']->id,
        'portal_account_id' => $fx['account']->id,
        'purpose' => PortalLoginToken::PURPOSE_PASSWORD_RESET,
        'token_hash' => hash('sha256', $crossPlain),
        'otp_hash' => bcrypt('123456'),
        'expires_at' => Carbon::now()->addMinutes(30),
        'consumed_at' => null,
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);

    // POSITIVE CONTROL (D-182): the row is LIVE — unexpired, unconsumed, right purpose — so nothing
    // but the binding can be what refuses it.
    $row = DB::table('portal_login_tokens')->where('token_hash', hash('sha256', $crossPlain))->first();
    expect($row)->not->toBeNull();
    expect($row->consumed_at)->toBeNull()
        ->and($row->tenant_id)->toBe($fx['other']->id)
        ->and($row->portal_account_id)->toBe($fx['account']->id);
    expect(Carbon::parse($row->expires_at)->isFuture())->toBeTrue();

    pprCtx()->forget();
    expect($this->get('/portal/reset/'.$crossPlain)->viewData('page')['props']['valid'])->toBeFalse();

    $post = pprReset($this, $crossPlain, '123456');
    $post->assertOk();
    expect($post->viewData('page')['props']['valid'])->toBeFalse();

    // Neither account moved, and the token is still sitting there unconsumed.
    pprCtx()->set($fx['tenant']);
    expect(Hash::check($fx['password'], (string) $fx['account']->refresh()->password))->toBeTrue();
    expect(DB::table('portal_login_tokens')->where('token_hash', hash('sha256', $crossPlain))->first()->consumed_at)->toBeNull();
});

test('A RESET GRANTS NOTHING — a withdrawn-consent patient can reset and is STILL refused at login', function () {
    $fx = pprFixture();

    // POSITIVE CONTROL (D-182): this account signs in perfectly well right now.
    pprCtx()->forget();
    $this->postJson(route('portal.login.attempt'), [
        'email' => 'erika.reset@example.test', 'password' => $fx['password'],
    ])->assertOk();

    // The patient withdraws portal access — the PT.P5 path, with a reason.
    pprCtx()->set($fx['tenant']);
    $consent = PatientConsent::query()
        ->where('patient_id', $fx['patient']->id)
        ->where('status', PatientConsent::STATUS_GRANTED)
        ->firstOrFail();
    app(ConsentService::class)->withdraw($consent, 'I no longer want an online account.');

    // The reset itself still works — recovery is not the thing consent gates.
    pprRequest($this, 'erika.reset@example.test');
    $link = pprTokenFor('erika.reset@example.test');
    pprReset($this, $link['token'], $link['otp'])->assertRedirect(route('portal.login'));

    pprCtx()->set($fx['tenant']);
    expect(Hash::check('a-brand-new-password', (string) $fx['account']->refresh()->password))->toBeTrue();

    /*
     * ...and it bought them nothing. The consent gate in login() still refuses, so a recovery flow
     * cannot be used to get past a gate PT.P5 built. Pinned at the SERVICE, called directly, because
     * over HTTP the portal-consent middleware would refuse first (D-183).
     */
    pprCtx()->forget();
    expect(fn () => app(PortalAccessService::class)->login('erika.reset@example.test', 'a-brand-new-password'))
        ->toThrow(AuthorizationException::class);

    // The consent was not re-granted, and the account was not otherwise touched.
    pprCtx()->set($fx['tenant']);
    expect(app(ConsentService::class)->has($fx['patient'], 'portal.access'))->toBeFalse();
    expect(PatientConsent::query()->where('patient_id', $fx['patient']->id)->where('status', PatientConsent::STATUS_GRANTED)->count())->toBe(0);
});

test('A RESET DOES NOT REVIVE A DISABLED ACCOUNT', function () {
    $fx = pprFixture();

    // The disabled account is a real, complete account — only its status is off.
    pprRequest($this, 'marta.disabled@example.test')->assertRedirect(route('portal.password.request'));

    pprCtx()->set($fx['tenant']);

    // No token was issued for it at all, so there is no link to follow.
    $tokens = PortalLoginToken::query()
        ->where('portal_account_id', $fx['disabled']->id)
        ->where('purpose', PortalLoginToken::PURPOSE_PASSWORD_RESET)
        ->count();
    expect($tokens)->toBe(0);

    // ...and it is still disabled, still holding its old password.
    $disabled = $fx['disabled']->refresh();
    expect($disabled->status)->toBe(PortalAccount::STATUS_DISABLED);
    expect(Hash::check('another-old-password', (string) $disabled->password))->toBeTrue();
});

test('both recovery routes are THROTTLED, at the established guest ceiling', function () {
    pprFixture();

    $middleware = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array($route->getName(), [
            'portal.password.request', 'portal.password.send',
            'portal.password.reset', 'portal.password.update',
        ], true))
        ->mapWithKeys(fn ($route) => [$route->getName() => $route->gatherMiddleware()]);

    expect($middleware)->toHaveCount(4);
    foreach ($middleware as $name => $stack) {
        expect(in_array('throttle:10,1', $stack, true))->toBeTrue("{$name} is not throttled at the guest ceiling");
    }

    // ...and it engages: the 11th request in a minute is refused.
    pprCtx()->forget();
    $statuses = [];
    for ($i = 0; $i < 11; $i++) {
        $statuses[] = $this->get('/portal/forgot-password')->status();
    }

    expect(array_slice($statuses, 0, 10))->each->toBe(200);
    expect($statuses[10])->toBe(429);
});

test('the AUTH-SEC.2 guest smoke covers the recovery routes', function () {
    $source = (string) file_get_contents(base_path('tests/Feature/Smoke/RouteSmokeTest.php'));

    expect($source)->toContain("'portal.password.request' => '/portal/forgot-password'");
    expect($source)->toContain("'portal.password.reset' => '/portal/reset/'.\$token");
    expect($source)->toContain("'portal.password.update' => ['/portal/reset/'.\$token");
    expect($source)->toContain("\$this->post('/portal/forgot-password', ['email' => 'nobody@example.test'])");
});

test('THE FENCE: no clinical data and no patient identity on any recovery response', function () {
    $fx = pprFixture();

    pprRequest($this, 'erika.reset@example.test');
    $link = pprTokenFor('erika.reset@example.test');

    $payloads = [];
    pprCtx()->forget();
    $payloads['forgot'] = $this->get('/portal/forgot-password')->viewData('page')['props'];
    pprCtx()->forget();
    $payloads['reset'] = $this->get('/portal/reset/'.$link['token'])->viewData('page')['props'];
    pprCtx()->forget();
    $payloads['refused'] = $this->get('/portal/reset/'.Str::random(64))->viewData('page')['props'];

    // POSITIVE CONTROL: the reset page really did render its valid state, so the scan has content.
    expect($payloads['reset']['valid'])->toBeTrue();

    foreach ($payloads as $label => $props) {
        $body = strtolower(json_encode($props) ?: '');
        foreach ([
            'erika', 'baumgartner', 'mrn', 'date_of_birth', 'diagnos', 'allerg', 'medication',
            'appointment', 'invoice', 'document', 'riskscore', 'severity',
        ] as $forbidden) {
            expect(str_contains($body, $forbidden))->toBeFalse("the {$label} response leaks '{$forbidden}'");
        }
    }

    // D-173 — the scan follows the files, and fails loudly if one moves out from under it.
    foreach ([
        'resources/js/pages/Portal/Password/Forgot.vue',
        'resources/js/pages/Portal/Password/Reset.vue',
    ] as $path) {
        expect(file_exists(base_path($path)))->toBeTrue("{$path} is missing — this fence would scan nothing");
    }

    /*
     * D-179 — the request page may never claim an email was sent, because for most subjects none
     * was. The conditional wording is the assertion.
     */
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    $sent = strtolower((string) $en['portal']['password']['sentBody']);
    expect($sent)->toContain('if an account exists');
    foreach (["we've emailed you", 'we have emailed you', 'we sent you'] as $overclaim) {
        expect(str_contains($sent, $overclaim))->toBeFalse("the confirmation claims an action not taken: '{$overclaim}'");
    }

    expect($fx['account']->email)->toBe('erika.reset@example.test');
});
