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
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource;

uses(RefreshDatabase::class);

/*
 * DEPLOY-FIX.2 — /scheduling/availability renders an empty state instead of 404ing.
 *
 * The defect was the one DEPLOY-FIX.1a (b8d5777) closed on the day-board and recorded here rather than
 * widened into: AvailabilityController resolved "the current branch" with firstOrFail(), so a tenant with
 * zero ACTIVE branches got an HTTP 404 on the screen that configures bookable hours.
 *
 * EVERY TEST IN THIS FILE THAT ASSERTS assertOk() FAILED BEFORE THE FIX (D-182). That is not incidental:
 * a 404 is indistinguishable, to the person looking at it, from a broken deploy — which is exactly how
 * the day-board's version of this was first mistaken for a provisioning failure.
 *
 * Helpers are prefixed df2 because Pest declares test-file functions GLOBALLY and
 * tests/Feature/Qa/FreshTenantIsUsableTest.php already owns df*.
 */

function df2Ctx(): TenantContext
{
    return app(TenantContext::class);
}

/** A real tenant through the documented provisioning commands — which leave ZERO branches. */
function df2Tenant(string $slug = 'avail-clinic'): Tenant
{
    // plans:seed FIRST: on a clean database tenant:create refuses with "Unknown plan [eu_pro]".
    Artisan::call('plans:seed');

    Artisan::call('tenant:create', [
        'name' => 'Availability Clinic',
        '--slug' => $slug,
        '--timezone' => 'Europe/Zurich',
        '--locale' => 'de',
        '--currency' => 'CHF',
    ]);

    return Tenant::query()->where('slug', $slug)->firstOrFail();
}

/** The tenant's first administrator — holds admin.manage, so it IS offered the branch link. */
function df2Admin(Tenant $tenant): User
{
    Artisan::call('tenant:add-admin', [
        'tenant' => $tenant->slug,
        '--email' => 'admin@avail-clinic.test',
        '--name' => 'Dr. Avail',
    ]);

    $user = User::query()->where('email', 'admin@avail-clinic.test')->firstOrFail();

    // The page is behind mandatory 2FA; these tests are about the page, not the enrolment gate.
    $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_secret' => encrypt('x')])->save();

    return $user;
}

/**
 * Reception: holds appointment.manage (so it may open this page) and NOT admin.manage (so it must not
 * be offered the /admin/branches link). Verified against RbacProvisioner's role templates.
 */
function df2Reception(Tenant $tenant): User
{
    df2Ctx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $role = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'reception')->firstOrFail();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id]);

    return $user;
}

it('renders the availability screen with an honest empty state instead of a 404 when there is no branch', function () {
    $tenant = df2Tenant();
    $admin = df2Admin($tenant);

    df2Ctx()->set($tenant);
    expect(Branch::query()->count())->toBe(0);

    /*
     * BEFORE THE FIX THIS WAS A 404. The payload assertions matter as much as assertOk(): the page
     * decides which of its two empty states to show from filters.branch_id being null, so a fix that
     * returned 200 with a branch_id would render the WRONG empty state — telling a practice with no
     * site to go and add a room.
     */
    $this->actingAs($admin)
        ->get('/scheduling/availability')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Scheduling/Availability')
            ->where('filters.branch_id', null)
            ->where('branches', [])
            ->where('resources', [])
            ->where('counts.resources', 0)
            ->where('counts.withoutTemplate', 0)
            ->where('counts.exceptions', 0)
        );
});

it('a MATURE tenant that deactivates its only branch gets the same empty state, not a 404', function () {
    $tenant = df2Tenant();
    $admin = df2Admin($tenant);
    df2Ctx()->set($tenant);

    $branch = app(BranchService::class)->create(['name' => 'Only Site', 'code' => 'ONLY']);
    $branch->forceFill(['active' => false])->save();

    /*
     * THIS IS WHY THE FIX IS NOT MERELY A PROVISIONING CONCERN, and it is the half a fresh-tenant-only
     * test would miss. BranchController::deactivate refuses only when FUTURE APPOINTMENTS exist, so a
     * practice with a quiet calendar can deactivate its last site and hit the identical 404 years after
     * onboarding. DEPLOY-FIX.1a covered this path on the day-board; this covers it here.
     */
    $this->actingAs($admin)
        ->get('/scheduling/availability')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Scheduling/Availability')
            ->where('filters.branch_id', null)
            ->where('resources', [])
        );
});

it('still renders the empty state for a role that cannot create a branch', function () {
    $tenant = df2Tenant();
    $reception = df2Reception($tenant);

    /*
     * Reception holds appointment.manage but NOT admin.manage. The server's job here is only to not
     * 404 — it must not refuse a role that legitimately opens this page. The CALL TO ACTION is the
     * page's decision, made from admin.manage, so reception sees the explanation without a link it
     * would 403 on (D-214). The Vue half of that is pinned in availability-empty-branch.test.ts,
     * because a props assertion cannot see a template.
     */
    $this->actingAs($reception)
        ->get('/scheduling/availability')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Scheduling/Availability')
            ->where('filters.branch_id', null)
        );
});

it('keeps a ?type= filter the operator typed through the empty render', function () {
    $tenant = df2Tenant();
    $admin = df2Admin($tenant);

    /*
     * A deliberate consequence of where the null-guard sits: it runs AFTER $type is resolved, so an
     * operator who filtered to rooms and then lost their last branch still sees their filter rather
     * than having it silently dropped. Pinned because moving the guard up — the "tidier" edit, and the
     * shape the day-board uses — would break it without failing anything else.
     */
    $this->actingAs($admin)
        ->get('/scheduling/availability?type=room')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.branch_id', null)
            ->where('filters.type', 'room')
        );
});

it('supplies the same action endpoints on the empty payload as on the populated one', function () {
    $tenant = df2Tenant();
    $admin = df2Admin($tenant);

    /*
     * The anti-drift property actionUrls() exists for. Asserting the keys match between the two
     * payloads is the point — an empty state that silently lost an endpoint would leave the page's
     * wiring half-built the moment a branch appeared.
     */
    $empty = $this->actingAs($admin)->get('/scheduling/availability');
    $empty->assertOk();
    $emptyActions = $empty->viewData('page')['props']['actions'];

    df2Ctx()->set($tenant);
    app(BranchService::class)->create(['name' => 'Hauptstandort', 'code' => 'HAUPT']);

    $populated = $this->actingAs($admin)->get('/scheduling/availability');
    $populated->assertOk();
    $populatedActions = $populated->viewData('page')['props']['actions'];

    expect(array_keys($emptyActions))->toBe(array_keys($populatedActions))
        ->and($emptyActions)->toBe($populatedActions)
        ->and($emptyActions)->toHaveKeys(['storeUrl', 'updateUrl', 'deleteUrl', 'impactUrl']);
});

it('renders the populated screen unchanged once a branch and a resource exist', function () {
    $tenant = df2Tenant();
    $admin = df2Admin($tenant);
    df2Ctx()->set($tenant);

    $branch = app(BranchService::class)->create(['name' => 'Hauptstandort', 'code' => 'HAUPT']);
    Resource::query()->create([
        'type' => 'practitioner',
        'name' => 'Dr. Example',
        'branch_id' => $branch->id,
        'active' => true,
    ]);

    /*
     * THE POSITIVE CONTROL (D-174). Without it, deleting the whole populated branch of the controller
     * would still leave every test above green — they only ever assert the empty case. This pins that
     * the fix changed the no-branch path and nothing else.
     */
    $this->actingAs($admin)
        ->get('/scheduling/availability')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Scheduling/Availability')
            ->where('filters.branch_id', $branch->id)
            ->where('counts.resources', 1)
            ->has('resources', 1)
            ->has('branches', 1)
        );
});
