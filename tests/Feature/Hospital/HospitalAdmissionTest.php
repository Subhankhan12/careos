<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Clinical\Models\Encounter;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Hospital\Exceptions\BedNotAvailableException;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\StayEvent;
use Modules\Hospital\Models\Ward;
use Modules\Hospital\Services\AdmissionService;
use Modules\Hospital\Services\BedService;
use Modules\Hospital\Services\WardService;
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
 * HOSPITAL.G2 — the ADT core. A Stay is a NET-NEW entity above an UNMODIFIED Encounter;
 * admit/transfer/discharge are atomic (stay change + bed claim/release via G1's proven
 * concurrency-safe BedService + append-only event, in one transaction) and a legal-only
 * state machine. These tests assert BEHAVIOUR (DB state, audit rows, gate refusals) — no markup.
 */

function haTenant(string $slug = 'hosp'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Hospital', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

function haUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

function haPatient(string $first = 'Ivy'): Patient
{
    return app(PatientService::class)->create(['first_name' => $first, 'last_name' => 'Inpatient', 'date_of_birth' => '1975-03-03', 'sex' => 'female']);
}

/** @return array{tenant: Tenant, branch: Branch, ward: Ward, bedA: Bed, bedB: Bed, clinician: StaffProfile, patient: Patient, clerk: User, nurse: User, manager: User} */
function haFixture(string $slug = 'hosp'): array
{
    $tenant = haTenant($slug);
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $clerk = haUser($tenant, 'admissions_clerk'); // holds admission.manage
    $nurse = haUser($tenant, 'ward_nurse');       // clinical only — no admission.manage
    $manager = haUser($tenant, 'bed_manager');    // bed.manage / ward.manage — builds the ward + beds
    $ward = app(WardService::class)->create($manager, $branch->id, 'Ward 1', 'W1');
    $bedA = app(BedService::class)->create($manager, $ward, '1', Bed::TYPE_GENERAL);
    $bedB = app(BedService::class)->create($manager, $ward, '2', Bed::TYPE_ICU);
    $clinician = StaffProfile::query()->create([
        'first_name' => 'Hana', 'last_name' => 'Hospitalist', 'display_name' => 'Dr Hana Hospitalist',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id,
    ]);
    $patient = haPatient();

    return compact('tenant', 'branch', 'ward', 'bedA', 'bedB', 'clinician', 'patient', 'clerk', 'nurse', 'manager');
}

test('admit creates a stay, claims the bed atomically, appends the admission event, and audits it', function () {
    $fx = haFixture();

    $stay = app(AdmissionService::class)->admit($fx['clerk'], $fx['patient'], $fx['bedA'], $fx['clinician'], Stay::TYPE_EMERGENCY, 'chest pain');

    expect($stay->status)->toBe(Stay::STATUS_ADMITTED)
        ->and($stay->current_bed_id)->toBe($fx['bedA']->id)
        ->and($stay->current_ward_id)->toBe($fx['ward']->id)
        ->and($fx['bedA']->fresh()->status)->toBe(Bed::STATUS_OCCUPIED); // the bed was claimed

    expect(StayEvent::query()->where('stay_id', $stay->id)->where('event_type', 'admitted')->count())->toBe(1);

    // Audited: the ADT transition AND the bed claim, chain intact.
    expect(AuditEvent::query()->where('action', 'admission.admitted')->where('resource_id', $stay->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'bed.status_changed')->where('resource_id', $fx['bedA']->id)->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();

    // G2 is ADT only: no charge (billing is G6) and no encounter (charting is G4).
    expect(Charge::query()->count())->toBe(0)
        ->and(Encounter::query()->count())->toBe(0);
});

test('transfer claims the new bed, releases the old to cleaning, moves the stay, and appends a transfer event', function () {
    $fx = haFixture();
    $stay = app(AdmissionService::class)->admit($fx['clerk'], $fx['patient'], $fx['bedA'], $fx['clinician'], Stay::TYPE_ELECTIVE);

    app(AdmissionService::class)->transfer($fx['clerk'], $stay, $fx['bedB'], 'needs ICU');

    expect($stay->fresh()->current_bed_id)->toBe($fx['bedB']->id)
        ->and($stay->fresh()->current_ward_id)->toBe($fx['ward']->id)
        ->and($stay->fresh()->status)->toBe(Stay::STATUS_ADMITTED) // a transfer keeps the stay admitted
        ->and($fx['bedB']->fresh()->status)->toBe(Bed::STATUS_OCCUPIED) // new bed claimed
        ->and($fx['bedA']->fresh()->status)->toBe(Bed::STATUS_CLEANING); // old bed released

    expect(StayEvent::query()->where('stay_id', $stay->id)->where('event_type', 'transferred')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'admission.transferred')->count())->toBe(1)
        // append-only history: the admission event is preserved — the full bed journey stands.
        ->and(StayEvent::query()->where('stay_id', $stay->id)->count())->toBe(2);
});

test('discharge sets the disposition, releases the bed to cleaning, discharges the stay, and appends the event', function () {
    $fx = haFixture();
    $stay = app(AdmissionService::class)->admit($fx['clerk'], $fx['patient'], $fx['bedA'], $fx['clinician'], Stay::TYPE_EMERGENCY);

    app(AdmissionService::class)->discharge($fx['clerk'], $stay, Stay::DISPOSITION_HOME, 'stable for home');

    expect($stay->fresh()->status)->toBe(Stay::STATUS_DISCHARGED)
        ->and($stay->fresh()->discharge_disposition)->toBe('home')
        ->and($stay->fresh()->discharged_at)->not->toBeNull()
        ->and($fx['bedA']->fresh()->status)->toBe(Bed::STATUS_CLEANING); // bed released

    expect(StayEvent::query()->where('stay_id', $stay->id)->where('event_type', 'discharged')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'admission.discharged')->count())->toBe(1)
        ->and(Charge::query()->count())->toBe(0); // no charge posted (billing is G6)
});

test('the ADT state machine is legal-only: a discharged stay cannot be transferred or discharged again', function () {
    $fx = haFixture();
    $stay = app(AdmissionService::class)->admit($fx['clerk'], $fx['patient'], $fx['bedA'], $fx['clinician'], Stay::TYPE_ELECTIVE);
    app(AdmissionService::class)->discharge($fx['clerk'], $stay, Stay::DISPOSITION_HOME);

    expect(fn () => app(AdmissionService::class)->discharge($fx['clerk'], $stay->fresh(), Stay::DISPOSITION_HOME))
        ->toThrow(AdmissionException::class);
    expect(fn () => app(AdmissionService::class)->transfer($fx['clerk'], $stay->fresh(), $fx['bedB']))
        ->toThrow(AdmissionException::class);

    // an unknown disposition is rejected
    $stay2 = app(AdmissionService::class)->admit($fx['clerk'], haPatient('Cy'), $fx['bedB'], $fx['clinician'], Stay::TYPE_EMERGENCY);
    expect(fn () => app(AdmissionService::class)->discharge($fx['clerk'], $stay2, 'teleported'))
        ->toThrow(AdmissionException::class);
});

test('a patient cannot have two concurrent active admissions (one-active-stay guard)', function () {
    $fx = haFixture();
    $stay = app(AdmissionService::class)->admit($fx['clerk'], $fx['patient'], $fx['bedA'], $fx['clinician'], Stay::TYPE_EMERGENCY);

    // a second admission of the SAME patient is refused — before any bed is claimed.
    expect(fn () => app(AdmissionService::class)->admit($fx['clerk'], $fx['patient'], $fx['bedB'], $fx['clinician'], Stay::TYPE_ELECTIVE))
        ->toThrow(AdmissionException::class);
    expect($fx['bedB']->fresh()->status)->toBe(Bed::STATUS_FREE)
        ->and(Stay::query()->where('patient_id', $fx['patient']->id)->count())->toBe(1);

    // after discharge the patient can be re-admitted.
    app(AdmissionService::class)->discharge($fx['clerk'], $stay, Stay::DISPOSITION_HOME);
    $stay2 = app(AdmissionService::class)->admit($fx['clerk'], $fx['patient'], $fx['bedB'], $fx['clinician'], Stay::TYPE_ELECTIVE);
    expect($stay2->status)->toBe(Stay::STATUS_ADMITTED);
});

test('atomicity: admitting to an occupied bed fails and leaves no orphan stay', function () {
    $fx = haFixture();
    app(AdmissionService::class)->admit($fx['clerk'], $fx['patient'], $fx['bedA'], $fx['clinician'], Stay::TYPE_EMERGENCY);

    $other = haPatient('Ben');
    expect(fn () => app(AdmissionService::class)->admit($fx['clerk'], $other, $fx['bedA'], $fx['clinician'], Stay::TYPE_EMERGENCY))
        ->toThrow(BedNotAvailableException::class);

    expect(Stay::query()->where('patient_id', $other->id)->count())->toBe(0)
        ->and($fx['bedA']->fresh()->status)->toBe(Bed::STATUS_OCCUPIED)
        ->and(Stay::query()->count())->toBe(1);
});

test('atomicity: a forced failure after the bed is claimed rolls the claim back (no stuck bed, no orphan stay, no phantom audit)', function () {
    $fx = haFixture();

    // The admission type is validated at Stay creation — AFTER the bed is claimed inside the same
    // transaction — so a forced-invalid type must roll the claim (and its audit) all the way back.
    expect(fn () => app(AdmissionService::class)->admit($fx['clerk'], $fx['patient'], $fx['bedA'], $fx['clinician'], 'not-a-real-type'))
        ->toThrow(AdmissionException::class);

    expect($fx['bedA']->fresh()->status)->toBe(Bed::STATUS_FREE) // the claim was rolled back
        ->and(Stay::query()->count())->toBe(0)
        ->and(StayEvent::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'bed.status_changed')->count())->toBe(0); // audit rolled back too
});

test('atomicity: transferring to an occupied bed does not release the old bed', function () {
    $fx = haFixture();
    $stayA = app(AdmissionService::class)->admit($fx['clerk'], $fx['patient'], $fx['bedA'], $fx['clinician'], Stay::TYPE_ELECTIVE);
    app(AdmissionService::class)->admit($fx['clerk'], haPatient('Bob'), $fx['bedB'], $fx['clinician'], Stay::TYPE_ELECTIVE); // B occupies bedB

    expect(fn () => app(AdmissionService::class)->transfer($fx['clerk'], $stayA, $fx['bedB']))
        ->toThrow(BedNotAvailableException::class);

    // A is still in bedA (occupied, NOT released to cleaning); no transfer event was written.
    expect($stayA->fresh()->current_bed_id)->toBe($fx['bedA']->id)
        ->and($fx['bedA']->fresh()->status)->toBe(Bed::STATUS_OCCUPIED)
        ->and(StayEvent::query()->where('stay_id', $stayA->id)->where('event_type', 'transferred')->count())->toBe(0);
});

test('ADT actions are gated on admission.manage (server Gate authoritative)', function () {
    $fx = haFixture();

    // a ward nurse (no admission.manage) cannot admit
    expect(fn () => app(AdmissionService::class)->admit($fx['nurse'], $fx['patient'], $fx['bedA'], $fx['clinician'], Stay::TYPE_EMERGENCY))
        ->toThrow(AuthorizationException::class);

    $stay = app(AdmissionService::class)->admit($fx['clerk'], $fx['patient'], $fx['bedA'], $fx['clinician'], Stay::TYPE_EMERGENCY);

    // ...nor transfer nor discharge
    expect(fn () => app(AdmissionService::class)->transfer($fx['nurse'], $stay, $fx['bedB']))->toThrow(AuthorizationException::class);
    expect(fn () => app(AdmissionService::class)->discharge($fx['nurse'], $stay, Stay::DISPOSITION_HOME))->toThrow(AuthorizationException::class);
});

test('stays are tenant isolated and reject cross-tenant references (fail-closed)', function () {
    $fxA = haFixture('alpha');
    $stayA = app(AdmissionService::class)->admit($fxA['clerk'], $fxA['patient'], $fxA['bedA'], $fxA['clinician'], Stay::TYPE_ELECTIVE);

    // switch to tenant B — tenant A's stay is invisible.
    $fxB = haFixture('beta');
    app(TenantContext::class)->set($fxB['tenant']);
    expect(Stay::find($stayA->id))->toBeNull();

    // admitting a tenant-B patient into a tenant-A bed is rejected (the bed is invisible → claim throws).
    expect(fn () => app(AdmissionService::class)->admit($fxB['clerk'], $fxB['patient'], $fxA['bedA'], $fxB['clinician'], Stay::TYPE_ELECTIVE))
        ->toThrow(CrossTenantReferenceException::class);
});

test('stay events are append-only (immutable at the model and DB-trigger level; history preserved)', function () {
    $fx = haFixture();
    $stay = app(AdmissionService::class)->admit($fx['clerk'], $fx['patient'], $fx['bedA'], $fx['clinician'], Stay::TYPE_EMERGENCY);
    $event = StayEvent::query()->where('stay_id', $stay->id)->firstOrFail();

    expect(fn () => $event->update(['reason' => 'edited']))->toThrow(AdmissionException::class);          // model guard
    expect(fn () => DB::table('stay_events')->where('id', $event->id)->delete())->toThrow(QueryException::class); // DB trigger

    // a transfer preserves the admission event — the bed journey is never rewritten.
    app(AdmissionService::class)->transfer($fx['clerk'], $stay, $fx['bedB']);
    expect(StayEvent::query()->where('stay_id', $stay->id)->count())->toBe(2);
});

test('FENCE: stays/stay_events carry no acuity/severity/triage column — ADT is operational', function () {
    haTenant();

    $stayCols = Schema::getColumnListing('stays');
    $eventCols = Schema::getColumnListing('stay_events');

    // No computed clinical judgment: acuity/severity/score/risk/grade/triage/early-warning/flag.
    $forbidden = ['acuity', 'severity', 'score', 'risk', 'grade', 'triage', 'deterioration', 'ews', 'news', 'flag', 'priority', 'abnormal'];
    foreach ($forbidden as $word) {
        expect($stayCols)->not->toContain($word, "stays must not carry a clinical-judgment column: {$word}")
            ->and($eventCols)->not->toContain($word, "stay_events must not carry a clinical-judgment column: {$word}");
    }

    // admission_type + disposition ARE present — human-recorded operational facts, a closed set.
    expect($stayCols)->toContain('admission_type')->toContain('discharge_disposition')
        ->and(Stay::ADMISSION_TYPES)->toBe(['elective', 'emergency', 'transfer']);
});
