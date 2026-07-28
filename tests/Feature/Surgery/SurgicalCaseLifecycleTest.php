<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\Clinical\Services\EncounterService;
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
use Modules\Surgery\Exceptions\SurgicalCaseException;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalCaseEncounter;
use Modules\Surgery\Models\SurgicalCaseEvent;
use Modules\Surgery\Models\SurgicalCaseTeamMember;
use Modules\Surgery\Services\SurgicalCaseService;

uses(RefreshDatabase::class);

/*
 * SURGERY.G2 — the surgical case lifecycle (legal-only state machine + append-only history + factual phase
 * times), op documentation REUSING Clinical's sign-and-lock Encounter/ClinicalNote (Encounter UNMODIFIED),
 * and the anesthetist-ASSIGNED ASA/Mallampati. Per docs/HOSPITAL-PHASE5-SURGERY-MAP.md. FENCE: recorded
 * facts, no computed surgical-risk.
 */

function scCtx(): TenantContext
{
    return app(TenantContext::class);
}

function scUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, branch: Branch, surgeon: User, anesthetist: User, reception: User, surgeonProfile: StaffProfile, anesthetistProfile: StaffProfile, patient: Patient, case: SurgicalCase} */
function scFixture(string $slug = 'surgcase'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Surgery', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    scCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $surgeon = scUser($tenant, 'surgeon');       // surgery.manage + note.write/sign + encounter.manage
    $anesthetist = scUser($tenant, 'anesthetist'); // surgery.manage + note.write/sign + encounter.manage
    $reception = scUser($tenant, 'reception');   // no surgery.manage / note.write / encounter.manage
    $surgeonProfile = StaffProfile::query()->create([
        'first_name' => 'Sara', 'last_name' => 'Sharp', 'display_name' => 'Dr Sara Sharp',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $anesthetistProfile = StaffProfile::query()->create([
        'first_name' => 'Amir', 'last_name' => 'Aziz', 'display_name' => 'Dr Amir Aziz',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Percy', 'last_name' => 'Patient', 'date_of_birth' => '1972-02-02', 'sex' => 'male']);
    $case = app(SurgicalCaseService::class)->schedule($surgeon, $patient, $surgeonProfile, 'Laparoscopic appendectomy', Carbon::parse('2026-08-20 08:00:00'));

    return compact('tenant', 'branch', 'surgeon', 'anesthetist', 'reception', 'surgeonProfile', 'anesthetistProfile', 'patient', 'case');
}

test('a case advances through its LEGAL lifecycle, recording a factual phase time + an append-only audited event each step', function () {
    $fx = scFixture();
    $svc = app(SurgicalCaseService::class);
    $case = $fx['case'];

    foreach ([SurgicalCase::STATUS_PRE_OP, SurgicalCase::STATUS_IN_PROGRESS, SurgicalCase::STATUS_COMPLETED, SurgicalCase::STATUS_POST_OP] as $to) {
        $svc->transition($fx['surgeon'], $case, $to);
        $case = $case->fresh();
    }

    expect($case->status)->toBe(SurgicalCase::STATUS_POST_OP)
        ->and($case->pre_op_at)->not->toBeNull()
        ->and($case->in_progress_at)->not->toBeNull()   // the "incision" time — a factual timestamp
        ->and($case->completed_at)->not->toBeNull()
        ->and($case->post_op_at)->not->toBeNull()
        // Append-only history: one immutable row per transition, ordered.
        ->and(SurgicalCaseEvent::query()->where('surgical_case_id', $case->id)->count())->toBe(4)
        // Each transition is audited (patient-scoped), so Surgery stays free of Audit.
        ->and(AuditEvent::query()->where('action', 'surgical_case.in_progress')->where('resource_type', 'surgical_case_event')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'surgical_case.completed')->where('patient_id', $fx['patient']->id)->count())->toBe(1);
});

test('the lifecycle is LEGAL-ONLY: illegal transitions throw', function () {
    $fx = scFixture();
    $svc = app(SurgicalCaseService::class);

    // Cannot skip pre_op: scheduled -> in_progress is illegal.
    expect(fn () => $svc->transition($fx['surgeon'], $fx['case'], SurgicalCase::STATUS_IN_PROGRESS))
        ->toThrow(SurgicalCaseException::class);

    // A cancelled case is terminal: cancelled -> completed is illegal.
    $svc->transition($fx['surgeon'], $fx['case'], SurgicalCase::STATUS_CANCELLED);
    expect($fx['case']->fresh()->status)->toBe(SurgicalCase::STATUS_CANCELLED)
        ->and(fn () => $svc->transition($fx['surgeon'], $fx['case']->fresh(), SurgicalCase::STATUS_COMPLETED))
        ->toThrow(SurgicalCaseException::class);

    // A completed case cannot rewind to pre_op.
    $fx2 = scFixture('surgcaseb');
    $svc->transition($fx2['surgeon'], $fx2['case'], SurgicalCase::STATUS_PRE_OP);
    $svc->transition($fx2['surgeon'], $fx2['case']->fresh(), SurgicalCase::STATUS_IN_PROGRESS);
    $svc->transition($fx2['surgeon'], $fx2['case']->fresh(), SurgicalCase::STATUS_COMPLETED);
    expect(fn () => $svc->transition($fx2['surgeon'], $fx2['case']->fresh(), SurgicalCase::STATUS_PRE_OP))
        ->toThrow(SurgicalCaseException::class);
});

test('the case event history is APPEND-ONLY (model guard + DB trigger)', function () {
    $fx = scFixture();
    app(SurgicalCaseService::class)->transition($fx['surgeon'], $fx['case'], SurgicalCase::STATUS_PRE_OP);
    $event = SurgicalCaseEvent::query()->firstOrFail();

    // Model guard (belt).
    expect(fn () => $event->update(['reason' => 'tampered']))->toThrow(SurgicalCaseException::class);

    // DB trigger (suspenders) — a raw update/delete bypassing the model still fails.
    expect(fn () => DB::table('surgical_case_events')->where('id', $event->id)->update(['reason' => 'tampered']))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('surgical_case_events')->where('id', $event->id)->delete())
        ->toThrow(QueryException::class);
});

test('op documentation REUSES the sign-and-lock ClinicalNote/Encounter tied to the case; Encounter stays UNMODIFIED', function () {
    $fx = scFixture();
    $svc = app(SurgicalCaseService::class);

    // Author an operative note — a real Clinical ClinicalNote on a TYPE_PROCEDURE encounter, linked case-side.
    $note = $svc->startNote($fx['surgeon'], $fx['case'], SurgicalCaseEncounter::PHASE_OPERATIVE);
    $encounter = Encounter::query()->findOrFail($note->encounter_id);

    expect($note)->toBeInstanceOf(ClinicalNote::class)
        ->and($encounter->type)->toBe(Encounter::TYPE_PROCEDURE)
        ->and(SurgicalCaseEncounter::query()->where('surgical_case_id', $fx['case']->id)->where('encounter_id', $encounter->id)->where('phase', 'operative')->count())->toBe(1);

    // A second note for the SAME case + surgeon does NOT collide (each note's encounter is opened + closed).
    $preOp = $svc->startNote($fx['surgeon'], $fx['case'], SurgicalCaseEncounter::PHASE_PRE_OP);
    expect($preOp->id)->not->toBe($note->id)
        ->and(SurgicalCaseEncounter::query()->where('surgical_case_id', $fx['case']->id)->count())->toBe(2);

    // The op note goes through the EXISTING sign-and-lock flow, unchanged: write sections -> sign -> immutable.
    app(ClinicalNoteService::class)->saveDraft($encounter, $fx['surgeonProfile'], ['assessment' => 'Appendix removed; no complications.'], $fx['surgeon'], $note, null);
    $signed = app(ClinicalNoteService::class)->sign($note->fresh(), $fx['surgeon']);
    expect($signed->status)->toBe(ClinicalNote::STATUS_SIGNED)
        ->and(fn () => $signed->fresh()->update(['assessment' => 'tampered']))->toThrow(LogicException::class);

    // ENCOUNTER UNMODIFIED: no surgical_case_id/stay_id was added to its schema (the association is Surgery-side).
    $cols = Schema::getColumnListing('encounters');
    expect($cols)->not->toContain('surgical_case_id')->and($cols)->not->toContain('stay_id');

    // The one-open-per-practitioner invariant is PRESERVED for other verticals: startNote left no lingering
    // open encounter, so a fresh encounter for the same patient + surgeon opens cleanly.
    scCtx()->set($fx['tenant']);
    $fresh = app(EncounterService::class)->open($fx['patient'], $fx['surgeonProfile'], $fx['branch'], null, Encounter::TYPE_CONSULTATION, $fx['surgeon'], 'follow-up');
    expect($fresh->status)->toBe(Encounter::STATUS_OPEN);
});

test('the surgical team is recorded on the case (surgeon / anesthetist / scrub nurse)', function () {
    $fx = scFixture();
    $svc = app(SurgicalCaseService::class);

    $member = $svc->addTeamMember($fx['surgeon'], $fx['case'], $fx['anesthetistProfile'], SurgicalCaseTeamMember::ROLE_ANESTHETIST);
    expect($member->team_role)->toBe('anesthetist')
        ->and($fx['case']->fresh()->teamMembers()->count())->toBe(1);

    // Re-adding the same person updates their role (idempotent on the unique key).
    $svc->addTeamMember($fx['surgeon'], $fx['case'], $fx['anesthetistProfile'], SurgicalCaseTeamMember::ROLE_SCRUB_NURSE);
    expect($fx['case']->fresh()->teamMembers()->count())->toBe(1)
        ->and(SurgicalCaseTeamMember::query()->where('staff_profile_id', $fx['anesthetistProfile']->id)->firstOrFail()->team_role)->toBe('scrub_nurse');

    // An unknown role is rejected.
    expect(fn () => $svc->addTeamMember($fx['surgeon'], $fx['case'], $fx['surgeonProfile'], 'captain'))
        ->toThrow(SurgicalCaseException::class);
});

test('ASA + Mallampati are anesthetist-ASSIGNED recorded facts, never computed', function () {
    $fx = scFixture();
    $svc = app(SurgicalCaseService::class);

    $case = $svc->recordAnesthesiaAssessment($fx['anesthetist'], $fx['case'], 'III', 'II', $fx['anesthetistProfile']);

    expect($case->asa_class)->toBe('III')          // the value the anesthetist ASSIGNED
        ->and($case->mallampati)->toBe('II')
        ->and($case->asa_assessed_by)->toBe($fx['anesthetistProfile']->id) // provenance
        ->and($case->asa_assessed_at)->not->toBeNull();

    // Only the closed sets are accepted — CareOS records an ASSIGNED class, it does not invent one.
    expect(fn () => $svc->recordAnesthesiaAssessment($fx['anesthetist'], $fx['case'], 'VII', null, $fx['anesthetistProfile']))
        ->toThrow(SurgicalCaseException::class);
    expect(fn () => $svc->recordAnesthesiaAssessment($fx['anesthetist'], $fx['case'], 'II', 'V', $fx['anesthetistProfile']))
        ->toThrow(SurgicalCaseException::class);
});

test('FENCE: no computed surgical-risk/prediction/score column; nothing auto-computes a risk', function () {
    // The case + its events are operational records. The ASA/Mallampati are ASSIGNED classes (allowed); what
    // must NEVER exist is a COMPUTED risk: a risk/score/prediction/acuity/severity/triage/grade column.
    $forbidden = ['risk', 'score', 'prediction', 'predicted', 'acuity', 'severity', 'triage', 'grade', 'rating'];
    foreach (['surgical_cases', 'surgical_case_events', 'surgical_case_team_members'] as $table) {
        $columns = Schema::getColumnListing($table);
        foreach ($forbidden as $word) {
            expect($columns)->not->toContain($word, "{$table} must not carry a computed-judgment column: {$word}");
        }
    }

    // Scheduling a case computes/stores no risk — a fresh case has only the recorded fields, ASA null.
    $fx = scFixture();
    expect($fx['case']->asa_class)->toBeNull();

    // The Pharmacy-style discipline extended to Surgery: the module manufactures no clinical verdict — there
    // is no "computeRisk"/"predict"/"riskScore" method anywhere in Modules\Surgery\src.
    $files = collect(File::allFiles(base_path('Modules/Surgery/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['computeRisk', 'riskScore', 'predictRisk', 'surgicalRisk('] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Surgery must not compute a surgical risk ({$needle})");
        }
    }
});

test('the anesthesia device-data feed is DEFERRED (partner-gated), not built this gate', function () {
    // Anesthesia DOCUMENTATION is buildable (the ASA record above); the intra-op DEVICE-DATA feed (anesthesia
    // machine / patient monitor) is partner-gated — noted in the map, NOT built. No device-integration code
    // exists in the module yet (no HL7 / device-feed / machine ingestion).
    $files = collect(File::allFiles(base_path('Modules/Surgery/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['DeviceFeed', 'AnesthesiaMachine', 'MonitorFeed', 'ingestDevice', 'hl7', 'Hl7'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("The anesthesia device-data feed is partner-gated and must not be built ({$needle})");
        }
    }
});

test('RBAC: lifecycle/team/ASA are surgery.manage-gated and op notes need note.write; reception is refused', function () {
    $fx = scFixture();
    $svc = app(SurgicalCaseService::class);

    // reception holds neither surgery.manage nor note.write / encounter.manage — refused at the gate.
    expect(fn () => $svc->transition($fx['reception'], $fx['case'], SurgicalCase::STATUS_PRE_OP))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->addTeamMember($fx['reception'], $fx['case'], $fx['anesthetistProfile'], 'anesthetist'))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->recordAnesthesiaAssessment($fx['reception'], $fx['case'], 'II', null, $fx['anesthetistProfile']))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->startNote($fx['reception'], $fx['case'], SurgicalCaseEncounter::PHASE_OPERATIVE))->toThrow(AuthorizationException::class);

    // The surgeon carries every required gate.
    expect($svc->transition($fx['surgeon'], $fx['case'], SurgicalCase::STATUS_PRE_OP)->status)->toBe(SurgicalCase::STATUS_PRE_OP);
});

test('cross-tenant is fail-closed: a tenant cannot advance another tenant surgical case', function () {
    $fxA = scFixture('alpha');
    $caseA = $fxA['case'];

    $fxB = scFixture('beta');
    scCtx()->set($fxB['tenant']);

    expect(fn () => app(SurgicalCaseService::class)->transition($fxB['surgeon'], $caseA, SurgicalCase::STATUS_PRE_OP))
        ->toThrow(CrossTenantReferenceException::class);
});
