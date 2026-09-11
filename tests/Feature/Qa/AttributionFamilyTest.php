<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SystemActorResolver;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
| QA-FIX.11b — FAMILY 6, attribution (`P9-H6`, `P8-H3`).
|
| THE TWO FINDINGS ARE DIFFERENT KINDS OF DEFECT, and the study says which is which:
|
|  - `P9-H6` is a genuine ATTRIBUTION defect on an UNATTENDED path. `AccrueBedDaysCommand` picked
|    `RoleAssignment::where('role_id', $orgAdminId)->value('user_id')` — no ORDER BY, no check that the
|    user holds `billing.manage`, no exclusion of a branch-scoped assignment or a super-admin. The person
|    credited with a tenant's inpatient revenue was whichever row the engine happened to return. FIXED by
|    using `SystemActorResolver::forPermission()`, which every other scheduled command already uses.
|
|  - `P8-H3` is a DISPLAY defect. The finding says so in its own first line: "The data model is right
|    throughout ... And not one of them is displayed." So the fix RENDERS A NAME; it changes no write and
|    no recorded value. A test below pins exactly that: the stored columns are untouched.
|
| WHY THE FIXTURE MAKES THE ACTORS DIFFERENT PEOPLE: a fixture whose two people are the same person cannot
| catch a misattribution — which is why `P2-C1`, `P6-C2`, `P7-C1` and `P9-C3` all survived their own suites.
*/

function afTenant(string $slug): Tenant
{
    $t = Tenant::query()->create(['name' => 'AF '.$slug, 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($t);

    return $t;
}

function afUser(Tenant $tenant, string $roleKey, string $name): User
{
    $u = User::factory()->forTenant($tenant)->twoFactorEnabled()->create(['name' => $name]);
    $r = Role::query()->where('key', $roleKey)->first();
    if ($r !== null) {
        RoleAssignment::query()->firstOrCreate(['user_id' => $u->id, 'role_id' => $r->id]);
    }

    return $u;
}

/* ------------------------------------------------------------------ *
 | `P9-H6` — the unattended accrual credits a real billing manager.    |
 * ------------------------------------------------------------------ */

it('P9-H6: the resolver REFUSES rather than guessing — null when nobody holds the permission', function () {
    $tenant = afTenant('af-nobody');
    afUser($tenant, 'nurse', 'Nina Nurse'); // holds no billing.manage

    /*
     * THE PROPERTY THE WHOLE FIX RESTS ON (D-195, D-216). If the resolver picked a person by convenience
     * it would be the same defect the programme spent four gates closing. It returns NULL, and the
     * command's existing `if (! $actor instanceof User) { warn; continue; }` skips the tenant.
     */
    expect(app(SystemActorResolver::class)->forPermission($tenant, 'billing.manage'))->toBeNull();
});

it('P9-H6: the resolver is DETERMINISTIC and picks a genuine billing.manage holder, not an arbitrary row', function () {
    $tenant = afTenant('af-determ');

    // THE FIXTURE MAKES THEM DIFFERENT PEOPLE ON PURPOSE, and puts the NON-qualifying user first so an
    // unordered `value('user_id')` would be likely to return the wrong one.
    $nurse = afUser($tenant, 'nurse', 'Nina Nurse');
    $admin = afUser($tenant, 'org_admin', 'Otto Orgadmin');

    $resolved = app(SystemActorResolver::class)->forPermission($tenant, 'billing.manage');

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($admin->id)
        ->and($resolved->id)->not->toBe($nurse->id);

    // Deterministic: the same tenant resolves the same actor on every run, so the audit trail is stable.
    expect(app(SystemActorResolver::class)->forPermission($tenant, 'billing.manage')->id)->toBe($resolved->id);
});

it('P9-H6: the command no longer resolves an actor by convenience', function () {
    $src = (string) file_get_contents(base_path('Modules/Hospital/src/Console/AccrueBedDaysCommand.php'));

    /*
     * THE DEFECT, PINNED AS ABSENT. The old query was
     * `RoleAssignment::query()->where('role_id', $roleId)->value('user_id')` — no ORDER BY, no permission
     * check. Mutation-checked: restoring it reddens this.
     */
    expect($src)->toContain("\$actors->forPermission(\$tenant, 'billing.manage')")
        ->and($src)->not->toContain('resolveBillingActor')
        ->and($src)->not->toContain("where('key', 'org_admin')")
        ->and($src)->not->toContain('RoleAssignment');

    // And it is now the SAME resolver the other scheduled commands use — Hospital was the only one skipping it.
    foreach ([
        'Modules/Billing/src/Console/DunningRunCommand.php',
        'Modules/Billing/src/Console/ReconcileCommand.php',
        'Modules/Reporting/src/Console/ReportingSummaryCommand.php',
        'Modules/Hospital/src/Console/AccrueBedDaysCommand.php',
    ] as $command) {
        expect((string) file_get_contents(base_path($command)))
            ->toContain('SystemActorResolver');
    }
});

it('P9-H6: a BRANCH-SCOPED admin is never the unattended actor — the case the old query got wrong', function () {
    $tenant = afTenant('af-branch');
    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    $role = Role::query()->where('key', 'org_admin')->firstOrFail();

    /*
     * THE DISCRIMINATING FIXTURE. The old query was
     * `RoleAssignment::where('role_id', $orgAdminId)->value('user_id')` — **no ORDER BY and no check that
     * the permission is held TENANT-WIDE** — so it would have returned whichever row came first, and a
     * branch-scoped assignment is a row like any other. This fixture creates the branch-scoped admin
     * FIRST (lower id) so the old query would pick exactly the wrong person.
     *
     * `PermissionService::has()` with no branch counts only all-branches assignments, so the resolver
     * skips the branch-scoped one and returns the tenant-wide admin.
     */
    $branchOnly = User::factory()->forTenant($tenant)->twoFactorEnabled()->create(['name' => 'Bea Branchadmin']);
    RoleAssignment::query()->create(['user_id' => $branchOnly->id, 'role_id' => $role->id, 'branch_id' => $branch->id]);

    $tenantWide = afUser($tenant, 'org_admin', 'Otto Orgadmin');

    expect($branchOnly->id)->not->toBe($tenantWide->id)
        ->and($branchOnly->id)->toBeLessThan($tenantWide->id); // the old query's likely pick

    $resolved = app(SystemActorResolver::class)->forPermission($tenant, 'billing.manage');

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($tenantWide->id)
        ->and($resolved->id)->not->toBe($branchOnly->id);
});

it('P9-H6: the accrual runs unattended and does not skip a tenant that has a billing manager', function () {
    $tenant = afTenant('af-accrue');
    $nurse = afUser($tenant, 'nurse', 'Nina Nurse');
    $admin = afUser($tenant, 'org_admin', 'Otto Orgadmin');

    expect($nurse->id)->not->toBe($admin->id); // the fixture's whole point

    Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);

    // END TO END through the real console path — the surface `P9-H6` is about is unattended, so this is
    // where it is verified, not in a browser.
    app(TenantContext::class)->forget();
    $exit = Artisan::call('hospital:accrue-bed-days');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->not->toContain("Skipped {$tenant->slug}");
});

/* ------------------------------------------------------------------ *
 | `P8-H3` — a DISPLAY fix. No write changes.                          |
 * ------------------------------------------------------------------ */

it('P8-H3: the recorded attribution columns are untouched — this is a display fix', function () {
    /*
     * THE POSITIVE CONTROL FOR "CHANGED NOTHING" (D-174 applied to a display fix). `P8-H3`'s own first
     * line is "The data model is right throughout", so the one thing this part must NOT do is touch a
     * write. The four controllers gained a read-only resolver and a `*_by_name` payload field; none of
     * them writes an attribution column.
     */
    foreach ([
        'Modules/Radiology/src/Http/Controllers/ImagingReportController.php' => 'signed_by',
        'Modules/Radiology/src/Http/Controllers/ImagingStudyController.php' => 'performed_by',
        'Modules/Lab/src/Http/Controllers/SpecimenController.php' => 'performed_by',
        'Modules/Lab/src/Http/Controllers/LabResultController.php' => 'entered_by',
    ] as $file => $column) {
        $src = (string) file_get_contents(base_path($file));

        // It READS the column for display and never assigns it.
        expect($src)->toContain($column.'_name')
            ->and($src)->not->toContain("'".$column."' =>")
            ->and($src)->not->toContain('->'.$column.' =');
    }
});

it('P8-H3: every named surface resolves the actor in ONE query, and leaves an unknown id unnamed', function () {
    // Structural: no fixture needed — this pins HOW the four controllers resolve, not a resolved value.
    foreach ([
        'Modules/Radiology/src/Http/Controllers/ImagingReportController.php',
        'Modules/Radiology/src/Http/Controllers/ImagingStudyController.php',
        'Modules/Lab/src/Http/Controllers/SpecimenController.php',
        'Modules/Lab/src/Http/Controllers/LabResultController.php',
    ] as $file) {
        $src = (string) file_get_contents(base_path($file));

        // ONE query per list, not per row — `whereIn`, never a lookup inside the map.
        expect($src)->toContain('private function actorNames(array $ids): array')
            ->and($src)->toContain('whereIn(\'id\', $ids)')
            ->and($src)->toContain('whereIn(\'user_id\', $ids)')
            // D-176: an id that resolves to nobody is left UNNAMED rather than labelled.
            ->and($src)->toContain('?? null');
    }
});

it('P8-H3: the four surfaces RENDER the name, and only when the server sent one', function () {
    foreach ([
        'resources/js/pages/Radiology/Report.vue' => 'signed_by_name',
        'resources/js/pages/Radiology/Study.vue' => 'performed_by_name',
        'resources/js/pages/Lab/Specimens.vue' => 'performed_by_name',
        'resources/js/pages/Lab/Results.vue' => 'entered_by_name',
    ] as $page => $field) {
        $vue = (string) file_get_contents(resource_path(str_replace('resources/', '', $page)));

        // The TYPE carries it and the TEMPLATE prints it behind a v-if, so a null never renders an empty
        // separator. Mutation-checked: deleting either reddens this.
        expect($vue)->toContain($field)
            ->and($vue)->toContain('v-if="'.(str_contains($page, 'Report') ? 'v.' : (str_contains($page, 'Results') ? 'r.' : 'e.')).$field.'"');
    }
});
