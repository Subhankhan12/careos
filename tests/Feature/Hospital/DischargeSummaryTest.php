<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\DischargeSummary;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Services\AdmissionService;
use Modules\Hospital\Services\BedService;
use Modules\Hospital\Services\DischargeSummaryService;
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
 * HOSPITAL.G7 — discharge summary + LOS + episode close-out (the FINAL Phase-1 gate). The discharge summary
 * is a stay-scoped, clinician-authored SIGN-AND-LOCK document (reusing the ClinicalNote sign-and-lock
 * discipline + conditional immutability trigger). LOS is a DERIVED elapsed fact (never stored/graded).
 * RECORD-NOT-JUDGE: the narrative is the clinician's own words; nothing is computed or auto-populated.
 */

// Frozen so LOS ties out deterministically (discharge stamps discharged_at = now()).
beforeEach(function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function dsCtx(): TenantContext
{
    return app(TenantContext::class);
}

function dsTenant(string $slug = 'dishosp'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Hospital', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    dsCtx()->set($tenant);

    return $tenant;
}

function dsUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, stay: Stay, patient: Patient, clinician: User, reception: User, billing: User} */
function dsFixture(string $slug = 'dishosp'): array
{
    $tenant = dsTenant($slug);
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $clinician = dsUser($tenant, 'hospitalist'); // admission.manage + note.write + note.sign + patient.view
    $reception = dsUser($tenant, 'reception');   // patient.view but NOT note.write / note.sign
    $billing = dsUser($tenant, 'billing');       // no patient.view
    $manager = dsUser($tenant, 'bed_manager');
    $ward = app(WardService::class)->create($manager, $branch->id, 'Ward 1', 'W1');
    $bed = app(BedService::class)->create($manager, $ward, '1', Bed::TYPE_GENERAL);
    $clinicianProfile = StaffProfile::query()->create([
        'first_name' => 'Hana', 'last_name' => 'Hospitalist', 'display_name' => 'Dr Hana Hospitalist',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Ivy', 'last_name' => 'Inpatient', 'date_of_birth' => '1975-03-03', 'sex' => 'female']);
    $stay = app(AdmissionService::class)->admit($clinician, $patient, $bed, $clinicianProfile, Stay::TYPE_ELECTIVE);

    return compact('tenant', 'stay', 'patient', 'clinician', 'reception', 'billing');
}

function dsAssertNoJudgment(array $data): void
{
    // los_minutes / status / disposition are legitimate operational FACTS — not judgments.
    $forbidden = ['acuity', 'severity', 'score', 'risk', 'grade', 'priority', 'deterioration', 'flag',
        'abnormal', 'triage', 'ews', 'news', 'rating', 'alert', 'outlier', 'readmission', 'expected_los'];
    foreach ($data as $key => $value) {
        expect(in_array((string) $key, $forbidden, true))->toBeFalse("interpretation key '{$key}' leaked into the discharge-summary payload");
        if (is_array($value)) {
            dsAssertNoJudgment($value);
        }
    }
}

test('length-of-stay is a DERIVED elapsed fact (discharged − admitted), never stored or graded', function () {
    $fx = dsFixture();

    // Still admitted → no LOS yet.
    expect($fx['stay']->fresh()->lengthOfStayMinutes())->toBeNull();

    // Admitted 3d 4h before the frozen discharge time.
    $fx['stay']->forceFill(['admitted_at' => Carbon::parse('2026-06-12 08:00:00')])->save();
    app(AdmissionService::class)->discharge($fx['clinician'], $fx['stay']->fresh(), Stay::DISPOSITION_HOME);

    expect($fx['stay']->fresh()->lengthOfStayMinutes())->toBe(3 * 1440 + 4 * 60); // 4560 minutes, a raw duration

    // FENCE: LOS is derived — never a stored column, never a grade/outlier/expected-vs-actual.
    foreach (['stays', 'discharge_summaries'] as $table) {
        $cols = Schema::getColumnListing($table);
        foreach (['los', 'length_of_stay', 'los_minutes', 'los_rating', 'outlier', 'expected_los', 'acuity', 'severity', 'score', 'risk', 'grade'] as $word) {
            expect($cols)->not->toContain($word, "{$table} must not store or grade LOS/judgment: {$word}");
        }
    }
});

test('the discharge summary is sign-and-lock: a draft is editable, finalize locks it immutable, and both are audited', function () {
    $fx = dsFixture();

    $draft = app(DischargeSummaryService::class)->saveDraft($fx['clinician'], $fx['stay'], 'Course: uneventful.', 'Rest and hydrate.');
    expect($draft->status)->toBe(DischargeSummary::STATUS_DRAFT)
        ->and($draft->summary)->toBe('Course: uneventful.')
        ->and($draft->instructions)->toBe('Rest and hydrate.')
        ->and($draft->tenant_id)->toBe($fx['tenant']->id)
        ->and($draft->patient_id)->toBe($fx['patient']->id);

    // A draft is editable — one summary per stay (updateOrCreate).
    $edited = app(DischargeSummaryService::class)->saveDraft($fx['clinician'], $fx['stay'], 'Course: uneventful; mobilised.', null);
    expect(DischargeSummary::query()->where('stay_id', $fx['stay']->id)->count())->toBe(1)
        ->and($edited->summary)->toBe('Course: uneventful; mobilised.');

    // Finalize → sign-and-lock.
    $final = app(DischargeSummaryService::class)->finalize($fx['clinician'], $fx['stay']);
    expect($final->status)->toBe(DischargeSummary::STATUS_FINALIZED)
        ->and($final->finalized_at)->not->toBeNull()
        ->and($final->finalized_by)->toBe($fx['clinician']->id);

    // Immutable: model guard (belt) + DB trigger (raw), and no delete.
    expect(fn () => $final->fresh()->update(['summary' => 'tampered']))->toThrow(AdmissionException::class);
    expect(fn () => DB::table('discharge_summaries')->where('id', $final->id)->update(['summary' => 'raw']))->toThrow(QueryException::class);
    expect(fn () => DB::table('discharge_summaries')->where('id', $final->id)->delete())->toThrow(QueryException::class);

    // Audited: drafted (create/edit) + finalized; the chain stays intact.
    expect(AuditEvent::query()->where('action', 'discharge_summary.drafted')->count())->toBeGreaterThanOrEqual(1)
        ->and(AuditEvent::query()->where('action', 'discharge_summary.finalized')->where('resource_id', $final->id)->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();
});

test('the closed-episode view shows LOS + disposition + the finalized summary read-only, and read-logs the stay', function () {
    $fx = dsFixture();
    $fx['stay']->forceFill(['admitted_at' => Carbon::parse('2026-06-12 08:00:00')])->save();
    app(AdmissionService::class)->discharge($fx['clinician'], $fx['stay']->fresh(), Stay::DISPOSITION_HOME);
    app(DischargeSummaryService::class)->saveDraft($fx['clinician'], $fx['stay']->fresh(), 'Uneventful recovery.', 'Rest.');
    app(DischargeSummaryService::class)->finalize($fx['clinician'], $fx['stay']->fresh());

    dsCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get('/hospital/admissions/'.$fx['stay']->id.'/discharge-summary')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Hospital/DischargeSummary')
            ->where('stay.los_minutes', 4560)                 // derived fact
            ->where('stay.discharge_disposition', 'home')
            ->where('summary.is_finalized', true)
            ->where('summary.summary', 'Uneventful recovery.')
            ->where('actions.can_author', false)              // finalized → read-only
            ->where('actions.can_finalize', false));

    // Patient-scoped read-logged (the Stay disclosure produced a 'read' audit row).
    dsCtx()->set($fx['tenant']);
    expect(AuditEvent::query()->where('resource_id', $fx['stay']->id)->where('action', 'read')->count())->toBeGreaterThanOrEqual(1);
});

test('FENCE: the discharge summary is clinician-authored — nothing auto-generated, and the payload carries no judgment key', function () {
    $fx = dsFixture();

    // Nothing auto-populates: even a fresh stay has no summary until a clinician writes one.
    expect(DischargeSummary::query()->where('stay_id', $fx['stay']->id)->count())->toBe(0);

    $summary = app(DischargeSummaryService::class)->saveDraft($fx['clinician'], $fx['stay'], 'exactly what the clinician wrote', null);
    expect($summary->summary)->toBe('exactly what the clinician wrote'); // verbatim — not derived/scored

    // Schema: no computed-judgment column (summary + instructions are the authored fields).
    $cols = Schema::getColumnListing('discharge_summaries');
    foreach (['acuity', 'severity', 'score', 'risk', 'grade', 'priority', 'deterioration', 'flag', 'readmission', 'rating'] as $word) {
        expect($cols)->not->toContain($word, "discharge_summaries must not carry a judgment column: {$word}");
    }
    expect($cols)->toContain('summary')->toContain('instructions');

    // The rendered closed-episode payload carries no judgment key anywhere.
    dsCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get('/hospital/admissions/'.$fx['stay']->id.'/discharge-summary')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Hospital/DischargeSummary')
            ->where('stay', function ($stay): bool {
                dsAssertNoJudgment((array) $stay);

                return true;
            })
            ->where('episode', function ($episode): bool {
                dsAssertNoJudgment((array) $episode);

                return true;
            }));
});

test('the discharge summary is RBAC-gated: note.write to author, note.sign to finalize, patient.view to view', function () {
    $fx = dsFixture();

    // reception holds patient.view but NOT note.write/note.sign → cannot author or finalize.
    expect(fn () => app(DischargeSummaryService::class)->saveDraft($fx['reception'], $fx['stay'], 'x', null))
        ->toThrow(AuthorizationException::class);

    app(DischargeSummaryService::class)->saveDraft($fx['clinician'], $fx['stay'], 'draft', null);
    expect(fn () => app(DischargeSummaryService::class)->finalize($fx['reception'], $fx['stay']))
        ->toThrow(AuthorizationException::class);

    // billing (no patient.view) cannot even view the closed episode; the clinician can.
    dsCtx()->forget();
    $this->actingAs($fx['billing'])->get('/hospital/admissions/'.$fx['stay']->id.'/discharge-summary')->assertForbidden();
    dsCtx()->forget();
    $this->actingAs($fx['clinician'])->get('/hospital/admissions/'.$fx['stay']->id.'/discharge-summary')->assertOk();

    // reception CAN view (patient.view) but the write routes are refused at the gate.
    dsCtx()->forget();
    $this->actingAs($fx['reception'])->get('/hospital/admissions/'.$fx['stay']->id.'/discharge-summary')->assertOk();
    dsCtx()->forget();
    $this->actingAs($fx['reception'])->post('/hospital/admissions/'.$fx['stay']->id.'/discharge-summary', ['summary' => 'x'])->assertForbidden();
    dsCtx()->forget();
    $this->actingAs($fx['reception'])->post('/hospital/admissions/'.$fx['stay']->id.'/discharge-summary/finalize')->assertForbidden();
});

test('the discharge summary is tenant scoped: a cross-tenant stay is 404 / rejected', function () {
    $fxA = dsFixture('alpha');
    $fxB = dsFixture('beta');

    dsCtx()->forget();
    $this->actingAs($fxB['clinician'])->get('/hospital/admissions/'.$fxA['stay']->id.'/discharge-summary')->assertNotFound();

    dsCtx()->set($fxB['tenant']);
    expect(fn () => app(DischargeSummaryService::class)->saveDraft($fxB['clinician'], $fxA['stay'], 'x', null))
        ->toThrow(CrossTenantReferenceException::class);
});
