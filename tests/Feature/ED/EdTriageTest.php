<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\Clinical\Models\Vital;
use Modules\ED\Contracts\TriageAcuityProvider;
use Modules\ED\Exceptions\EdVisitException;
use Modules\ED\Models\EdTriage;
use Modules\ED\Models\EdVisit;
use Modules\ED\Services\EdVisitService;
use Modules\ED\Services\NullTriageAcuityProvider;
use Modules\ED\Services\TriageService;
use Modules\ED\Support\AcuityContext;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * ED.G2 — triage: the nurse-ASSIGNED acuity + presenting complaint + RAW vitals, recorded on an EdVisit
 * (arrived → triaged). Per docs/HOSPITAL-PHASE6-ED-MAP.md. THE FENCE: the acuity is the value the NURSE
 * assigns (a recorded fact, the ASA precedent) — nothing computes/suggests it; the triage-acuity seam returns
 * none() today.
 */

function edtCtx(): TenantContext
{
    return app(TenantContext::class);
}

function edtUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, branch: Branch, triageNurse: User, edPhysician: User, reception: User, nurseProfile: StaffProfile, patient: Patient, visit: EdVisit} */
function edtFixture(string $slug = 'edtri'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' ED', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    edtCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $triageNurse = edtUser($tenant, 'triage_nurse');  // triage.record + ed.manage + note.write
    $edPhysician = edtUser($tenant, 'ed_physician');  // ed.manage, NO triage.record
    $reception = edtUser($tenant, 'reception');        // neither
    $nurseProfile = StaffProfile::query()->create([
        'first_name' => 'Nadia', 'last_name' => 'Nurse', 'display_name' => 'Nadia Nurse',
        'profession' => 'nurse', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $visit = app(EdVisitService::class)->register($triageNurse, $patient, $branch, EdVisit::ARRIVAL_AMBULANCE, 'Chest pain');

    return compact('tenant', 'branch', 'triageNurse', 'edPhysician', 'reception', 'nurseProfile', 'patient', 'visit');
}

test('triage records the nurse-ASSIGNED acuity + complaint + raw vitals; the visit moves arrived → triaged; audited + read-logged', function () {
    $fx = edtFixture();

    $triage = app(TriageService::class)->record(
        $fx['triageNurse'], $fx['visit'], $fx['nurseProfile'],
        'Central chest pain, radiating', EdTriage::SCALE_ESI, '2',
        ['systolic' => 148, 'diastolic' => 92, 'heart_rate' => 104, 'spo2' => 96],
    );

    expect($triage->acuity_scale)->toBe('ESI')
        ->and($triage->acuity_level)->toBe('2')                    // the value the NURSE assigned
        ->and($triage->triaged_by)->toBe($fx['nurseProfile']->id)  // provenance
        ->and($triage->presenting_complaint)->toBe('Central chest pain, radiating')
        // The visit advanced arrived → triaged (the G1 legal transition).
        ->and($fx['visit']->fresh()->status)->toBe(EdVisit::STATUS_TRIAGED)
        // RAW vitals recorded through the EXISTING Vital store (patient-scoped, no encounter at triage).
        ->and(Vital::query()->where('patient_id', $fx['patient']->id)->count())->toBe(1)
        ->and(Vital::query()->firstOrFail()->systolic)->toBe(148)
        // Audited (patient-scoped), so ED stays free of Audit.
        ->and(AuditEvent::query()->where('action', 'ed_triage.recorded')->where('patient_id', $fx['patient']->id)->count())->toBe(1);

    // Read-logged (LogsReads wired to patient_id).
    $triage->auditRead();
    expect(AuditEvent::query()->where('action', 'read')->where('resource_type', 'ed_triages')->where('patient_id', $fx['patient']->id)->count())->toBe(1);
});

test('FENCE: the acuity is the nurse ASSIGNED value, never computed/suggested; the seam returns none()', function () {
    $fx = edtFixture();

    // The seam (threaded) returns no suggestion today — the null-object computes nothing.
    $provider = app(TriageAcuityProvider::class);
    expect($provider)->toBeInstanceOf(NullTriageAcuityProvider::class)
        ->and(app(TriageService::class)->acuitySuggestion($fx['visit'])->hasSuggestion())->toBeFalse()
        ->and($provider->suggestAcuity(new AcuityContext('Chest pain', ['hr' => 130]))->hasSuggestion())->toBeFalse();

    // Recording uses ONLY the nurse's assigned value — the same complaint+vitals never yields a different or
    // system-derived level; the level stored is exactly what the nurse passed.
    $triage = app(TriageService::class)->record($fx['triageNurse'], $fx['visit'], $fx['nurseProfile'], 'Chest pain', EdTriage::SCALE_ESI, '3', ['heart_rate' => 130]);
    expect($triage->acuity_level)->toBe('3'); // the assigned value, verbatim (not a computed acuity for a HR of 130)

    // The triage record carries NO computed-acuity/suggested/score field beyond the assigned value + provenance.
    $forbidden = ['suggested', 'computed', 'score', 'severity', 'priority', 'deterioration', 'risk', 'grade', 'rank', 'esi_score'];
    $columns = Schema::getColumnListing('ed_triages');
    foreach ($forbidden as $word) {
        expect($columns)->not->toContain($word, "ed_triages must not carry a computed-judgment column: {$word}");
    }

    // No homemade acuity-computation anywhere in the module — the null-object is the only seam impl.
    $files = collect(File::allFiles(base_path('Modules/ED/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['computeAcuity', 'calculateAcuity', 'scoreAcuity', 'assignAcuity', 'triageScore', 'computeTriage', 'deriveAcuity'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("ED must not compute a triage acuity ({$needle})");
        }
    }
});

test('an invalid acuity scale or a level not on the scale is rejected (data-entry validation, not a grade)', function () {
    $fx = edtFixture();
    $svc = app(TriageService::class);

    expect(fn () => $svc->record($fx['triageNurse'], $fx['visit'], $fx['nurseProfile'], 'Pain', 'APACHE', '2'))
        ->toThrow(EdVisitException::class); // unknown scale
    expect(fn () => $svc->record($fx['triageNurse'], $fx['visit'], $fx['nurseProfile'], 'Pain', EdTriage::SCALE_ESI, '9'))
        ->toThrow(EdVisitException::class); // 9 is not an ESI level
    expect(fn () => $svc->record($fx['triageNurse'], $fx['visit'], $fx['nurseProfile'], '   ', EdTriage::SCALE_ESI, '2'))
        ->toThrow(EdVisitException::class); // complaint required

    // Manchester uses colours, not numbers — a valid colour is accepted, a number is not.
    expect($svc->record($fx['triageNurse'], $fx['visit'], $fx['nurseProfile'], 'Pain', EdTriage::SCALE_MANCHESTER, 'orange')->acuity_level)->toBe('orange');
    expect(fn () => $svc->record($fx['triageNurse'], $fx['visit']->fresh(), $fx['nurseProfile'], 'Pain', EdTriage::SCALE_MANCHESTER, '2'))
        ->toThrow(EdVisitException::class);
});

test('triage is APPEND-ONLY: a re-triage is a new row preserving history (model guard + DB trigger)', function () {
    $fx = edtFixture();
    $svc = app(TriageService::class);

    $first = $svc->record($fx['triageNurse'], $fx['visit'], $fx['nurseProfile'], 'Chest pain', EdTriage::SCALE_ESI, '3');
    // A re-triage: a NEW record (a different assigned level), the visit already triaged (status kept).
    $svc->record($fx['triageNurse'], $fx['visit']->fresh(), $fx['nurseProfile'], 'Worsening chest pain', EdTriage::SCALE_ESI, '2');

    expect(EdTriage::query()->where('ed_visit_id', $fx['visit']->id)->count())->toBe(2)   // history preserved
        ->and($fx['visit']->fresh()->status)->toBe(EdVisit::STATUS_TRIAGED);              // no illegal re-transition

    // Model guard (belt).
    expect(fn () => $first->update(['acuity_level' => '1']))->toThrow(EdVisitException::class);

    // DB trigger (suspenders) — a raw update/delete bypassing the model still fails.
    expect(fn () => DB::table('ed_triages')->where('id', $first->id)->update(['acuity_level' => '1']))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('ed_triages')->where('id', $first->id)->delete())
        ->toThrow(QueryException::class);
});

test('raw vitals at triage are recorded RAW (no bands/flags/scores) and are optional', function () {
    $fx = edtFixture();
    $svc = app(TriageService::class);

    // No vitals given → a triage still records, and no Vital row is created.
    $svc->record($fx['triageNurse'], $fx['visit'], $fx['nurseProfile'], 'Ankle pain', EdTriage::SCALE_ESI, '4');
    expect(Vital::query()->where('patient_id', $fx['patient']->id)->count())->toBe(0);

    // With vitals → a raw Vital row; the vitals table carries no band/flag/score column (the existing fence).
    $svc->record($fx['triageNurse'], $fx['visit']->fresh(), $fx['nurseProfile'], 'Ankle pain', EdTriage::SCALE_ESI, '4', ['heart_rate' => 88, 'spo2' => 99]);
    $vital = Vital::query()->where('patient_id', $fx['patient']->id)->firstOrFail();
    expect($vital->heart_rate)->toBe(88)->and($vital->spo2)->toBe(99);

    $forbidden = ['band', 'flag', 'score', 'severity', 'abnormal', 'news2', 'acuity'];
    $columns = Schema::getColumnListing('vitals');
    foreach ($forbidden as $word) {
        expect($columns)->not->toContain($word, "vitals must stay raw: no {$word} column");
    }
});

test('RBAC: recording triage is triage.record-gated; ed.manage alone (ED physician) and reception are refused', function () {
    $fx = edtFixture();
    $svc = app(TriageService::class);

    // The ED physician holds ed.manage but NOT triage.record — refused at the triage gate.
    expect(fn () => $svc->record($fx['edPhysician'], $fx['visit'], $fx['nurseProfile'], 'Chest pain', EdTriage::SCALE_ESI, '2'))
        ->toThrow(AuthorizationException::class);
    // Reception holds neither.
    expect(fn () => $svc->record($fx['reception'], $fx['visit'], $fx['nurseProfile'], 'Chest pain', EdTriage::SCALE_ESI, '2'))
        ->toThrow(AuthorizationException::class);

    // The triage nurse (triage.record) can record.
    expect($svc->record($fx['triageNurse'], $fx['visit'], $fx['nurseProfile'], 'Chest pain', EdTriage::SCALE_ESI, '2')->acuity_level)->toBe('2');
});

test('cross-tenant is fail-closed: a tenant cannot triage another tenant ED visit', function () {
    $fxA = edtFixture('alpha');
    $visitA = $fxA['visit'];

    $fxB = edtFixture('beta');
    edtCtx()->set($fxB['tenant']);

    expect(fn () => app(TriageService::class)->record($fxB['triageNurse'], $visitA, $fxB['nurseProfile'], 'Chest pain', EdTriage::SCALE_ESI, '2'))
        ->toThrow(CrossTenantReferenceException::class);
});
