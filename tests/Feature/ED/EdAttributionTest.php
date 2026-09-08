<?php

use App\Services\EdDispositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Models\AuditEvent;
use Modules\ED\Models\EdTriage;
use Modules\ED\Models\EdVisit;
use Modules\ED\Services\EdVisitService;
use Modules\ED\Services\TriageService;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\StayEvent;
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
| QA-FIX.7a — P7-C1 + P7-C2: ED triage and admission record the ACTOR
|--------------------------------------------------------------------------
| Two writes that SUCCEEDED and left the wrong fact behind. Driven in Phase 7:
| triaging as `yusuf.demir` produced a record reading "Triaged by Beat Suter",
| and admitting as `clara.meier` produced an inpatient stay naming the same
| person as admitting clinician. Beat Suter is a `surgical_scheduler` who was
| not present and is not a clinician; he sorts first alphabetically, and both
| forms pre-selected the first entry of an unfiltered staff list. Nothing
| failed, nothing was refused, and no error existed to find.
|
| THE REMEDY IS QA-FIX.6b's, UNCHANGED (P6-C2, the ASA): name BOTH people, take
| the actor from the session and never from the request, and prove a submitted
| actor is ignored. The two halves differ in ONE respect, deliberately:
|   - a triage gains `ed_triages.recorded_by`, because a RE-TRIAGE appends no
|     ed_visit_event, so there was nowhere else the actor could live;
|   - an admission gains NO column, because `stay_events.performed_by` already
|     records it from AdmissionService's own `$actor` inside the admit
|     transaction. A second home for one fact is what D-199 exists to prevent.
|
| The BED is not a person, so it is not an attribution — it is here because
| admitting CLAIMS the bed (free → occupied), and a default answered "which
| bed" with "the alphabetically first free one", which is not a reason.
*/

function edaCtx(): TenantContext
{
    return app(TenantContext::class);
}

function edaUser(Tenant $tenant, string $role): User
{
    // twoFactorEnabled so the mandatory-MFA middleware lets the request reach the route/gate (HTTP tests).
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** Flow a visit arrived → triaged → in_treatment → awaiting_disposition (the admit precondition). */
function edaAwaiting(User $actor, EdVisit $visit): EdVisit
{
    $visits = app(EdVisitService::class);
    foreach ([EdVisit::STATUS_TRIAGED, EdVisit::STATUS_IN_TREATMENT, EdVisit::STATUS_AWAITING_DISPOSITION] as $to) {
        $visits->transition($actor, $visit, $to);
        $visit = $visit->fresh();
    }

    return $visit;
}

/** @return array{tenant: Tenant, branch: Branch, actor: User, second: User, admitter: User, other: User, nurseProfile: StaffProfile, patient: Patient, visit: EdVisit, ward: Ward, bed: Bed} */
function edaFixture(string $slug = 'edattr'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' ED', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    edaCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);

    $actor = edaUser($tenant, 'triage_nurse');       // triage.record — the person at the keyboard
    $second = edaUser($tenant, 'ed_charge_nurse');   // ALSO triage.record — the second recorder
    $admitter = edaUser($tenant, 'ed_physician');    // ed.manage + admission.manage, NO triage.record
    $other = edaUser($tenant, 'reception');          // an uninvolved account, never the actor

    $profile = fn (string $first, string $last, string $profession, ?User $user = null): StaffProfile => StaffProfile::query()->create([
        'first_name' => $first, 'last_name' => $last, 'display_name' => $first.' '.$last,
        'profession' => $profession, 'primary_branch_id' => $branch->id,
        'status' => StaffProfile::STATUS_ACTIVE, 'user_id' => $user?->id,
    ]);
    // The ACTORS' own profiles, so a page renders a display name rather than falling back to an email.
    $profile('Alma', 'Actor', 'nurse', $actor);
    $profile('Sam', 'Second', 'nurse', $second);
    $profile('Pia', 'Physician', 'doctor', $admitter);
    // The person the dropdown NAMES — deliberately someone else, which is the whole point. `Aaron Aardvark`
    // sorts first in the unfiltered list, so he is what the old default would have selected: the Beat Suter
    // shape, reproduced.
    $profile('Aaron', 'Aardvark', 'scheduler');
    $nurseProfile = $profile('Nadia', 'Nurse', 'nurse');

    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $visit = app(EdVisitService::class)->register($actor, $patient, $branch, EdVisit::ARRIVAL_AMBULANCE, 'Chest pain');

    $ward = Ward::query()->create(['branch_id' => $branch->id, 'name' => 'Acute Ward', 'code' => 'AW']);
    $bed = Bed::query()->create(['branch_id' => $branch->id, 'ward_id' => $ward->id, 'label' => 'A1', 'bed_type' => Bed::TYPE_GENERAL]);

    return compact('tenant', 'branch', 'actor', 'second', 'admitter', 'other', 'nurseProfile', 'patient', 'visit', 'ward', 'bed');
}

// ------------------------------------------------ P7-C1 — TRIAGE: THE ACTOR ----

test('P7-C1: a triage records the ACTOR who entered it, not only the nurse named', function () {
    $fx = edaFixture('eda-actor');

    // The Phase-7 gesture exactly: the actor records a triage naming SOMEONE ELSE.
    app(TriageService::class)->record(
        $fx['actor'], $fx['visit'], $fx['nurseProfile'], 'Central chest pain', EdTriage::SCALE_ESI, '2'
    );

    $triage = EdTriage::query()->firstOrFail();

    // THE PROPERTY (D-182 — before the fix there was no column at all, so this could not be asked).
    expect($triage->recorded_by)->toBe($fx['actor']->id)
        // …and the two people stay distinct: the nurse named has no account of her own (D-195).
        ->and($triage->triaged_by)->toBe($fx['nurseProfile']->id)
        ->and($triage->triagedBy->user_id)->toBeNull();
});

test('P7-C1: recorded_by cannot be forged — it is the authenticated user, never the request', function () {
    $fx = edaFixture('eda-forge');
    edaCtx()->forget(); // request-level: the middleware re-establishes context from the authed user

    // Post as the ACTOR while naming someone else, and additionally try to submit a recorded_by.
    test()->actingAs($fx['actor'])
        ->post('/ed/visits/'.$fx['visit']->id.'/triage', [
            'triaged_by' => $fx['nurseProfile']->id,
            'presenting_complaint' => 'Central chest pain',
            'acuity_scale' => 'ESI',
            'acuity_level' => '2',
            'recorded_by' => $fx['other']->id,   // forged — must be ignored entirely
        ])->assertRedirect();

    $triage = EdTriage::query()->firstOrFail();
    expect($triage->recorded_by)->toBe($fx['actor']->id)
        ->and($triage->recorded_by)->not->toBe($fx['other']->id);
});

test('P7-C1: a RE-TRIAGE records the actor too — the case with no ed_visit_event to fall back on', function () {
    $fx = edaFixture('eda-retriage');
    $svc = app(TriageService::class);

    $svc->record($fx['actor'], $fx['visit'], $fx['nurseProfile'], 'Chest pain', EdTriage::SCALE_ESI, '3');
    // A DIFFERENT actor re-triages the SAME visit. The visit is already `triaged`, so no transition happens
    // and NO ed_visit_event is appended — which is exactly why the triage row needed its own recorder.
    $svc->record($fx['second'], $fx['visit']->fresh(), $fx['nurseProfile'], 'Worsening', EdTriage::SCALE_ESI, '2');

    $all = EdTriage::query()->orderBy('triaged_at')->get();
    expect($all)->toHaveCount(2)
        ->and($all[0]->recorded_by)->toBe($fx['actor']->id)
        ->and($all[1]->recorded_by)->toBe($fx['second']->id)
        // The fallback that does NOT exist: only ONE `triaged` event was ever appended, for the first triage.
        ->and(DB::table('ed_visit_events')->where('event_type', 'triaged')->count())->toBe(1);
});

test('P7-C1: the audit context carries the actor as well as the named nurse', function () {
    $fx = edaFixture('eda-audit');
    // The audit actor comes from the AUTH context, so a service-level write must actingAs (the 6b lesson).
    test()->actingAs($fx['actor']);

    app(TriageService::class)->record($fx['actor'], $fx['visit'], $fx['nurseProfile'], 'Chest pain', EdTriage::SCALE_ESI, '2');

    $event = AuditEvent::query()->where('action', 'ed_triage.recorded')->firstOrFail();
    $context = $event->context; // cast to array — never assert audit JSON as a raw substring

    expect($context['triaged_by'])->toBe($fx['nurseProfile']->id)
        ->and($context['recorded_by'])->toBe($fx['actor']->id)
        ->and((int) $event->actor_id)->toBe($fx['actor']->id);
});

test('P7-C1: the triage screen names BOTH people and never lets one stand in for the other', function () {
    $fx = edaFixture('eda-screen');
    app(TriageService::class)->record($fx['actor'], $fx['visit'], $fx['nurseProfile'], 'Chest pain', EdTriage::SCALE_ESI, '2');
    edaCtx()->forget();

    test()->actingAs($fx['actor'])
        ->get('/ed/visits/'.$fx['visit']->id.'/triage')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ED/Triage')
            ->where('triages.0.triaged_by', 'Nadia Nurse')   // the nurse named
            ->where('triages.0.recorded_by', 'Alma Actor')   // the person who typed it
            ->etc());
});

test('P7-C1: the form pre-selects NO nurse — an attribution is never a default', function () {
    // Structural, and it is the whole defect: a payload assertion cannot see what the form initialises to.
    // Mutation-checked — restoring `props.options.nurses[0]?.id` reddens this.
    $vue = (string) file_get_contents(resource_path('js/pages/ED/Triage.vue'));

    expect($vue)->toContain("triaged_by: ''")
        ->and($vue)->not->toContain('props.options.nurses[0]')
        // …and the empty value is a real, non-selectable prompt, matched by the server's `required` rule.
        ->and($vue)->toContain('<option value="" disabled>{{ t(\'ed.triage.selectNurse\') }}</option>')
        ->and($vue)->toContain('v-model="form.triaged_by" required');
});

// ------------------------------------------- P7-C2 — ADMISSION: THE ACTOR ----

test('P7-C2: an emergency admission already records the ACTOR — on the event, and it is not the clinician named', function () {
    $fx = edaFixture('eda-admit');
    $visit = edaAwaiting($fx['admitter'], $fx['visit']);

    // The admitter admits while NAMING a different clinician — the legitimate case (a charge nurse admitting
    // on the physician's decision), and the one the old default used to produce by accident.
    app(EdDispositionService::class)->admit($fx['admitter'], $visit, $fx['bed'], $fx['nurseProfile'], 'Observation');

    $stay = Stay::query()->firstOrFail();
    $event = StayEvent::query()->where('stay_id', $stay->id)->where('event_type', StayEvent::TYPE_ADMITTED)->firstOrFail();

    expect($stay->admitting_clinician_id)->toBe($fx['nurseProfile']->id) // the clinician NAMED
        ->and($event->performed_by)->toBe($fx['admitter']->id)           // the ACTOR — written, never request-sourced
        ->and($stay->admission_type)->toBe(Stay::TYPE_EMERGENCY);
});

test('P7-C2: NO second home for the actor was created — `stays` gains no recorder column', function () {
    // The other half of the decision: `stay_events.performed_by` is the one place the admission actor lives.
    // A `stays.recorded_by` would be a second independently-writable copy of one fact (D-199). Pinned so a
    // later "consistency with ed_triages" pass does not add one.
    expect(Schema::getColumnListing('stays'))->not->toContain('recorded_by');
});

test('P7-C2: the disposition screen names BOTH people for an admitted visit', function () {
    $fx = edaFixture('eda-dispo-screen');
    $visit = edaAwaiting($fx['admitter'], $fx['visit']);
    app(EdDispositionService::class)->admit($fx['admitter'], $visit, $fx['bed'], $fx['nurseProfile'], 'Observation');
    edaCtx()->forget();

    test()->actingAs($fx['admitter'])
        ->get('/ed/visits/'.$fx['visit']->id.'/disposition')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ED/Disposition')
            ->where('stay.admitting_clinician', 'Nadia Nurse') // the clinician named
            ->where('stay.recorded_by', 'Pia Physician')       // the person who admitted
            ->etc());
});

test('P7-C2: the form pre-selects NEITHER the clinician NOR the bed', function () {
    // Mutation-checked — restoring either `props.actions.*[0]?.id` default reddens this.
    $vue = (string) file_get_contents(resource_path('js/pages/ED/Disposition.vue'));

    expect($vue)->toContain("bed_id: '', clinician_id: ''")
        ->and($vue)->not->toContain('props.actions.beds[0]')
        ->and($vue)->not->toContain('props.actions.clinicians[0]')
        ->and($vue)->toContain('<option value="" disabled>{{ t(\'ed.disposition.selectBed\') }}</option>')
        ->and($vue)->toContain('<option value="" disabled>{{ t(\'ed.disposition.selectClinician\') }}</option>')
        ->and($vue)->toContain('v-model="form.bed_id" required')
        ->and($vue)->toContain('v-model="form.clinician_id" required');
});

// ------------------------------------------------------ POSITIVE CONTROLS ----

test('POSITIVE CONTROL — the server still REFUSES an admit with no bed and no clinician', function () {
    // Removing the client default must not have removed a rule: both fields were `required` server-side all
    // along, and an empty submission is refused rather than silently defaulted. If this passed, the empty
    // form would create a Stay in a bed nobody chose — worse than the defect being fixed.
    $fx = edaFixture('eda-refuse');
    edaAwaiting($fx['admitter'], $fx['visit']);
    edaCtx()->forget();

    test()->actingAs($fx['admitter'])
        ->from('/ed/visits/'.$fx['visit']->id.'/disposition')
        ->post('/ed/visits/'.$fx['visit']->id.'/disposition', ['disposition' => 'admit', 'bed_id' => '', 'clinician_id' => ''])
        ->assertRedirect()
        ->assertSessionHasErrors(['bed_id', 'clinician_id']);

    expect(Stay::query()->count())->toBe(0);
    edaCtx()->set($fx['tenant']);
    expect(Bed::query()->whereKey($fx['bed']->id)->value('status'))->toBe(Bed::STATUS_FREE); // NOT claimed
});

test('POSITIVE CONTROL — the server still REFUSES a triage with no nurse named', function () {
    $fx = edaFixture('eda-refuse-triage');
    edaCtx()->forget();

    test()->actingAs($fx['actor'])
        ->from('/ed/visits/'.$fx['visit']->id.'/triage')
        ->post('/ed/visits/'.$fx['visit']->id.'/triage', [
            'triaged_by' => '',
            'presenting_complaint' => 'Chest pain',
            'acuity_scale' => 'ESI',
            'acuity_level' => '2',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('triaged_by');

    expect(EdTriage::query()->count())->toBe(0);
});

test('POSITIVE CONTROL — the acuity is still ASSIGNED, never computed (the fence is untouched)', function () {
    // This part added an attribution column. It must not have added a judgment one: `recorded_by` says WHO
    // typed the nurse's value and nothing whatsoever about the value. Restated at column level because a new
    // column is precisely the moment one could slip in.
    $fx = edaFixture('eda-fence');

    $triage = app(TriageService::class)->record($fx['actor'], $fx['visit'], $fx['nurseProfile'], 'Chest pain', EdTriage::SCALE_ESI, '3', ['heart_rate' => 140]);
    expect($triage->acuity_level)->toBe('3'); // verbatim, for a heart rate of 140

    foreach (['suggested', 'computed', 'score', 'severity', 'priority', 'deterioration', 'risk', 'grade', 'rank'] as $word) {
        expect(Schema::getColumnListing('ed_triages'))->not->toContain($word);
    }
});
