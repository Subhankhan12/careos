<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\Plan;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\FeatureService;
use Modules\Platform\Services\RbacProvisioner;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * DEPLOY.PROV — first-customer provisioning (closes pre-deploy blockers M1–M4).
 *
 * Before this gate there was NO way to create a real tenant (Tenant::create existed
 * only inside the demo seeders) and no way to create its FIRST org_admin (every other
 * path runs through StaffInviteService, which needs an already-authenticated admin).
 * The runbook's "create their tenant" step had no mechanism behind it.
 *
 * These tests pin the provisioning path itself: a real tenant with its role templates,
 * the first-admin bootstrap, mandatory 2FA still applying to that admin, plans resolving
 * real features instead of silently returning false, and — importantly — that the
 * production seed path still cannot reach a demo seeder.
 *
 * These tests ADD coverage; no existing behaviour test was modified.
 */

function provCtx(): TenantContext
{
    return app(TenantContext::class);
}

// ── M1: tenant:create ───────────────────────────────────────────────────────────────

test('tenant:create makes a real tenant with all starter role templates seeded (26)', function () {
    $this->artisan('plans:seed')->assertSuccessful();

    $this->artisan('tenant:create', [
        'name' => 'Praxis Example',
        '--slug' => 'praxis-example',
        '--plan' => 'eu_pro',
        '--currency' => 'CHF',
        '--locale' => 'de',
        '--timezone' => 'Europe/Zurich',
    ])->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'praxis-example')->firstOrFail();

    expect($tenant->name)->toBe('Praxis Example')
        ->and($tenant->region)->toBe('eu')
        ->and($tenant->status)->toBe('active')          // a real customer, not 'provisioning'
        ->and($tenant->plan_id)->not->toBeNull();

    // The Tenant::created hook fired: every starter template exists for THIS tenant.
    $roles = provCtx()->system(fn () => Role::query()->where('tenant_id', $tenant->getKey())->pluck('key'));

    expect($roles)->toHaveCount(count(RbacProvisioner::ROLE_TEMPLATES))
        ->and($roles)->toContain('org_admin')
        ->and($roles)->toContain('doctor')
        ->and($roles)->toContain('reception');

    // Locale/currency/timezone landed in the tenant's own settings.
    provCtx()->set($tenant);
    $settings = app(SettingsService::class);
    expect($settings->get('currency'))->toBe('CHF')
        ->and($settings->get('locale'))->toBe('de')
        ->and($settings->get('timezone'))->toBe('Europe/Zurich');
    provCtx()->forget();

    // It is a REAL, MINIMAL tenant — emphatically not demo data.
    expect(provCtx()->system(fn () => Patient::query()->count()))->toBe(0);
});

test('tenant:create REFUSES a duplicate slug rather than half-creating', function () {
    $this->artisan('plans:seed')->assertSuccessful();
    $this->artisan('tenant:create', ['name' => 'Alpha Clinic', '--slug' => 'alpha-clinic'])->assertSuccessful();

    $this->artisan('tenant:create', ['name' => 'Alpha Again', '--slug' => 'alpha-clinic'])
        ->expectsOutputToContain('already exists')
        ->assertFailed();

    expect(Tenant::query()->where('slug', 'alpha-clinic')->count())->toBe(1)
        ->and(Tenant::query()->where('name', 'Alpha Again')->exists())->toBeFalse();
});

test('tenant:create validates its input and refuses an unknown plan without creating anything', function () {
    $this->artisan('plans:seed')->assertSuccessful();

    $this->artisan('tenant:create', ['name' => 'Bad Region', '--slug' => 'bad-region', '--region' => 'mars'])
        ->assertFailed();

    $this->artisan('tenant:create', ['name' => 'Bad Plan', '--slug' => 'bad-plan', '--plan' => 'nope'])
        ->expectsOutputToContain('Unknown plan')
        ->assertFailed();

    expect(Tenant::query()->count())->toBe(0);
});

// ── M2: tenant:add-admin — the chicken-and-egg ──────────────────────────────────────

test('tenant:add-admin creates the FIRST org_admin with NO pre-existing admin needed', function () {
    $this->artisan('plans:seed')->assertSuccessful();
    $this->artisan('tenant:create', ['name' => 'Bootstrap Clinic', '--slug' => 'bootstrap-clinic'])
        ->assertSuccessful();

    // No users exist at all — this is precisely what StaffInviteService cannot do.
    expect(User::query()->count())->toBe(0);

    $this->artisan('tenant:add-admin', [
        'tenant' => 'bootstrap-clinic',
        '--email' => 'anna.vogt@example.test',
        '--name' => 'Dr Anna Vogt',
        '--password' => 'bootstrap-password',
    ])->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'bootstrap-clinic')->firstOrFail();
    $user = User::query()->where('email', 'anna.vogt@example.test')->firstOrFail();

    expect($user->tenant_id)->toBe($tenant->getKey())
        ->and($user->name)->toBe('Dr Anna Vogt')
        // The password was hash-cast, never stored in the clear.
        ->and($user->password)->not->toBe('bootstrap-password');

    // The REAL RBAC path: an org_admin assignment across ALL branches.
    $assignment = provCtx()->system(fn () => RoleAssignment::query()
        ->where('user_id', $user->getKey())->firstOrFail());
    $role = provCtx()->system(fn () => Role::query()->findOrFail($assignment->role_id));

    expect($role->key)->toBe('org_admin')
        ->and($assignment->branch_id)->toBeNull();

    // And the permission actually resolves through the real Gate.
    provCtx()->set($tenant);
    expect($user->hasPermission('admin.manage'))->toBeTrue();
    provCtx()->forget();
});

test('tenant:add-admin REFUSES a second admin — it bootstraps, it is not a user-management tool', function () {
    $this->artisan('plans:seed')->assertSuccessful();
    $this->artisan('tenant:create', ['name' => 'Once Clinic', '--slug' => 'once-clinic'])->assertSuccessful();

    $this->artisan('tenant:add-admin', [
        'tenant' => 'once-clinic', '--email' => 'first@example.test', '--name' => 'First',
        '--password' => 'pw-first',
    ])->assertSuccessful();

    $this->artisan('tenant:add-admin', [
        'tenant' => 'once-clinic', '--email' => 'second@example.test', '--name' => 'Second',
        '--password' => 'pw-second',
    ])->expectsOutputToContain('already has')->assertFailed();

    expect(User::query()->where('email', 'second@example.test')->exists())->toBeFalse()
        ->and(User::query()->count())->toBe(1);
});

test('tenant:add-admin refuses an unknown tenant, a bad email and a duplicate email', function () {
    $this->artisan('plans:seed')->assertSuccessful();
    $this->artisan('tenant:create', ['name' => 'Guard Clinic', '--slug' => 'guard-clinic'])->assertSuccessful();
    $this->artisan('tenant:create', ['name' => 'Other Clinic', '--slug' => 'other-clinic'])->assertSuccessful();

    $this->artisan('tenant:add-admin', ['tenant' => 'no-such-tenant', '--email' => 'a@b.test', '--name' => 'A'])
        ->assertFailed();

    $this->artisan('tenant:add-admin', ['tenant' => 'guard-clinic', '--email' => 'not-an-email', '--name' => 'A'])
        ->assertFailed();

    $this->artisan('tenant:add-admin', [
        'tenant' => 'guard-clinic', '--email' => 'taken@example.test', '--name' => 'Taken', '--password' => 'pw',
    ])->assertSuccessful();

    // Email is globally unique — the same address cannot be reused in another tenant.
    $this->artisan('tenant:add-admin', [
        'tenant' => 'other-clinic', '--email' => 'taken@example.test', '--name' => 'Dup', '--password' => 'pw',
    ])->expectsOutputToContain('already exists')->assertFailed();

    expect(User::query()->where('email', 'taken@example.test')->count())->toBe(1);
});

test('the bootstrapped admin is STILL subject to mandatory 2FA — first login forces enrolment', function () {
    $this->artisan('plans:seed')->assertSuccessful();
    $this->artisan('tenant:create', ['name' => 'MFA Clinic', '--slug' => 'mfa-clinic'])->assertSuccessful();
    $this->artisan('tenant:add-admin', [
        'tenant' => 'mfa-clinic', '--email' => 'mfa@example.test', '--name' => 'MFA Admin', '--password' => 'pw',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'mfa@example.test')->firstOrFail();

    // The command created NO two-factor secret — it cannot hand out a 2FA-exempt account.
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();

    Route::middleware(['web', 'auth'])->get('/_prov_probe', fn () => response('in'));

    // So the mandatory-2FA gate sends them to enrolment, not into the app.
    $this->actingAs($user)->get('/_prov_probe')->assertRedirect(route('two-factor.enrollment'));
    $this->actingAs($user)->get('/two-factor/enrollment')->assertOk();
});

// ── M3: plans:seed + the FeatureService silent-off fix ──────────────────────────────

test('plans:seed is idempotent and makes FeatureService resolve REAL features, not false', function () {
    // THE SILENT FAILURE: a tenant with no plan has every feature off.
    $planless = Tenant::query()->create([
        'name' => 'Planless', 'slug' => 'planless', 'region' => 'eu', 'status' => 'active',
    ]);

    provCtx()->set($planless);
    expect(app(FeatureService::class)->enabled('telehealth'))->toBeFalse();
    provCtx()->forget();

    $this->artisan('plans:seed')->assertSuccessful();
    $first = Plan::query()->count();

    // Idempotent: a second run updates in place and duplicates nothing.
    $this->artisan('plans:seed')->assertSuccessful();
    expect(Plan::query()->count())->toBe($first)
        ->and($first)->toBeGreaterThan(0);

    // A tenant created WITH a plan now resolves the plan's real feature set.
    $this->artisan('tenant:create', ['name' => 'Pro Clinic', '--slug' => 'pro-clinic', '--plan' => 'eu_pro'])
        ->assertSuccessful();

    provCtx()->set(Tenant::query()->where('slug', 'pro-clinic')->firstOrFail());
    expect(app(FeatureService::class)->enabled('telehealth'))->toBeTrue()      // eu_pro: on
        ->and(app(FeatureService::class)->enabled('ai_drafting'))->toBeTrue()
        ->and(app(FeatureService::class)->enabled('evv'))->toBeFalse();        // honestly off in eu_pro
    provCtx()->forget();

    // And a starter-plan tenant resolves ITS real (narrower) set — not a blanket false.
    $this->artisan('tenant:create', ['name' => 'Starter Clinic', '--slug' => 'starter-clinic', '--plan' => 'eu_starter'])
        ->assertSuccessful();

    provCtx()->set(Tenant::query()->where('slug', 'starter-clinic')->firstOrFail());
    expect(app(FeatureService::class)->enabled('telehealth'))->toBeFalse();
    provCtx()->forget();
});

// ── The production seed path stays demo-free ────────────────────────────────────────

test('the production seed path cannot reach a demo seeder', function () {
    // db:seed --force runs DatabaseSeeder, which calls ONLY the catalogs. If a demo
    // seeder were ever added there, a customer instance would silently grow a fake
    // clinic — so this pins the safety property rather than trusting convention.
    $body = (string) file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

    expect($body)->not->toContain('Demo');

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect(Tenant::query()->count())->toBe(0)                 // no demo tenant appeared
        ->and(Plan::query()->count())->toBeGreaterThan(0);    // the catalogs DID seed
});
