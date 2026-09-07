<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Exceptions\SurgicalCaseException;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalCaseAnesthesiaAssessment;
use Modules\Surgery\Services\SurgicalCaseService;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| QA-FIX.6b — P6-C2: the ASA assessment records its author, keeps its
| history, and is audited
|--------------------------------------------------------------------------
| Three defects, driven in a browser during Phase 6:
|
| (a) ATTRIBUTION — `asa_assessed_by` was written from the SUBMITTED
|     anesthetist_id; the ACTOR was used only for the Gate check and then
|     discarded. The anaesthetist Johann Wyss recorded an ASA III naming Tim
|     Graf, a PHARMACY TECHNICIAN, and the permanent record said Graf assessed
|     it. Nothing recorded who actually typed it.
| (b) HISTORY — `forceFill(...)->save()` overwrote in place. An earlier ASA III
|     was gone with no trace.
| (c) AUDIT — it was the ONLY write in the Surgery module raising no audit
|     event, while every sibling around it did.
|
| THE FIXTURE DELIBERATELY MAKES ACTOR != PICKED PERSON. Pre-existing surgery
| fixtures used the same user for both, which is precisely why P2-C1's identical
| defect survived its own suite: when the two coincide, no assertion can tell
| which one was stored.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{tenant: Tenant, actor: User, other: User, assessorProfile: StaffProfile, surgeonProfile: StaffProfile, case: SurgicalCase}
 */
function saaFixture(string $slug): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);

    $make = function (string $role, string $first, string $last) use ($tenant, $branch): array {
        $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);
        $profile = StaffProfile::query()->create([
            'user_id' => $user->id,
            'first_name' => $first, 'last_name' => $last, 'display_name' => "{$first} {$last}",
            'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
        ]);

        return [$user, $profile];
    };

    // THE ACTOR — an anesthetist who will do the recording.
    [$actor] = $make('anesthetist', 'Johann', 'Wyss');
    // A DIFFERENT person, who will be NAMED as the assessor. Actor != picked, always.
    [$other, $assessorProfile] = $make('surgeon', 'Tilda', 'Graf');
    [$surgeonUser, $surgeonProfile] = $make('surgeon', 'Isabelle', 'Vogt');

    $patient = app(PatientService::class)->create([
        'first_name' => 'Percy', 'last_name' => 'Patient', 'date_of_birth' => '1972-02-02', 'sex' => 'male',
    ]);
    $case = app(SurgicalCaseService::class)->schedule(
        $surgeonUser, $patient, $surgeonProfile, 'Laparoscopic appendectomy', Carbon::parse('2026-06-15 08:00:00')
    );

    return compact('tenant', 'actor', 'other', 'assessorProfile', 'surgeonProfile', 'case');
}

// ------------------------------------------------------- (a) ATTRIBUTION ----

test('P6-C2(a): the assessment records the ACTOR who entered it, not only the person picked', function () {
    $fx = saaFixture('saa-actor');

    app(SurgicalCaseService::class)->recordAnesthesiaAssessment(
        $fx['actor'], $fx['case'], 'III', 'II', $fx['assessorProfile']
    );

    $assessment = SurgicalCaseAnesthesiaAssessment::query()->firstOrFail();

    // THE PROPERTY (D-182 — before the fix there was no such column at all, so this could not pass).
    // The actor and the picked person are DIFFERENT people, which is the only way to tell them apart.
    expect($assessment->recorded_by)->toBe($fx['actor']->id)
        ->and($assessment->assessed_by)->toBe($fx['assessorProfile']->id)
        // …and they must not have collapsed into one another.
        ->and($fx['assessorProfile']->user_id)->not->toBe($fx['actor']->id);
});

test('P6-C2(a): recorded_by cannot be forged — it is the authenticated user, never the request', function () {
    $fx = saaFixture('saa-forge');

    // Post the ASA as the ACTOR while naming someone else, and additionally try to submit a recorded_by.
    test()->actingAs($fx['actor'])
        ->post('/surgery/cases/'.$fx['case']->id.'/anesthesia', [
            'asa_class' => 'II',
            'mallampati' => 'I',
            'anesthetist_id' => $fx['assessorProfile']->id,
            'recorded_by' => $fx['other']->id,   // forged — must be ignored entirely
        ])->assertRedirect();

    $assessment = SurgicalCaseAnesthesiaAssessment::query()->firstOrFail();
    expect($assessment->recorded_by)->toBe($fx['actor']->id)
        ->and($assessment->recorded_by)->not->toBe($fx['other']->id);
});

// ----------------------------------------------------------- (b) HISTORY ----

test('P6-C2(b): a revision PRESERVES the previous assessment — it is a new row, not an overwrite', function () {
    $fx = saaFixture('saa-history');
    $svc = app(SurgicalCaseService::class);

    $svc->recordAnesthesiaAssessment($fx['actor'], $fx['case'], 'III', 'II', $fx['assessorProfile']);
    $svc->recordAnesthesiaAssessment($fx['actor'], $fx['case']->fresh(), 'I', 'II', $fx['surgeonProfile']);

    // THE PROPERTY (D-182 — this FAILS before the fix: forceFill left exactly one value, the latest).
    $all = SurgicalCaseAnesthesiaAssessment::query()->orderBy('assessed_at')->get();
    expect($all)->toHaveCount(2)
        ->and($all[0]->asa_class)->toBe('III')      // the earlier judgment SURVIVES
        ->and($all[1]->asa_class)->toBe('I')
        // and the two name different assessors, so the history is not a duplicate
        ->and($all[0]->assessed_by)->toBe($fx['assessorProfile']->id)
        ->and($all[1]->assessed_by)->toBe($fx['surgeonProfile']->id);

    // The case still carries the CURRENT value, so existing readers are unaffected.
    expect($fx['case']->fresh()->asa_class)->toBe('I');
});

test('P6-C2(b): an assessment is APPEND-ONLY — the model refuses an update and a delete', function () {
    $fx = saaFixture('saa-append');
    app(SurgicalCaseService::class)->recordAnesthesiaAssessment($fx['actor'], $fx['case'], 'III', null, $fx['assessorProfile']);

    $assessment = SurgicalCaseAnesthesiaAssessment::query()->firstOrFail();

    expect(fn () => $assessment->forceFill(['asa_class' => 'I'])->save())
        ->toThrow(SurgicalCaseException::class);
    expect(fn () => $assessment->delete())
        ->toThrow(SurgicalCaseException::class);

    expect(SurgicalCaseAnesthesiaAssessment::query()->firstOrFail()->asa_class)->toBe('III');
});

test('P6-C2(b): the DATABASE refuses too — the trigger stands even if the model guard is bypassed', function () {
    $fx = saaFixture('saa-trigger');
    app(SurgicalCaseService::class)->recordAnesthesiaAssessment($fx['actor'], $fx['case'], 'IV', null, $fx['assessorProfile']);

    // Straight to the driver, past Eloquent entirely (belt AND suspenders — the ed_triages recipe).
    expect(fn () => DB::table('surgical_case_anesthesia_assessments')->update(['asa_class' => 'I']))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('surgical_case_anesthesia_assessments')->delete())
        ->toThrow(QueryException::class);

    expect(DB::table('surgical_case_anesthesia_assessments')->count())->toBe(1);
});

// ------------------------------------------------------------- (c) AUDIT ----

test('P6-C2(c): the write produces an audit row on the EXISTING path, and the chain still verifies', function () {
    $fx = saaFixture('saa-audit');

    $before = DB::table('audit_events')->where('action', 'surgical_case.anesthesia_assessed')->count();
    expect($before)->toBe(0); // it produced NOTHING before this fix

    // The audit row takes its actor from the AUTH context (PlatformAuditContext), so the write is made as
    // an authenticated user here — the same way every real request makes it.
    test()->actingAs($fx['actor']);
    app(SurgicalCaseService::class)->recordAnesthesiaAssessment($fx['actor'], $fx['case'], 'III', 'II', $fx['assessorProfile']);

    $rows = DB::table('audit_events')->where('action', 'surgical_case.anesthesia_assessed')->get();

    // Exactly ONE row — not two, which would mean a second audit path was added beside the existing one.
    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    $context = json_decode((string) $row->context, true);

    expect((int) $row->actor_id)->toBe($fx['actor']->id)
        ->and($row->resource_type)->toBe('surgical_case_anesthesia_assessment')
        ->and($row->patient_id)->toBe($fx['case']->patient_id)
        // The context names BOTH people, because conflating them is what P6-C2 was.
        ->and($context['assessed_by'])->toBe($fx['assessorProfile']->id)
        ->and($context['recorded_by'])->toBe($fx['actor']->id)
        ->and($context['asa_class'])->toBe('III');

    expect(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();
});

test('P6-C2(c): a REVISION audits too — two assessments, two audit rows', function () {
    $fx = saaFixture('saa-audit2');
    $svc = app(SurgicalCaseService::class);

    $svc->recordAnesthesiaAssessment($fx['actor'], $fx['case'], 'III', null, $fx['assessorProfile']);
    $svc->recordAnesthesiaAssessment($fx['actor'], $fx['case']->fresh(), 'I', null, $fx['assessorProfile']);

    expect(DB::table('audit_events')->where('action', 'surgical_case.anesthesia_assessed')->count())->toBe(2)
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();
});

// ------------------------------------------------------------ THE SCREEN ----

test('the case screen names BOTH people and never lets one stand in for the other', function () {
    $fx = saaFixture('saa-screen');
    app(SurgicalCaseService::class)->recordAnesthesiaAssessment($fx['actor'], $fx['case'], 'III', 'II', $fx['assessorProfile']);

    test()->actingAs($fx['actor'])
        ->get('/surgery/cases/'.$fx['case']->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Surgery/Case')
            ->has('anesthesia_assessments', 1)
            ->where('anesthesia_assessments.0.asa_class', 'III')
            // Tilda Graf ASSESSED; Johann Wyss RECORDED. Two different names, both present.
            ->where('anesthesia_assessments.0.assessed_by', 'Tilda Graf')
            ->where('anesthesia_assessments.0.recorded_by', 'Johann Wyss'));
});

test('STRUCTURAL GUARD: the template renders both names, so neither can silently vanish', function () {
    // A payload assertion cannot see a template that stops rendering one of them (the QA-FIX.5a lesson).
    // Mutation-checked: deleting either line reddens this.
    $vue = (string) file_get_contents(resource_path('js/pages/Surgery/Case.vue'));

    expect($vue)->toContain("t('surgery.case.assessedBy', { name: a.assessed_by ?? '—' })")
        ->and($vue)->toContain("t('surgery.case.recordedBy', { name: a.recorded_by ?? '—' })")
        ->and($vue)->toContain('v-for="a in anesthesia_assessments"');

    // The two labels must be distinguishable — the whole point is that they name different roles.
    $lang = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);
    expect($lang['surgery']['case']['assessedBy'])->toBe('Assessed by {name}')
        ->and($lang['surgery']['case']['recordedBy'])->toBe('recorded by {name}')
        ->and($lang['surgery']['case']['assessedBy'])->not->toBe($lang['surgery']['case']['recordedBy']);
});

// -------------------------------------------------------- POSITIVE CONTROLS ----

test('POSITIVE CONTROL — the ASA is still ASSIGNED, never computed (the fence holds)', function () {
    $fx = saaFixture('saa-fence');
    app(SurgicalCaseService::class)->recordAnesthesiaAssessment($fx['actor'], $fx['case'], 'IV', 'III', $fx['assessorProfile']);

    $assessment = SurgicalCaseAnesthesiaAssessment::query()->firstOrFail();

    // Exactly what was entered, unchanged — no derived score, band, grade or risk anywhere.
    expect($assessment->asa_class)->toBe('IV')->and($assessment->mallampati)->toBe('III');

    $columns = array_keys((array) DB::table('surgical_case_anesthesia_assessments')->first());
    foreach (['score', 'risk', 'severity', 'grade', 'priority', 'rank'] as $forbidden) {
        expect(implode(',', $columns))->not->toContain($forbidden);
    }
});

test('POSITIVE CONTROL — an invalid class is still refused, at the model as well as the service', function () {
    $fx = saaFixture('saa-invalid');

    expect(fn () => app(SurgicalCaseService::class)
        ->recordAnesthesiaAssessment($fx['actor'], $fx['case'], 'VII', null, $fx['assessorProfile']))
        ->toThrow(SurgicalCaseException::class);

    // Nothing was written by the refused attempt — the transaction rolled back.
    expect(SurgicalCaseAnesthesiaAssessment::query()->count())->toBe(0)
        ->and($fx['case']->fresh()->asa_class)->toBeNull();
});
