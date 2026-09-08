<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| QA-FIX.7d — pattern 1's root cause: /app offers only what the role can open
|--------------------------------------------------------------------------
| SEVEN CONSECUTIVE PHASES FOUND THIS AND IT NEVER MOVED. `P1-H1` (reception,
| "Register patient"), `P2-H2` and `P7-H4` (ed_physician, all four links),
| `P3-M7` (billing, all four), `P4-H5` (all four nursing roles), `P5-H2` (both
| pharmacy roles), `P6-H4`. Every instance names the SAME four destinations on
| the SAME page, and every instance measured a 403.
|
| THE NAV MAP WAS NEVER THE DEFECT. `P3-M7` says it outright — "The nav is
| correct (Dashboard + Billing only); the page body is not." `AppLayout` has
| gated on `auth.user.permissions` since FIX.4; `Landing.vue` simply never read
| it, and all EIGHT of its links rendered for everyone.
|
| THE PROPERTY THIS FILE PINS is not "the template has v-ifs" — it is that the
| flag the page gates on AGREES WITH THE SERVER. For each role and each of the
| four destinations: if the shared permission is false the route must 403, and
| if it is true the route must not. That is the property all seven phases
| measured by hand, and it fails on the old code for five of the six roles here.
|
| The server Gate is untouched and stays authoritative: hiding a link neither
| grants nor blocks access. This stops the product ADVERTISING what it refuses.
*/

/** The four destinations `/app` offers, and the permission each route's own Gate enforces. */
const LANDING_LINKS = [
    '/patients/register' => 'patient.edit',
    '/scheduling/day-board' => 'appointment.manage',
    '/nursing/dispatch' => 'dispatch.manage',
    '/comms/inbox' => 'comms.manage',
];

function llgUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

function llgTenant(string $slug): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);
    Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);

    return $tenant;
}

// ------------------------------------------------------------ THE PROPERTY ----

test('THE PROPERTY: for every role, the flag /app gates on predicts whether the route is reachable', function (string $role) {
    $tenant = llgTenant('llg-'.str_replace('_', '-', $role));
    $user = llgUser($tenant, $role);
    app(TenantContext::class)->forget(); // request-level: the middleware re-establishes context (FIX.1)

    $permissions = null;
    $this->actingAs($user)->get('/app')->assertOk()
        ->assertInertia(function (Assert $page) use (&$permissions) {
            $permissions = $page->toArray()['props']['auth']['user']['permissions'];
        });

    foreach (LANDING_LINKS as $route => $permission) {
        // array_key_exists, NOT toHaveKey: these keys contain dots, and toHaveKey reads a dot as a nested
        // path (it would look for $permissions['patient']['edit']) while its second argument is an expected
        // VALUE rather than a message. Both cost a red test here.
        expect(array_key_exists($permission, $permissions))->toBeTrue(
            "the page gates {$route} on {$permission}, so the shell must share that key"
        );

        $status = $this->actingAs($user)->get($route)->status();
        $reachable = $status !== 403;

        // THE ASSERTION SEVEN PHASES WERE MAKING BY HAND. On the old code every flag was ignored and the
        // link rendered regardless, so `ed_physician` saw four links and got four 403s.
        expect($reachable)->toBe(
            $permissions[$permission],
            "{$role}: /app gates {$route} on {$permission}={$permissions[$permission]} but the route returned {$status}"
        );
    }
})->with(['reception', 'org_admin', 'ed_physician', 'triage_nurse', 'billing', 'ward_nurse']);

test('the shell shares patient.edit, because the landing body now gates on it', function () {
    // The key QA-FIX.7d added, by the D-107 / D-110 route: one key for one gated destination. Without it
    // `can('patient.edit')` is undefined on the client and the Register link would hide from EVERYONE —
    // failing closed, but wrongly.
    $tenant = llgTenant('llg-key');
    $admin = llgUser($tenant, 'org_admin'); // BEFORE forget() — the role lookup is tenant-scoped
    app(TenantContext::class)->forget();

    $permissions = null;
    $this->actingAs($admin)->get('/app')->assertOk()
        ->assertInertia(function (Assert $page) use (&$permissions) {
            $permissions = $page->toArray()['props']['auth']['user']['permissions'];
        });
    expect($permissions)->toMatchArray(['patient.edit' => true]);

    $middleware = (string) file_get_contents(base_path('app/Http/Middleware/HandleInertiaRequests.php'));
    expect($middleware)->toContain("'patient.edit',");
});

// ----------------------------------------------------------- STRUCTURAL ----

test('no link on /app is ungated — the three day-board links are inside the gated panel', function () {
    // Mutation-checked: removing any v-if reddens this. Ancestry counts, so the day-board links inside the
    // `canDayBoard` panel are checked by CONTAINMENT rather than by each carrying its own directive.
    $vue = (string) file_get_contents(resource_path('js/pages/App/Landing.vue'));

    // The three destinations that appear outside the schedule panel each carry their own gate.
    expect($vue)->toContain('<Link v-if="canRegister" href="/patients/register"')
        ->and($vue)->toContain('<Link v-if="can(\'dispatch.manage\')" href="/nursing/dispatch"')
        ->and($vue)->toContain('<Link v-if="can(\'comms.manage\')" href="/comms/inbox"')
        ->and($vue)->toContain('<Link v-if="canDayBoard" href="/scheduling/day-board"');

    // NOT ONE of them is left ungated: no bare `<Link href=` may target a gated destination. The day board
    // is EXCLUDED from this blanket check and covered by the containment check below instead, because its
    // three remaining links sit inside a panel that is itself gated — gating each one again would be
    // redundant, and asserting it here would contradict the panel's own v-if.
    foreach (array_keys(LANDING_LINKS) as $route) {
        if ($route === '/scheduling/day-board') {
            continue;
        }
        expect(str_contains($vue, '<Link href="'.$route.'"'))->toBeFalse(
            "an UNGATED link to {$route} is on /app — this is exactly pattern 1"
        );
    }

    // …except inside the schedule panel, which is itself gated: every remaining day-board link must fall
    // between `v-if="canDayBoard"` and the quick-actions panel that follows it.
    $panelStart = strpos($vue, 'v-if="canDayBoard" class="glass-card p-6"');
    $panelEnd = strpos($vue, 'v-if="showQuickActions"');
    expect($panelStart)->not->toBeFalse()->and($panelEnd)->not->toBeFalse()->and($panelEnd)->toBeGreaterThan($panelStart);

    $outside = substr($vue, 0, (int) $panelStart).substr($vue, (int) $panelEnd);
    expect(substr_count($outside, 'href="/scheduling/day-board"'))->toBe(1); // the hero's, which is gated
});

test('D-176: a panel whose every action is hidden does not render at all', function () {
    // "Quick actions" over nothing, or a schedule panel whose empty state tells you to open a board you
    // cannot open, is an affordance for something that cannot happen.
    $vue = (string) file_get_contents(resource_path('js/pages/App/Landing.vue'));

    expect($vue)->toContain('<div v-if="showQuickActions"')
        ->and($vue)->toContain('<div v-if="canDayBoard" class="glass-card p-6"')
        ->and($vue)->toContain('<section v-if="showBodySection"');
});

// ------------------------------------------------------ POSITIVE CONTROLS ----

test('POSITIVE CONTROL — org_admin still sees ALL FOUR links, so gating did not just hide everything', function () {
    // Without this, the whole suite would pass on a page that renders no links at all.
    $tenant = llgTenant('llg-admin');
    $user = llgUser($tenant, 'org_admin');
    app(TenantContext::class)->forget();

    $permissions = null;
    $this->actingAs($user)->get('/app')->assertOk()
        ->assertInertia(function (Assert $page) use (&$permissions) {
            $permissions = $page->toArray()['props']['auth']['user']['permissions'];
        });

    foreach (LANDING_LINKS as $route => $permission) {
        expect($permissions[$permission])->toBeTrue("org_admin must still be offered {$route}")
            ->and($this->actingAs($user)->get($route)->status())->not->toBe(403);
    }
});

test('POSITIVE CONTROL — the server Gate is untouched: a hidden route still 403s by URL', function () {
    // Hiding a link must neither grant nor block. The ED physician sees none of the four and is still
    // refused all four by the server — the guarantee the nav contract has always claimed.
    $tenant = llgTenant('llg-url');
    $user = llgUser($tenant, 'ed_physician');
    app(TenantContext::class)->forget();

    foreach (array_keys(LANDING_LINKS) as $route) {
        $this->actingAs($user)->get($route)->assertForbidden();
    }
});
