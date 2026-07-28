<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Audit\Models\AuditEvent;
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
use Modules\Surgery\Exceptions\TheatreException;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\Theatre;
use Modules\Surgery\Models\TheatreSlot;
use Modules\Surgery\Services\SurgicalCaseService;
use Modules\Surgery\Services\TheatreSchedulingService;

uses(RefreshDatabase::class);

/*
 * SURGERY.G1 — the OR/surgery foundation: theatre + theatre-scheduling (a NET-NEW TheatreSlot that REUSES the
 * booking/bed overlap-lock INVARIANT, NOT the day-board model) + the NET-NEW surgical Case + OR RBAC. Per
 * docs/HOSPITAL-PHASE5-SURGERY-MAP.md. Operational/scheduling only — record-not-judge (no computed risk).
 */

function surgCtx(): TenantContext
{
    return app(TenantContext::class);
}

function surgUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, branch: Branch, scheduler: User, surgeon: User, reception: User, surgeonProfile: StaffProfile, patient: Patient} */
function surgFixture(string $slug = 'surgtenant'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Surgery', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    surgCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $scheduler = surgUser($tenant, 'surgical_scheduler'); // theatre.manage + surgery.schedule
    $surgeon = surgUser($tenant, 'surgeon');              // surgery.manage + surgery.schedule
    $reception = surgUser($tenant, 'reception');          // NO surgery permissions
    $surgeonProfile = StaffProfile::query()->create([
        'first_name' => 'Sara', 'last_name' => 'Sharp', 'display_name' => 'Dr Sara Sharp',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Percy', 'last_name' => 'Patient', 'date_of_birth' => '1972-02-02', 'sex' => 'male']);

    return compact('tenant', 'branch', 'scheduler', 'surgeon', 'reception', 'surgeonProfile', 'patient');
}

test('a theatre is authored, tenant + branch scoped, and audited', function () {
    $fx = surgFixture();

    $theatre = app(TheatreSchedulingService::class)->createTheatre($fx['scheduler'], $fx['branch']->id, 'OR-1', 'general');

    expect($theatre->tenant_id)->toBe($fx['tenant']->id)
        ->and($theatre->branch_id)->toBe($fx['branch']->id)
        ->and($theatre->name)->toBe('OR-1')
        ->and($theatre->type)->toBe('general')
        ->and($theatre->active)->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'theatre.created')->where('resource_id', $theatre->id)->count())->toBe(1);
});

test('a surgical block is booked in a theatre (bounded start+end) and audited', function () {
    $fx = surgFixture();
    $theatre = app(TheatreSchedulingService::class)->createTheatre($fx['scheduler'], $fx['branch']->id, 'OR-1');

    $start = Carbon::parse('2026-08-15 09:00:00');
    $slot = app(TheatreSchedulingService::class)->bookSlot($fx['scheduler'], $theatre, $start, 120);

    expect($slot->theatre_id)->toBe($theatre->id)
        ->and($slot->status)->toBe(TheatreSlot::STATUS_BOOKED)
        ->and($slot->starts_at->equalTo($start))->toBeTrue()
        ->and($slot->ends_at->equalTo($start->copy()->addMinutes(120)))->toBeTrue() // a BOUNDED planned block
        ->and(AuditEvent::query()->where('action', 'theatre_slot.booked')->where('resource_id', $slot->id)->count())->toBe(1);
});

test('THE OVERLAP-LOCK INVARIANT: two surgeries cannot overlap in one theatre; non-overlapping / other theatres are free', function () {
    $fx = surgFixture();
    $scheduling = app(TheatreSchedulingService::class);
    $or1 = $scheduling->createTheatre($fx['scheduler'], $fx['branch']->id, 'OR-1');
    $or2 = $scheduling->createTheatre($fx['scheduler'], $fx['branch']->id, 'OR-2');

    // 09:00–11:00 in OR-1.
    $scheduling->bookSlot($fx['scheduler'], $or1, Carbon::parse('2026-08-15 09:00:00'), 120);

    // 10:00–12:00 in OR-1 OVERLAPS → refused.
    expect(fn () => $scheduling->bookSlot($fx['scheduler'], $or1, Carbon::parse('2026-08-15 10:00:00'), 120))
        ->toThrow(TheatreException::class);

    // 11:00–12:00 in OR-1 is ADJACENT (no overlap) → allowed.
    $adjacent = $scheduling->bookSlot($fx['scheduler'], $or1, Carbon::parse('2026-08-15 11:00:00'), 60);
    // The SAME 10:00 block in OR-2 (a different theatre) → allowed.
    $other = $scheduling->bookSlot($fx['scheduler'], $or2, Carbon::parse('2026-08-15 10:00:00'), 120);

    expect($adjacent->id)->not->toBeNull()
        ->and($other->id)->not->toBeNull()
        ->and(TheatreSlot::query()->where('theatre_id', $or1->id)->count())->toBe(2)
        ->and(TheatreSlot::query()->where('theatre_id', $or2->id)->count())->toBe(1);
});

test('a surgical case is scheduled, tied to patient (+ soft stay for inpatient), audited and read-logged', function () {
    $fx = surgFixture();
    $stayId = (string) Str::ulid(); // a soft inpatient stay ref (no FK)

    $case = app(SurgicalCaseService::class)->schedule(
        $fx['surgeon'], $fx['patient'], $fx['surgeonProfile'], 'Laparoscopic appendectomy',
        Carbon::parse('2026-08-15 09:00:00'), $stayId,
    );

    expect($case->patient_id)->toBe($fx['patient']->id)
        ->and($case->primary_surgeon_id)->toBe($fx['surgeonProfile']->id)
        ->and($case->stay_id)->toBe($stayId)
        ->and($case->status)->toBe(SurgicalCase::STATUS_SCHEDULED)
        ->and($case->procedure_description)->toBe('Laparoscopic appendectomy')
        ->and(AuditEvent::query()->where('action', 'surgical_case.scheduled')->where('resource_id', $case->id)->where('patient_id', $fx['patient']->id)->count())->toBe(1);

    // Day-surgery (outpatient) — the stay link is optional.
    $dayCase = app(SurgicalCaseService::class)->schedule(
        $fx['surgeon'], $fx['patient'], $fx['surgeonProfile'], 'Carpal tunnel release',
        Carbon::parse('2026-08-16 09:00:00'),
    );
    expect($dayCase->stay_id)->toBeNull();

    // Read-logged (LogsReads) — reading the case writes a patient-scoped `read` audit row.
    $case->auditRead();
    expect(AuditEvent::query()->where('resource_id', $case->id)->where('action', 'read')->count())->toBeGreaterThanOrEqual(1);
});

test('OR RBAC gates theatre.manage / surgery.schedule / surgery.manage; an unauthorized role is refused', function () {
    $fx = surgFixture();
    $scheduling = app(TheatreSchedulingService::class);
    $cases = app(SurgicalCaseService::class);
    $theatre = $scheduling->createTheatre($fx['scheduler'], $fx['branch']->id, 'OR-1');

    // reception holds none of the surgery permissions → refused at the gate (fail closed).
    expect(fn () => $scheduling->createTheatre($fx['reception'], $fx['branch']->id, 'OR-X'))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $scheduling->bookSlot($fx['reception'], $theatre, Carbon::parse('2026-08-15 09:00:00'), 60))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $cases->schedule($fx['reception'], $fx['patient'], $fx['surgeonProfile'], 'X', Carbon::parse('2026-08-15 09:00:00')))
        ->toThrow(AuthorizationException::class);

    // The proper roles succeed: the scheduler books; the surgeon creates the case.
    expect($scheduling->bookSlot($fx['scheduler'], $theatre, Carbon::parse('2026-08-15 09:00:00'), 60)->id)->not->toBeNull()
        ->and($cases->schedule($fx['surgeon'], $fx['patient'], $fx['surgeonProfile'], 'Appendectomy', Carbon::parse('2026-08-15 12:00:00'))->id)->not->toBeNull();
});

test('cross-tenant is fail-closed: a tenant cannot book in another tenant theatre or schedule another tenant patient', function () {
    $fxA = surgFixture('alpha');
    $theatreA = app(TheatreSchedulingService::class)->createTheatre($fxA['scheduler'], $fxA['branch']->id, 'OR-A');
    $patientA = $fxA['patient'];

    $fxB = surgFixture('beta');
    surgCtx()->set($fxB['tenant']);

    // Tenant B's scheduler cannot book a block in tenant A's theatre.
    expect(fn () => app(TheatreSchedulingService::class)->bookSlot($fxB['scheduler'], $theatreA, Carbon::parse('2026-08-15 09:00:00'), 60))
        ->toThrow(CrossTenantReferenceException::class);

    // Tenant B's surgeon cannot schedule a case for tenant A's patient (invisible under B's scope).
    expect(fn () => app(SurgicalCaseService::class)->schedule($fxB['surgeon'], $patientA, $fxB['surgeonProfile'], 'X', Carbon::parse('2026-08-15 09:00:00')))
        ->toThrow(CrossTenantReferenceException::class);
});

test('FENCE: theatre / slot / case are operational records with NO computed judgment column', function () {
    // A surgical-risk score is the fence line (map §3) — never computed, never stored. Assert the schema
    // carries no acuity/priority/risk/severity/triage/score/urgency/grade column on any of the three tables.
    $forbidden = ['acuity', 'priority', 'risk', 'severity', 'triage', 'score', 'urgency', 'grade', 'rating'];

    foreach (['theatres', 'theatre_slots', 'surgical_cases'] as $table) {
        $columns = Schema::getColumnListing($table);
        foreach ($forbidden as $word) {
            expect($columns)->not->toContain($word, "{$table} must not carry a computed-judgment column: {$word}");
        }
    }
});
