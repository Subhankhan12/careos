<?php

use App\Services\EdDispositionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\ED\Exceptions\EdVisitException;
use Modules\ED\Models\EdVisit;
use Modules\ED\Services\EdVisitService;
use Modules\Hospital\Exceptions\BedNotAvailableException;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\Ward;
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
 * ED.G5 — disposition + the ED→ADT handoff: closing an ED visit (admit / discharge / transfer). ADMIT reuses
 * the Phase-1 AdmissionService (concurrency-safe, atomic) to create an inpatient Stay (admission_type=emergency).
 * Per docs/HOSPITAL-PHASE6-ED-MAP.md §2.3. FENCE: the disposition is the clinician's recorded DECISION, never
 * computed/suggested.
 */

function edspCtx(): TenantContext
{
    return app(TenantContext::class);
}

function edspUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** Register + flow a visit to awaiting_disposition (arrived → triaged → in_treatment → awaiting_disposition). */
function edspAwaitingVisit(User $actor, Patient $patient, Branch $branch, string $complaint = 'Chest pain'): EdVisit
{
    $visits = app(EdVisitService::class);
    $visit = $visits->register($actor, $patient, $branch, EdVisit::ARRIVAL_AMBULANCE, $complaint);
    foreach ([EdVisit::STATUS_TRIAGED, EdVisit::STATUS_IN_TREATMENT, EdVisit::STATUS_AWAITING_DISPOSITION] as $to) {
        $visits->transition($actor, $visit, $to);
        $visit = $visit->fresh();
    }

    return $visit;
}

/** @return array{tenant: Tenant, branch: Branch, physician: User, edNurse: User, reception: User, clinician: StaffProfile, ward: Ward, bed: Bed, patient: Patient} */
function edspFixture(string $slug = 'edsp'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' ED', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    edspCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $physician = edspUser($tenant, 'ed_physician');  // ed.manage + admission.manage
    $edNurse = edspUser($tenant, 'triage_nurse');    // ed.manage, NO admission.manage
    $reception = edspUser($tenant, 'reception');      // no ed.manage
    $clinician = StaffProfile::query()->create([
        'first_name' => 'Ravi', 'last_name' => 'Rao', 'display_name' => 'Dr Ravi Rao',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    // Ward + a free bed (direct create under the tenant context — the admit target).
    $ward = Ward::query()->create(['branch_id' => $branch->id, 'name' => 'Acute Ward', 'code' => 'AW']);
    $bed = Bed::query()->create(['branch_id' => $branch->id, 'ward_id' => $ward->id, 'label' => 'A1', 'bed_type' => Bed::TYPE_GENERAL]);
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);

    return compact('tenant', 'branch', 'physician', 'edNurse', 'reception', 'clinician', 'ward', 'bed', 'patient');
}

test('DISCHARGE / TRANSFER close the visit with the recorded disposition — no Stay, audited', function () {
    $fx = edspFixture();
    $visit = edspAwaitingVisit($fx['physician'], $fx['patient'], $fx['branch']);

    $out = app(EdDispositionService::class)->discharge($fx['physician'], $visit, 'Home, GP follow-up');

    expect($out->status)->toBe(EdVisit::STATUS_DISPOSITIONED)
        ->and($out->disposition)->toBe('discharge')       // the clinician's recorded decision
        ->and($out->stay_id)->toBeNull()                  // NO inpatient Stay for a discharge
        ->and(Stay::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'ed_visit.dispositioned')->where('patient_id', $fx['patient']->id)->count())->toBe(1);

    // Transfer-out (a second patient) — a recorded disposition, no Stay.
    $p2 = app(PatientService::class)->create(['first_name' => 'Bo', 'last_name' => 'Ben', 'date_of_birth' => '1980-01-01', 'sex' => 'male']);
    $v2 = edspAwaitingVisit($fx['physician'], $p2, $fx['branch']);
    $out2 = app(EdDispositionService::class)->transferOut($fx['physician'], $v2);
    expect($out2->disposition)->toBe('transfer')->and(Stay::query()->count())->toBe(0);
});

test('ADMIT creates an inpatient Stay via the EXISTING AdmissionService (emergency), claims the bed, links the visit', function () {
    $fx = edspFixture();
    $visit = edspAwaitingVisit($fx['physician'], $fx['patient'], $fx['branch']);

    $result = app(EdDispositionService::class)->admit($fx['physician'], $visit, $fx['bed'], $fx['clinician'], 'Admit for observation');

    $stay = $result['stay'];
    expect($stay)->toBeInstanceOf(Stay::class)
        ->and($stay->admission_type)->toBe(Stay::TYPE_EMERGENCY)     // the map's emergency admission
        ->and($stay->current_bed_id)->toBe($fx['bed']->id)
        ->and($stay->current_ward_id)->toBe($fx['ward']->id)         // ward derived from the bed (reused admit)
        ->and($stay->status)->toBe(Stay::STATUS_ADMITTED)
        // The bed was CLAIMED via the proven concurrency-safe path (free → occupied).
        ->and($fx['bed']->fresh()->status)->toBe(Bed::STATUS_OCCUPIED)
        // The ED visit is dispositioned:admit and LINKED to the resulting Stay (the episode is traceable).
        ->and($result['visit']->status)->toBe(EdVisit::STATUS_DISPOSITIONED)
        ->and($result['visit']->disposition)->toBe('admit')
        ->and($result['visit']->stay_id)->toBe($stay->id);
});

test('the handoff is ATOMIC: a forced failure rolls back BOTH the Stay and the disposition (no orphan)', function () {
    $fx = edspFixture();
    // A visit still ARRIVED (NOT awaiting_disposition) — admitting it will create the Stay then FAIL at the
    // illegal disposition transition (arrived → dispositioned), rolling the whole transaction back.
    $visit = app(EdVisitService::class)->register($fx['physician'], $fx['patient'], $fx['branch'], EdVisit::ARRIVAL_WALK_IN, 'Cough');

    expect(fn () => app(EdDispositionService::class)->admit($fx['physician'], $visit, $fx['bed'], $fx['clinician']))
        ->toThrow(EdVisitException::class);

    // NOTHING persisted: no Stay, the bed is still free, the visit is unchanged (still arrived, no stay_id).
    expect(Stay::query()->count())->toBe(0)
        ->and($fx['bed']->fresh()->status)->toBe(Bed::STATUS_FREE)
        ->and($visit->fresh()->status)->toBe(EdVisit::STATUS_ARRIVED)
        ->and($visit->fresh()->stay_id)->toBeNull();
});

test('ADMIT requires admission.manage: an ed.manage-only user cannot admit (and creates no Stay)', function () {
    $fx = edspFixture();
    $visit = edspAwaitingVisit($fx['physician'], $fx['patient'], $fx['branch']);

    // The triage nurse holds ed.manage but NOT admission.manage — the ADMIT path is refused before any write.
    expect(fn () => app(EdDispositionService::class)->admit($fx['edNurse'], $visit, $fx['bed'], $fx['clinician']))
        ->toThrow(AuthorizationException::class);

    expect(Stay::query()->count())->toBe(0)
        ->and($fx['bed']->fresh()->status)->toBe(Bed::STATUS_FREE)
        ->and($visit->fresh()->status)->toBe(EdVisit::STATUS_AWAITING_DISPOSITION); // untouched
});

test('the reused bed-safety holds: cannot admit to an occupied bed (the G1/G2 guarantee, reused)', function () {
    $fx = edspFixture();
    $v1 = edspAwaitingVisit($fx['physician'], $fx['patient'], $fx['branch']);
    app(EdDispositionService::class)->admit($fx['physician'], $v1, $fx['bed'], $fx['clinician']); // bed now occupied

    // A second patient, awaiting disposition, admitted to the SAME (now occupied) bed → the reused
    // concurrency-safe bed claim refuses it; the whole handoff rolls back (no second Stay).
    $p2 = app(PatientService::class)->create(['first_name' => 'Bo', 'last_name' => 'Ben', 'date_of_birth' => '1980-01-01', 'sex' => 'male']);
    $v2 = edspAwaitingVisit($fx['physician'], $p2, $fx['branch']);

    expect(fn () => app(EdDispositionService::class)->admit($fx['physician'], $v2, $fx['bed'], $fx['clinician']))
        ->toThrow(BedNotAvailableException::class); // the losing side of the concurrency-safe bed claim (reused)

    expect(Stay::query()->count())->toBe(1)                                    // only the first admission
        ->and($v2->fresh()->status)->toBe(EdVisit::STATUS_AWAITING_DISPOSITION); // second visit unchanged
});

test('FENCE: the disposition is the clinician RECORDED decision; nothing computes/suggests/auto-decides it', function () {
    $fx = edspFixture();

    // A fresh awaiting_disposition visit has NO disposition — nothing auto-decides it.
    $visit = edspAwaitingVisit($fx['physician'], $fx['patient'], $fx['branch']);
    expect($visit->disposition)->toBeNull();

    // The recorded disposition is exactly what the clinician chose (not a computed/suggested value).
    $out = app(EdDispositionService::class)->discharge($fx['physician'], $visit);
    expect($out->disposition)->toBe('discharge');

    // No computed-judgment column on the visit — no admit-probability / discharge-risk / suggested disposition.
    $forbidden = ['admit_probability', 'discharge_risk', 'suggested_disposition', 'recommended_disposition', 'acuity_score', 'risk', 'severity'];
    $columns = Schema::getColumnListing('ed_visits');
    foreach ($forbidden as $word) {
        expect($columns)->not->toContain($word, "ed_visits must not carry a computed-judgment column: {$word}");
    }
});

test('disposition is legal-only + RBAC-gated + tenant scoped (fail-closed)', function () {
    $fx = edspFixture();

    // Legal-only: a visit NOT awaiting_disposition cannot be dispositioned (arrived → dispositioned is illegal).
    $arrived = app(EdVisitService::class)->register($fx['physician'], $fx['patient'], $fx['branch'], EdVisit::ARRIVAL_WALK_IN, 'Rash');
    expect(fn () => app(EdDispositionService::class)->discharge($fx['physician'], $arrived))->toThrow(EdVisitException::class);

    // RBAC: reception (no ed.manage) cannot dispose.
    $ready = edspAwaitingVisit($fx['physician'], app(PatientService::class)->create(['first_name' => 'Cy', 'last_name' => 'Cee', 'date_of_birth' => '1975-05-05', 'sex' => 'male']), $fx['branch']);
    expect(fn () => app(EdDispositionService::class)->discharge($fx['reception'], $ready))->toThrow(AuthorizationException::class);

    // Tenant scoped: another tenant cannot dispose this visit.
    $fxB = edspFixture('edspb');
    edspCtx()->set($fxB['tenant']);
    expect(fn () => app(EdDispositionService::class)->discharge($fxB['physician'], $ready))
        ->toThrow(CrossTenantReferenceException::class);
});
