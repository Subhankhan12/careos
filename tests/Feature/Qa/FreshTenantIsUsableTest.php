<?php

use App\Services\BranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * DEPLOY-FIX.1a — a freshly provisioned tenant has a usable day-board.
 *
 * THE DEFECT, MEASURED BY A PROVISIONING DRY RUN. After the documented sequence — migrate → db:seed →
 * plans:seed → tenant:create → tenant:add-admin → login → 2FA — the tenant had 0 branches, and
 * `DayBoardController` resolved its branch with `firstOrFail()`. The reception home screen of a brand-new
 * customer returned **HTTP 404** on day one.
 *
 * TWO HALVES, AND THEY ARE INDEPENDENT:
 *   1. The 404 itself. A tenant with no ACTIVE branch is a STATE, not an error — and a MATURE tenant
 *      reaches it too, because `BranchController::deactivate` refuses only when future appointments
 *      exist. So the board renders an honest empty state instead.
 *   2. The provisioning gap. `tenant:add-branch` lets the documented sequence produce a usable tenant
 *      without anyone opening a browser first.
 *
 * D-182: every test below fails on the pre-fix code — the day-board ones with a 404, the command ones
 * because the command did not exist.
 */

function dfCtx(): TenantContext
{
    return app(TenantContext::class);
}

/**
 * A tenant provisioned the way the runbook provisions one — NON-UTC on purpose (D-174).
 *
 * `plans:seed` FIRST, and that ordering is not incidental: on a clean database `tenant:create` refuses
 * with *"Unknown plan [eu_pro] — No plans exist yet"*. That refusal is M3's fix working (a tenant with no
 * plan has every feature silently OFF), and it is exactly the order the runbook documents:
 * migrate → db:seed → plans:seed → tenant:create.
 */
function dfTenant(string $slug = 'fresh-clinic', string $timezone = 'Europe/Zurich'): Tenant
{
    Artisan::call('plans:seed');

    Artisan::call('tenant:create', [
        'name' => 'Fresh Clinic',
        '--slug' => $slug,
        '--timezone' => $timezone,
        '--locale' => 'de',
        '--currency' => 'CHF',
    ]);

    return Tenant::query()->where('slug', $slug)->firstOrFail();
}

/** The tenant's first administrator, through the real bootstrap command. */
function dfAdmin(Tenant $tenant): User
{
    Artisan::call('tenant:add-admin', [
        'tenant' => $tenant->slug,
        '--email' => 'admin@fresh-clinic.test',
        '--name' => 'Dr. Fresh',
    ]);

    $user = User::query()->where('email', 'admin@fresh-clinic.test')->firstOrFail();

    // The board is behind mandatory 2FA; this test is about the board, not the enrolment gate.
    $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_secret' => encrypt('x')])->save();

    return $user;
}

/** A user holding `appointment.manage` but NOT `admin.manage` — reception's shape. */
function dfReception(Tenant $tenant): User
{
    dfCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'reception')->firstOrFail();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id]);

    return $user;
}

it('the documented provisioning sequence leaves a tenant with NO branch', function () {
    $tenant = dfTenant();
    dfCtx()->set($tenant);

    /*
     * THE PRECONDITION OF THE WHOLE FINDING, pinned so it cannot quietly change. If a future gate makes
     * tenant:create seed a branch, this fails and the decision gets revisited deliberately rather than
     * two commands silently both creating one.
     */
    expect(Branch::query()->count())->toBe(0);
});

it('renders the day-board with an honest empty state instead of a 404 when there is no branch', function () {
    $tenant = dfTenant();
    $admin = dfAdmin($tenant);

    /*
     * BEFORE THE FIX THIS WAS A 404 — `firstOrFail()` on zero branches. Asserting the component renders
     * is the whole point: a 404 is indistinguishable, to the person looking at it, from a broken deploy.
     */
    $this->actingAs($admin)
        ->get('/scheduling/day-board')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Scheduling/DayBoard')
            ->where('filters.branch_id', null)
            ->where('branches', [])
            ->where('resources', [])
            ->where('counts.total', 0)
        );
});

it('still renders the empty state for a role that cannot create a branch', function () {
    $tenant = dfTenant();
    $reception = dfReception($tenant);

    /*
     * The page decides the CALL TO ACTION from `admin.manage`, which reception does not hold — so it sees
     * the state without a link it would 403 on (D-214). The server's job is only to not 404; it must not
     * refuse a role that legitimately opens the board every morning.
     */
    $this->actingAs($reception)
        ->get('/scheduling/day-board')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Scheduling/DayBoard')
            ->where('filters.branch_id', null)
        );
});

it('a MATURE tenant that deactivates its only branch gets the same empty state, not a 404', function () {
    $tenant = dfTenant();
    $admin = dfAdmin($tenant);
    dfCtx()->set($tenant);

    $branch = app(BranchService::class)->create(['name' => 'Only Site', 'code' => 'ONLY']);
    $branch->forceFill(['active' => false])->save();

    /*
     * THIS IS WHY THE 404 FIX IS NOT MERELY A PROVISIONING CONCERN. `BranchController::deactivate`
     * refuses only when FUTURE APPOINTMENTS exist, so a practice with a quiet calendar can deactivate
     * its last site and land on exactly the same 404 years after onboarding.
     */
    $this->actingAs($admin)
        ->get('/scheduling/day-board')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.branch_id', null));
});

it('tenant:add-branch creates a usable first branch that takes the TENANT timezone, not UTC', function () {
    $tenant = dfTenant(timezone: 'Europe/Zurich');
    $admin = dfAdmin($tenant);

    $exit = Artisan::call('tenant:add-branch', [
        'tenant' => $tenant->slug,
        '--name' => 'Hauptstandort',
        '--code' => 'HAUPT',
    ]);

    expect($exit)->toBe(0);

    dfCtx()->set($tenant);
    $branch = Branch::query()->firstOrFail();

    /*
     * THE FIXTURE IS NON-UTC ON PURPOSE (D-174). A Europe/Zurich tenant is the only way this assertion
     * means anything: `Branch::$attributes` defaults `timezone` to 'UTC', so a UTC fixture would pass
     * against the very default the fix exists to override. The BRANCH timezone is what the booking
     * engine reads, so this is a behaviour difference, not a cosmetic one.
     */
    expect($branch->timezone)->toBe('Europe/Zurich')
        ->and($branch->name)->toBe('Hauptstandort')
        ->and($branch->code)->toBe('HAUPT');

    // And the board it exists for now renders a real branch.
    $this->actingAs($admin)
        ->get('/scheduling/day-board')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.branch_id', $branch->id));
});

it('honours an explicit --timezone over the tenant setting', function () {
    $tenant = dfTenant(timezone: 'Europe/Zurich');

    Artisan::call('tenant:add-branch', [
        'tenant' => $tenant->slug,
        '--name' => 'Aussenstelle',
        '--code' => 'AUS',
        '--timezone' => 'Europe/Berlin',
    ]);

    dfCtx()->set($tenant);

    expect(Branch::query()->firstOrFail()->timezone)->toBe('Europe/Berlin');
});

it('keeps the exactly-one-primary invariant across several branches', function () {
    $tenant = dfTenant();

    foreach ([['A', 'AAA'], ['B', 'BBB'], ['C', 'CCC']] as [$name, $code]) {
        Artisan::call('tenant:add-branch', ['tenant' => $tenant->slug, '--name' => $name, '--code' => $code]);
    }

    dfCtx()->set($tenant);

    /*
     * The command does NOT pass `is_primary` — `Branch::booted()` owns the invariant on every creation
     * path. `is_primary` IS fillable and `BranchService::create` passes its payload straight through, so
     * a command that set the flag itself would be the one way to end up with two primaries.
     */
    expect(Branch::query()->count())->toBe(3)
        ->and(Branch::query()->where('is_primary', true)->count())->toBe(1)
        ->and(Branch::query()->where('is_primary', true)->firstOrFail()->name)->toBe('A');
});

it('refuses a duplicate code within the tenant, and refuses an unknown timezone', function () {
    $tenant = dfTenant();

    Artisan::call('tenant:add-branch', ['tenant' => $tenant->slug, '--name' => 'First', '--code' => 'DUP']);

    expect(Artisan::call('tenant:add-branch', ['tenant' => $tenant->slug, '--name' => 'Second', '--code' => 'DUP']))->toBe(1)
        ->and(Artisan::call('tenant:add-branch', ['tenant' => $tenant->slug, '--name' => 'Third', '--code' => 'TZ', '--timezone' => 'Mars/Olympus']))->toBe(1);

    dfCtx()->set($tenant);

    // Neither refusal wrote anything.
    expect(Branch::query()->count())->toBe(1);
});

it('refuses an unknown tenant, a missing name and a missing code — before writing anything', function () {
    $tenant = dfTenant();

    expect(Artisan::call('tenant:add-branch', ['tenant' => 'no-such-tenant', '--name' => 'X', '--code' => 'X']))->toBe(1)
        ->and(Artisan::call('tenant:add-branch', ['tenant' => $tenant->slug, '--code' => 'X']))->toBe(1)
        ->and(Artisan::call('tenant:add-branch', ['tenant' => $tenant->slug, '--name' => 'X']))->toBe(1);

    dfCtx()->set($tenant);

    expect(Branch::query()->count())->toBe(0);
});

it('creates no demo data — the provisioned tenant is the only one', function () {
    $tenant = dfTenant();
    Artisan::call('tenant:add-branch', ['tenant' => $tenant->slug, '--name' => 'Main', '--code' => 'MAIN']);

    /*
     * The QA-FIX.9b guard stands: nothing in this sequence reaches a demo seeder, and the only tenant in
     * the database is the one the operator named.
     */
    expect(Tenant::query()->count())->toBe(1)
        ->and(Tenant::query()->firstOrFail()->slug)->toBe('fresh-clinic');

    dfCtx()->set($tenant);
    expect(app(SettingsService::class)->get('timezone'))->toBe('Europe/Zurich');
});
