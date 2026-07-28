<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\ED\Contracts\TriageAcuityProvider;
use Modules\ED\Exceptions\EdVisitException;
use Modules\ED\Models\EdVisit;
use Modules\ED\Models\EdVisitEvent;
use Modules\ED\Services\EdVisitService;
use Modules\ED\Services\NullTriageAcuityProvider;
use Modules\ED\Support\AcuityContext;
use Modules\ED\Support\AcuityResult;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * ED.G1 — the Emergency Department FOUNDATION: the NET-NEW EdVisit patient-flow entity (NOT Encounter, NOT
 * Stay), its legal-only flow lifecycle + append-only flow events, ED RBAC, and the EMPTY triage-acuity SEAM
 * (a null-object; computed triage acuity is a certified-partner / medical-device non-goal). Per
 * docs/HOSPITAL-PHASE6-ED-MAP.md. FENCE: operational flow facts only; the nurse ASSIGNS acuity later (G2),
 * the system computes it never.
 */

function edCtx(): TenantContext
{
    return app(TenantContext::class);
}

function edUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, branch: Branch, physician: User, triageNurse: User, reception: User, patient: Patient} */
function edFixture(string $slug = 'eddept'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' ED', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    edCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $physician = edUser($tenant, 'ed_physician');   // ed.manage + admission.manage
    $triageNurse = edUser($tenant, 'triage_nurse'); // ed.manage + triage.record
    $reception = edUser($tenant, 'reception');      // no ed.manage
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);

    return compact('tenant', 'branch', 'physician', 'triageNurse', 'reception', 'patient');
}

test('an ED arrival is registered as a NET-NEW EdVisit — arrived state, recorded facts, audited + an arrival flow event', function () {
    $fx = edFixture();

    $visit = app(EdVisitService::class)->register(
        $fx['physician'], $fx['patient'], $fx['branch'],
        EdVisit::ARRIVAL_AMBULANCE, 'Chest pain', Carbon::parse('2026-08-06 09:00:00'),
    );

    expect($visit->status)->toBe(EdVisit::STATUS_ARRIVED)         // the initial flow state
        ->and($visit->arrival_mode)->toBe('ambulance')            // an operational route (recorded fact)
        ->and($visit->chief_complaint)->toBe('Chest pain')        // the presenting complaint recorded at arrival
        ->and($visit->branch_id)->toBe($fx['branch']->id)
        ->and($visit->patient_id)->toBe($fx['patient']->id)
        // The arrival is the first append-only flow event.
        ->and(EdVisitEvent::query()->where('ed_visit_id', $visit->id)->where('event_type', 'arrived')->count())->toBe(1)
        // Registration is audited (patient-scoped), so ED stays free of Audit.
        ->and(AuditEvent::query()->where('action', 'ed_visit.registered')->where('patient_id', $fx['patient']->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'ed_visit.arrived')->where('resource_type', 'ed_visit_event')->count())->toBe(1);

    // A presenting complaint is required at arrival.
    expect(fn () => app(EdVisitService::class)->register($fx['physician'], $fx['patient'], $fx['branch'], EdVisit::ARRIVAL_WALK_IN, '   '))
        ->toThrow(EdVisitException::class);

    // An unknown arrival mode is rejected.
    expect(fn () => app(EdVisitService::class)->register($fx['physician'], $fx['patient'], $fx['branch'], 'helicopter', 'Chest pain'))
        ->toThrow(EdVisitException::class);
});

test('the EdVisit is patient-read-logged (LogsReads wired to patient_id)', function () {
    $fx = edFixture();
    $visit = app(EdVisitService::class)->register($fx['physician'], $fx['patient'], $fx['branch'], EdVisit::ARRIVAL_WALK_IN, 'Ankle injury');

    $visit->auditRead();

    expect(AuditEvent::query()->where('action', 'read')->where('resource_type', 'ed_visits')->where('patient_id', $fx['patient']->id)->count())->toBe(1);
});

test('the visit advances through its LEGAL flow, recording an append-only audited event each step', function () {
    $fx = edFixture();
    $svc = app(EdVisitService::class);
    $visit = $svc->register($fx['physician'], $fx['patient'], $fx['branch'], EdVisit::ARRIVAL_WALK_IN, 'Fever');

    foreach ([EdVisit::STATUS_TRIAGED, EdVisit::STATUS_IN_TREATMENT, EdVisit::STATUS_AWAITING_DISPOSITION] as $to) {
        $svc->transition($fx['physician'], $visit, $to);
        $visit = $visit->fresh();
    }
    // The disposition is recorded on the final transition (the ED→ADT admit handoff detail is G5).
    $visit = $svc->transition($fx['physician'], $visit, EdVisit::STATUS_DISPOSITIONED, null, EdVisit::DISPOSITION_ADMIT);

    expect($visit->status)->toBe(EdVisit::STATUS_DISPOSITIONED)
        ->and($visit->disposition)->toBe('admit')                 // an operational outcome (recorded fact)
        ->and($visit->dispositioned_at)->not->toBeNull()
        // arrival + 4 transitions = 5 append-only flow events.
        ->and(EdVisitEvent::query()->where('ed_visit_id', $visit->id)->count())->toBe(5)
        ->and(AuditEvent::query()->where('action', 'ed_visit.in_treatment')->where('resource_type', 'ed_visit_event')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'ed_visit.dispositioned')->where('patient_id', $fx['patient']->id)->count())->toBe(1);
});

test('the flow is LEGAL-ONLY: illegal transitions + bad dispositions throw', function () {
    $fx = edFixture();
    $svc = app(EdVisitService::class);
    $visit = $svc->register($fx['physician'], $fx['patient'], $fx['branch'], EdVisit::ARRIVAL_WALK_IN, 'Cough');

    // Cannot skip triage: arrived -> in_treatment is illegal.
    expect(fn () => $svc->transition($fx['physician'], $visit, EdVisit::STATUS_IN_TREATMENT))
        ->toThrow(EdVisitException::class);

    // Moving to dispositioned REQUIRES a valid disposition; an invalid one is rejected.
    $svc->transition($fx['physician'], $visit, EdVisit::STATUS_TRIAGED);
    $svc->transition($fx['physician'], $visit->fresh(), EdVisit::STATUS_IN_TREATMENT);
    $svc->transition($fx['physician'], $visit->fresh(), EdVisit::STATUS_AWAITING_DISPOSITION);
    expect(fn () => $svc->transition($fx['physician'], $visit->fresh(), EdVisit::STATUS_DISPOSITIONED))
        ->toThrow(EdVisitException::class); // disposition required
    expect(fn () => $svc->transition($fx['physician'], $visit->fresh(), EdVisit::STATUS_DISPOSITIONED, null, 'teleport'))
        ->toThrow(EdVisitException::class); // invalid disposition

    // A disposition on a non-disposition transition is rejected.
    $fx2 = edFixture('edb');
    $v2 = $svc->register($fx2['physician'], $fx2['patient'], $fx2['branch'], EdVisit::ARRIVAL_WALK_IN, 'Rash');
    expect(fn () => $svc->transition($fx2['physician'], $v2, EdVisit::STATUS_TRIAGED, null, EdVisit::DISPOSITION_DISCHARGE))
        ->toThrow(EdVisitException::class);

    // left_without_being_seen is terminal — no onward transition.
    $svc->transition($fx2['physician'], $v2->fresh(), EdVisit::STATUS_LEFT_WITHOUT_BEING_SEEN);
    expect($v2->fresh()->status)->toBe(EdVisit::STATUS_LEFT_WITHOUT_BEING_SEEN)
        ->and(fn () => $svc->transition($fx2['physician'], $v2->fresh(), EdVisit::STATUS_TRIAGED))
        ->toThrow(EdVisitException::class);
});

test('the flow-event history is APPEND-ONLY (model guard + DB trigger)', function () {
    $fx = edFixture();
    app(EdVisitService::class)->register($fx['physician'], $fx['patient'], $fx['branch'], EdVisit::ARRIVAL_WALK_IN, 'Headache');
    $event = EdVisitEvent::query()->firstOrFail();

    // Model guard (belt).
    expect(fn () => $event->update(['reason' => 'tampered']))->toThrow(EdVisitException::class);

    // DB trigger (suspenders) — a raw update/delete bypassing the model still fails.
    expect(fn () => DB::table('ed_visit_events')->where('id', $event->id)->update(['reason' => 'tampered']))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('ed_visit_events')->where('id', $event->id)->delete())
        ->toThrow(QueryException::class);
});

test('RBAC: registering + advancing a visit is ed.manage-gated; reception is refused', function () {
    $fx = edFixture();
    $svc = app(EdVisitService::class);

    // reception holds no ed.manage — refused at the gate for both register + transition.
    expect(fn () => $svc->register($fx['reception'], $fx['patient'], $fx['branch'], EdVisit::ARRIVAL_WALK_IN, 'Chest pain'))
        ->toThrow(AuthorizationException::class);

    $visit = $svc->register($fx['physician'], $fx['patient'], $fx['branch'], EdVisit::ARRIVAL_WALK_IN, 'Chest pain');
    expect(fn () => $svc->transition($fx['reception'], $visit, EdVisit::STATUS_TRIAGED))
        ->toThrow(AuthorizationException::class);

    // The triage nurse (ed.manage) can advance flow.
    expect($svc->transition($fx['triageNurse'], $visit, EdVisit::STATUS_TRIAGED)->status)->toBe(EdVisit::STATUS_TRIAGED);
});

test('cross-tenant is fail-closed: a tenant cannot advance another tenant ED visit', function () {
    $fxA = edFixture('alpha');
    $visitA = app(EdVisitService::class)->register($fxA['physician'], $fxA['patient'], $fxA['branch'], EdVisit::ARRIVAL_WALK_IN, 'Chest pain');

    $fxB = edFixture('beta');
    edCtx()->set($fxB['tenant']);

    expect(fn () => app(EdVisitService::class)->transition($fxB['physician'], $visitA, EdVisit::STATUS_TRIAGED))
        ->toThrow(CrossTenantReferenceException::class);
});

/*
 * THE TRIAGE-ACUITY SEAM — the crux. It EXISTS (a real interface for a future certified partner) and is EMPTY
 * (a null-object that computes NO acuity). CareOS builds the seam, NOT the judgment.
 */

test('the triage-acuity seam is bound to the null-object and asserts NOTHING (no computed acuity)', function () {
    edFixture();

    $provider = app(TriageAcuityProvider::class);
    expect($provider)->toBeInstanceOf(NullTriageAcuityProvider::class);

    // The null-object computes nothing: every call returns "no suggestion" regardless of the context.
    $result = $provider->suggestAcuity(new AcuityContext('Chest pain', ['hr' => 120, 'sbp' => 90]));
    expect($result)->toBeInstanceOf(AcuityResult::class)
        ->and($result->hasSuggestion())->toBeFalse()   // asserts nothing — no computed level
        ->and($result->suggestedLevel)->toBeNull()
        ->and($result->scale)->toBeNull();
});

test('the seam is REAL: a certified partner can be bound in place WITHOUT touching consumers', function () {
    // Prove the seam is a genuine swap point for a future partner — bind a test double and resolve it.
    $double = new class implements TriageAcuityProvider
    {
        public function suggestAcuity(AcuityContext $context): AcuityResult
        {
            return new AcuityResult('ESI-2', 'ESI'); // an ADVISORY suggestion a partner might return (human-owned)
        }
    };
    app()->bind(TriageAcuityProvider::class, fn () => $double);

    $resolved = app(TriageAcuityProvider::class);
    expect($resolved->suggestAcuity(new AcuityContext('Chest pain'))->suggestedLevel)->toBe('ESI-2');

    // The default (unbound) ships as the null-object — CareOS computes no acuity itself.
    app()->forgetInstance(TriageAcuityProvider::class);
    app()->bind(TriageAcuityProvider::class, NullTriageAcuityProvider::class);
    expect(app(TriageAcuityProvider::class))->toBeInstanceOf(NullTriageAcuityProvider::class);
});

test('FENCE: no computed-acuity/triage/priority column on the visit; the module computes no acuity', function () {
    // The visit + its events are OPERATIONAL flow records. There is NO computed-judgment column: acuity is
    // ASSIGNED by the triage nurse in a separate record (ED.G2), never a column here; a computed triage acuity
    // is the fence line (map §3).
    $forbidden = ['acuity', 'triage', 'priority', 'severity', 'risk', 'score', 'prediction', 'predicted', 'grade', 'rating'];
    foreach (['ed_visits', 'ed_visit_events'] as $table) {
        $columns = Schema::getColumnListing($table);
        foreach ($forbidden as $word) {
            expect($columns)->not->toContain($word, "{$table} must not carry a computed-judgment column: {$word}");
        }
    }

    // The module manufactures no acuity: there is NO compute/score/calculate-acuity (or triage-score) logic
    // anywhere in Modules\ED\src — the null-object is the only implementation of the seam.
    $files = collect(File::allFiles(base_path('Modules/ED/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['computeAcuity', 'calculateAcuity', 'scoreAcuity', 'assignAcuity', 'triageScore', 'computeTriage', 'acuityScore'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("ED must not compute a triage acuity ({$needle})");
        }
    }
});
