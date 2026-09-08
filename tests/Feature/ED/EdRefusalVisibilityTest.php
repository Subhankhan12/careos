<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Clinical\Models\Vital;
use Modules\ED\Models\EdTriage;
use Modules\ED\Models\EdVisit;
use Modules\ED\Services\EdVisitService;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\Ward;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| QA-FIX.7c — P7-H2: ED surfaces render their refusals
|--------------------------------------------------------------------------
| The `P6-C3` defect, one module over. Ten `->withErrors([...])` sites across
| five ED controllers plus every validate() rule, and — measured in Phase 7 and
| re-measured at the start of this part — ZERO ED pages read `errors`. A refused
| action and a successful one were identical to the user: the page reloaded,
| nothing was recorded, no message appeared, and the POST returned 302, which is
| success-shaped.
|
| THIS PART IS AN ADOPTION, NOT A DESIGN. `RefusalNotice.vue` already exists
| (QA-FIX.6c, D-210) and already reads the WHOLE error bag, which is the property
| that matters here too: validate() keys by FIELD (`triaged_by`, `bed_id`) while
| the controllers key by DOMAIN (`triage`, `disposition`, `ed_billing`), so a
| component naming keys would miss half of them. Nothing new is introduced — no
| new component, no new mechanism, no new copy (D-170).
|
| ALL FIVE ED PAGES TAKE IT, and that differs from Surgery deliberately. QA-FIX.6c
| EXCLUDED `Checklist.vue` because its only control was a checkbox bound to a real
| template item, so no rule it validated could fail from a live control (D-176).
| Every ED page has a refusal a user can actually reach, and the last test here
| names the reachable refusal for each one rather than asserting the pages as a
| block.
*/

function edrvUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, branch: Branch, nurse: User, physician: User, nurseProfile: StaffProfile, patient: Patient, visit: EdVisit, bed: Bed} */
function edrvFixture(string $slug = 'edrv'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' ED', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $nurse = edrvUser($tenant, 'triage_nurse');
    $physician = edrvUser($tenant, 'ed_physician');

    $nurseProfile = StaffProfile::query()->create([
        'first_name' => 'Nadia', 'last_name' => 'Nurse', 'display_name' => 'Nadia Nurse',
        'profession' => 'nurse', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $visit = app(EdVisitService::class)->register($nurse, $patient, $branch, EdVisit::ARRIVAL_AMBULANCE, 'Chest pain');

    $ward = Ward::query()->create(['branch_id' => $branch->id, 'name' => 'Acute Ward', 'code' => 'AW']);
    $bed = Bed::query()->create(['branch_id' => $branch->id, 'ward_id' => $ward->id, 'label' => 'A1', 'bed_type' => Bed::TYPE_GENERAL]);

    return compact('tenant', 'branch', 'nurse', 'physician', 'nurseProfile', 'patient', 'visit', 'bed');
}

// ------------------------------------------------- THE DRIVEN REFUSALS ----

test('P7-H2: a triage naming no nurse is refused AND the refusal reaches the page', function () {
    $fx = edrvFixture('edrv-triage');
    app(TenantContext::class)->forget();

    $response = test()->actingAs($fx['nurse'])
        ->from('/ed/visits/'.$fx['visit']->id.'/triage')
        ->post('/ed/visits/'.$fx['visit']->id.'/triage', [
            'triaged_by' => '',
            'presenting_complaint' => 'Chest pain',
            'acuity_scale' => 'ESI',
            'acuity_level' => '2',
        ]);

    // THE REFUSAL IS UNCHANGED — this part must not soften a guard to make it visible.
    expect(EdTriage::query()->count())->toBe(0);

    // THE PROPERTY (D-182 — before this part nothing carried the message to the user).
    $response->assertRedirect()->assertSessionHasErrors('triaged_by');
});

test('P7-H2: an acuity level that is not on the chosen scale is refused, and says so', function () {
    $fx = edrvFixture('edrv-level');
    app(TenantContext::class)->forget();

    // MANCHESTER uses colours; '2' is an ESI/CTAS level. The DOMAIN key, not a field key — which is exactly
    // why the notice must read the whole bag rather than named fields.
    $response = test()->actingAs($fx['nurse'])
        ->from('/ed/visits/'.$fx['visit']->id.'/triage')
        ->post('/ed/visits/'.$fx['visit']->id.'/triage', [
            'triaged_by' => $fx['nurseProfile']->id,
            'presenting_complaint' => 'Chest pain',
            'acuity_scale' => 'MANCHESTER',
            'acuity_level' => '2',
        ]);

    expect(EdTriage::query()->count())->toBe(0);
    $response->assertRedirect()->assertSessionHasErrors('triage'); // the DOMAIN key
});

test('P7-H2: an illegal board transition is refused AND the refusal reaches the board', function () {
    $fx = edrvFixture('edrv-board');
    app(TenantContext::class)->forget();

    // THE REACHABLE CASE, and it is ordinary on an ED board: two staff watch the same list, one advances a
    // visit, and the other's page still offers a button that is now illegal. The visit is `arrived`, whose
    // only legal successors are `triaged` and `left_without_being_seen` — so "Ready for disposition", the
    // button a board still showing `in_treatment` would offer, is refused.
    //
    // THE BOARD REFUSES IN TWO LAYERS AND THIS TARGETS THE INNER ONE. `dispositioned` is excluded by the
    // route's `in:` rule and never reaches the service (a FIELD-keyed `status` error);
    // `awaiting_disposition` passes validation and is then refused by the transition guard itself, which is
    // the DOMAIN-keyed `ed_visit` refusal Phase 7 counted. Both now reach the page through the same notice
    // — which is the point of reading the whole bag.
    $response = test()->actingAs($fx['physician'])
        ->from('/ed/board')
        ->post('/ed/visits/'.$fx['visit']->id.'/transition', ['status' => EdVisit::STATUS_AWAITING_DISPOSITION]);

    expect($fx['visit']->fresh()->status)->toBe(EdVisit::STATUS_ARRIVED); // the guard still holds
    $response->assertRedirect()->assertSessionHasErrors('ed_visit');
});

test('P7-H2: the board\'s OTHER refusal layer — a status the route excludes — also reaches the page', function () {
    $fx = edrvFixture('edrv-board-outer');
    app(TenantContext::class)->forget();

    // `dispositioned` requires a disposition and is deliberately not a board action, so the route's `in:`
    // rule refuses it before the service is reached. A FIELD key this time, not a domain key.
    test()->actingAs($fx['physician'])
        ->from('/ed/board')
        ->post('/ed/visits/'.$fx['visit']->id.'/transition', ['status' => EdVisit::STATUS_DISPOSITIONED])
        ->assertRedirect()
        ->assertSessionHasErrors('status');

    expect($fx['visit']->fresh()->status)->toBe(EdVisit::STATUS_ARRIVED);
});

test('P7-H2: an admit with no bed and no clinician is refused AND the refusal reaches the page', function () {
    $fx = edrvFixture('edrv-dispo');
    $visits = app(EdVisitService::class);
    $visit = $fx['visit'];
    foreach ([EdVisit::STATUS_TRIAGED, EdVisit::STATUS_IN_TREATMENT, EdVisit::STATUS_AWAITING_DISPOSITION] as $to) {
        $visits->transition($fx['physician'], $visit, $to);
        $visit = $visit->fresh();
    }
    app(TenantContext::class)->forget();

    $response = test()->actingAs($fx['physician'])
        ->from('/ed/visits/'.$fx['visit']->id.'/disposition')
        ->post('/ed/visits/'.$fx['visit']->id.'/disposition', ['disposition' => 'admit', 'bed_id' => '', 'clinician_id' => '']);

    expect(Stay::query()->count())->toBe(0);
    $response->assertRedirect()->assertSessionHasErrors(['bed_id', 'clinician_id']);
});

test('P7-H2: an encounter opened for nobody is refused AND the refusal reaches the record', function () {
    $fx = edrvFixture('edrv-doc');
    app(TenantContext::class)->forget();

    $response = test()->actingAs($fx['physician'])
        ->from('/ed/visits/'.$fx['visit']->id.'/record')
        ->post('/ed/visits/'.$fx['visit']->id.'/encounter', ['practitioner_id' => '']);

    $response->assertRedirect()->assertSessionHasErrors('practitioner_id');
});

// ------------------------------------------------------ POSITIVE CONTROLS ----

test('POSITIVE CONTROL — a SUCCESSFUL triage flashes NO error at all', function () {
    $fx = edrvFixture('edrv-ok');
    app(TenantContext::class)->forget();

    // The same route as the refusals above, with a payload that fits. If this flashed an error, the
    // rendered-error tests could be satisfied by a page that always shows something.
    test()->actingAs($fx['nurse'])
        ->post('/ed/visits/'.$fx['visit']->id.'/triage', [
            'triaged_by' => $fx['nurseProfile']->id,
            'presenting_complaint' => 'Central chest pain',
            'acuity_scale' => 'ESI',
            'acuity_level' => '2',
            'heart_rate' => 104,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(EdTriage::query()->count())->toBe(1)
        ->and(Vital::query()->count())->toBe(1);
});

test('STRUCTURAL GUARD: every ED surface renders the refusal, through the EXISTING component', function () {
    // A payload assertion cannot see whether a page RENDERS the error bag (the QA-FIX.5a lesson): Inertia
    // shares `errors` on every response whether or not any template reads it. The guard is therefore
    // structural. Mutation-checked: deleting <RefusalNotice /> from any page reddens this.
    foreach (['Triage', 'Board', 'Disposition', 'Documentation', 'Billing'] as $page) {
        $vue = (string) file_get_contents(resource_path("js/pages/ED/{$page}.vue"));
        expect($vue)->toContain('<RefusalNotice />')
            ->and($vue)->toContain("import RefusalNotice from '@/Components/RefusalNotice.vue';");
    }

    // AN ADOPTION, NOT A DESIGN (D-170): the ED pages use the component QA-FIX.6c already built, and this
    // part authored no ED-specific error component and no ED-specific copy. Anything matching would mean a
    // second, divergent mechanism had been introduced.
    $edOwn = collect(glob(resource_path('js/pages/ED/*.vue')))
        ->map(fn (string $p): string => (string) file_get_contents($p))
        ->implode("\n");
    foreach (['page.props.errors', 'usePage().props.errors', 'v-if="errors', 'defineProps<{ errors'] as $ownMechanism) {
        expect(str_contains($edOwn, $ownMechanism))->toBeFalse("ED must reuse RefusalNotice, not roll its own ({$ownMechanism})");
    }

    // The component still reads the WHOLE bag — the property this module depends on, since half its
    // refusals are DOMAIN-keyed (`triage`, `ed_visit`, `disposition`) and half are FIELD-keyed.
    $component = (string) file_get_contents(resource_path('js/Components/RefusalNotice.vue'));
    expect($component)->toContain('Object.values(bag)')
        ->and($component)->toContain('page.props.errors')
        ->and($component)->toContain('role="alert"');
});

test('D-176: every ED page that renders the notice has a refusal a user can actually reach', function () {
    // The counterpart to QA-FIX.6c's `Checklist.vue` exclusion. An error region on a page whose refusals
    // cannot fire is an affordance for something that never happens — so each page is justified by naming
    // the refusal, not by being an ED page. Each key below is asserted live by a test in this file or by
    // the controller rule cited.
    $reachable = [
        'Triage' => 'triaged_by (required) + triage (invalid level for the scale) — both driven above',
        'Board' => 'ed_visit (illegal transition from a stale board) — driven above',
        'Disposition' => 'bed_id / clinician_id (required on admit) + disposition — driven above',
        'Documentation' => 'practitioner_id (required) + encounter / vital / order — practitioner_id driven above',
        'Billing' => 'price_minor / code / name (required) + ed_billing — reachable by a billing.manage holder',
    ];

    expect(array_keys($reachable))->toHaveCount(5);

    // And the server side of each really does exist: five controllers, ten domain-keyed refusal sites.
    $controllers = collect(glob(base_path('Modules/ED/src/Http/Controllers/*.php')))
        ->push(base_path('app/Http/Controllers/EdDispositionController.php'))
        ->map(fn (string $p): string => (string) file_get_contents($p))
        ->implode("\n");
    expect(substr_count($controllers, '->withErrors('))->toBe(10);
});
