<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Nursing\Services\DayPackService;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Services\SurgicalCaseService;

uses(RefreshDatabase::class);

/*
| QA-FIX.12c — FAMILY 5, display / locale (`P2-H3`, `P4-H4`, `P6-H3`).
|
| ONE DECLARED DISPLAY ZONE: THE PRACTICE'S. Storage has been true UTC since D-192, and that decision
| also resolved the display side by sharing the tenant's zone as the `timezone` Inertia prop — and then
| recorded that *"no frontend component consumes the shared `timezone` prop for rendering today"*. That
| was still true when this gate opened, so every clinical surface had invented its own clock and the
| audit found THREE, none of them the practice's: raw UTC printed verbatim, `new Date(iso).toLocaleString()`
| (the VIEWER's machine zone), and `iso.replace('T',' ').slice(0,16)` (UTC, cosmetically tidied).
|
| `P6-H3` IS NOT A VALIDATION DEFECT, AND THE CONTROL BELOW IS THE POINT. Its recorded remedy — refuse a
| past `scheduled_at`, the D-194 shape — does not transfer. The demo seeder itself schedules a case in
| the past and transitions it to completed, and nine existing tests schedule at fixed past dates, because
| documenting an operation that already happened is a legitimate use of that path. Refusing it would
| break retrospective documentation. So the time is not refused; the board SAYS the time has passed.
*/

function pclTenant(string $slug, string $zone): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => 'PCL '.$slug, 'slug' => $slug, 'region' => 'eu', 'status' => 'active',
    ]);
    app(TenantContext::class)->set($tenant);
    app(SettingsService::class)->set('timezone', $zone);

    return $tenant;
}

function pclUser(Tenant $tenant, string $roleKey): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $role = Role::query()->where('key', $roleKey)->first();
    if ($role !== null) {
        RoleAssignment::query()->firstOrCreate(['user_id' => $user->id, 'role_id' => $role->id]);
    }

    return $user;
}

/* ------------------------------------------------------------------ *
 | `P4-H4` — the practice zone travels with the offline day pack.      |
 * ------------------------------------------------------------------ */

it('P4-H4: the day pack carries the practice zone, because the PWA gets no Inertia props', function () {
    $tenant = pclTenant('pcl-pack', 'Europe/Zurich');
    $nurse = pclUser($tenant, 'nurse');
    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    $profile = StaffProfile::query()->create([
        'first_name' => 'Nora', 'last_name' => 'Nurse', 'display_name' => 'Nora Nurse',
        'profession' => 'nurse', 'primary_branch_id' => $branch->id, 'user_id' => $nurse->id,
        'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER, 'name' => 'Nora Nurse Resource',
        'staff_profile_id' => $profile->id, 'branch_id' => $branch->id, 'active' => true,
    ]);

    $pack = app(DayPackService::class)->forNurse($nurse, Carbon::parse('2026-09-06'));

    /*
     * BEFORE THE FIX the pack had no zone at all, so the device could only print the raw ISO: the audit
     * read `2026-09-06T05:30:00+00:00` for a visit at 07:30 Zurich. The zone rides IN the pack because
     * the pack is cached and used offline — it must be there with no network.
     */
    expect($pack)->toHaveKey('timezone')
        ->and($pack['timezone'])->toBe('Europe/Zurich');
});

it('P4-H4: the pack reports the zone the TENANT set, not a hardcoded one', function () {
    $tenant = pclTenant('pcl-tokyo', 'Asia/Tokyo');
    $nurse = pclUser($tenant, 'nurse');
    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Asia/Tokyo']);
    $profile = StaffProfile::query()->create([
        'first_name' => 'Nao', 'last_name' => 'Nurse', 'display_name' => 'Nao Nurse',
        'profession' => 'nurse', 'primary_branch_id' => $branch->id, 'user_id' => $nurse->id,
        'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER, 'name' => 'Nao Nurse Resource',
        'staff_profile_id' => $profile->id, 'branch_id' => $branch->id, 'active' => true,
    ]);

    // A second tenant with a different zone: a hardcoded 'Europe/Zurich' would pass the test above.
    expect(app(DayPackService::class)->forNurse($nurse, Carbon::parse('2026-09-06'))['timezone'])
        ->toBe('Asia/Tokyo');
});

/* ------------------------------------------------------------------ *
 | `P6-H3` — the board SAYS the time has passed; it does not refuse.   |
 * ------------------------------------------------------------------ */

it('P6-H3: a scheduled case whose time has passed is marked as such on the board', function () {
    $tenant = pclTenant('pcl-or', 'Europe/Zurich');
    $surgeon = pclUser($tenant, 'surgeon');
    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    $profile = StaffProfile::query()->create([
        'first_name' => 'Isabelle', 'last_name' => 'Vogt', 'display_name' => 'Dr. med. Isabelle Vogt',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'user_id' => $surgeon->id,
    ]);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Simone', 'last_name' => 'Arnold', 'date_of_birth' => '1975-02-02', 'sex' => 'female',
    ]);

    $cases = app(SurgicalCaseService::class);
    $past = $cases->schedule($surgeon, $patient, $profile, 'QA12c past-time probe', Carbon::parse('2020-01-01 08:00:00'));
    $future = $cases->schedule($surgeon, $patient, $profile, 'QA12c future probe', Carbon::now()->addWeek());

    app(TenantContext::class)->forget();

    $payload = $this->actingAs($surgeon)->get('/surgery/cases')->assertOk()
        ->viewData('page')['props']['cases'];

    $byId = collect($payload)->keyBy('id');

    expect($byId[$past->id]['scheduled_time_passed'])->toBeTrue()
        ->and($byId[$future->id]['scheduled_time_passed'])->toBeFalse();
});

it('P6-H3: a case that has MOVED ON is not marked — the mark is about work that has not started', function () {
    $tenant = pclTenant('pcl-moved', 'Europe/Zurich');
    $surgeon = pclUser($tenant, 'surgeon');
    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    $profile = StaffProfile::query()->create([
        'first_name' => 'Isabelle', 'last_name' => 'Vogt', 'display_name' => 'Dr. med. Isabelle Vogt',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'user_id' => $surgeon->id,
    ]);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Simone', 'last_name' => 'Arnold', 'date_of_birth' => '1975-02-02', 'sex' => 'female',
    ]);

    $cases = app(SurgicalCaseService::class);
    $case = $cases->schedule($surgeon, $patient, $profile, 'QA12c documented retrospectively', Carbon::parse('2020-01-01 08:00:00'));
    $case = $cases->transition($surgeon, $case->fresh(), SurgicalCase::STATUS_PRE_OP);

    app(TenantContext::class)->forget();

    $payload = $this->actingAs($surgeon)->get('/surgery/cases')->assertOk()
        ->viewData('page')['props']['cases'];

    /*
     * A past date on a case that has already been taken forward is not a stale entry — it is the record
     * of when it happened. Marking those would make the signal meaningless, which is the D-169 line:
     * state a recorded fact, and only where it means something.
     */
    expect(collect($payload)->firstWhere('id', $case->id)['scheduled_time_passed'])->toBeFalse();
});

it('P6-H3 CONTROL: a past scheduled_at is still ACCEPTED — retrospective documentation is not broken', function () {
    $tenant = pclTenant('pcl-retro', 'Europe/Zurich');
    $surgeon = pclUser($tenant, 'surgeon');
    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    $profile = StaffProfile::query()->create([
        'first_name' => 'Isabelle', 'last_name' => 'Vogt', 'display_name' => 'Dr. med. Isabelle Vogt',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'user_id' => $surgeon->id,
    ]);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Simone', 'last_name' => 'Arnold', 'date_of_birth' => '1975-02-02', 'sex' => 'female',
    ]);

    /*
     * THE CONTROL THAT PINS THE DETERMINATION. `P6-H3`'s recorded remedy was a past-date refusal. The
     * evidence says otherwise: `DemoHospitalSeeder` schedules a case in the past and transitions it to
     * completed, and nine existing tests schedule at fixed past dates. If a later gate adds that
     * refusal, THIS test reddens and the trade-off has to be faced deliberately instead of by accident.
     */
    $case = app(SurgicalCaseService::class)
        ->schedule($surgeon, $patient, $profile, 'Operation documented after the fact', Carbon::parse('2020-01-01 08:00:00'));

    expect($case->exists)->toBeTrue()
        ->and($case->scheduled_at->year)->toBe(2020);
});

/* ------------------------------------------------------------------ *
 | `P2-H3` — the three wrong clocks are gone from the named surfaces.  |
 * ------------------------------------------------------------------ */

it('P2-H3: the named clinical surfaces render through the shared helper, in the practice zone', function () {
    foreach ([
        'resources/js/pages/Clinical/Chart.vue',
        'resources/js/pages/Clinical/NoteEditor.vue',
        'resources/js/pages/Dental/Odontogram.vue',
        'resources/js/pages/Dental/Imaging.vue',
        'resources/js/pages/Nursing/Dispatch.vue',
        'resources/js/pages/Surgery/CaseBoard.vue',
    ] as $page) {
        $src = (string) file_get_contents(base_path($page));

        expect($src)->toContain('formatDateTime')
            // It must read the PROP, not a constant and not the device.
            ->and($src)->toContain('page.props.timezone');
    }
});

it('P2-H3: the three wrong-clock idioms are ABSENT from those surfaces', function () {
    /*
     * PINNED AS AN ABSENCE, because each of these reads as a formatted local time and is not one. The
     * dangerous one is `toLocaleString()`: it produces a confident, well-formatted answer in the
     * VIEWER's zone, which is exactly what the audit measured as `9/5/2026, 9:51:12 AM` on a Zurich
     * practice. Mutation-checked: restoring any of them reddens this.
     */
    foreach ([
        'resources/js/pages/Clinical/Chart.vue',
        'resources/js/pages/Clinical/NoteEditor.vue',
        'resources/js/pages/Dental/Odontogram.vue',
        'resources/js/pages/Dental/Imaging.vue',
        'resources/js/pages/Nursing/Dispatch.vue',
        'resources/js/pages/Surgery/CaseBoard.vue',
    ] as $page) {
        $src = (string) file_get_contents(base_path($page));

        expect($src)->not->toContain('toLocaleString()')
            ->and($src)->not->toContain('toLocaleTimeString()')
            ->and($src)->not->toContain("replace('T', ' ')");
    }
});

it('the tenant zone reaches every page, and the helper refuses a date-only value', function () {
    // The prop D-192 built and nothing consumed — asserted to still be shared, since six pages now
    // depend on it.
    expect((string) file_get_contents(base_path('app/Http/Middleware/HandleInertiaRequests.php')))
        ->toContain("'timezone' => fn () => app(DisplayTimezone::class)->forCurrentTenant()");

    // D-091 owns date-only values; `formatDateTime` must not silently shift one (it would render
    // "01:00" in Zurich for a bare date, or the previous day in a zone behind UTC).
    expect((string) file_get_contents(base_path('resources/js/lib/date.ts')))
        ->toContain('if (DATE_ONLY.test(value)) {');
});
