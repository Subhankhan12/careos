<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;
use Modules\Hospital\Exceptions\HandoverException;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Handover;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Services\AdmissionService;
use Modules\Hospital\Services\BedService;
use Modules\Hospital\Services\HandoverService;
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
 * HOSPITAL.G5 — nursing shift handover: a NET-NEW structured SBAR artifact the outgoing nurse authors,
 * tied to a stay, append-only. RECORD-NOT-JUDGE: the SBAR fields capture what the nurse WROTE — the
 * system computes no acuity/score/risk and auto-populates nothing.
 */

function hoCtx(): TenantContext
{
    return app(TenantContext::class);
}

function hoTenant(string $slug = 'handhosp'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Hospital', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    hoCtx()->set($tenant);

    return $tenant;
}

function hoUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, stay: Stay, patient: Patient, nurse: User, billing: User} */
function hoFixture(string $slug = 'handhosp'): array
{
    $tenant = hoTenant($slug);
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $admitter = hoUser($tenant, 'hospitalist'); // admission.manage — to create the stay
    $nurse = hoUser($tenant, 'ward_nurse');     // note.write + patient.view — records/reads handovers
    $billing = hoUser($tenant, 'billing');      // NO note.write / patient.view
    $manager = hoUser($tenant, 'bed_manager');
    $ward = app(WardService::class)->create($manager, $branch->id, 'Ward 1', 'W1');
    $bed = app(BedService::class)->create($manager, $ward, '1', Bed::TYPE_GENERAL);
    $clinician = StaffProfile::query()->create([
        'first_name' => 'Hana', 'last_name' => 'Hospitalist', 'display_name' => 'Dr Hana Hospitalist',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Ivy', 'last_name' => 'Inpatient', 'date_of_birth' => '1975-03-03', 'sex' => 'female']);
    $stay = app(AdmissionService::class)->admit($admitter, $patient, $bed, $clinician, Stay::TYPE_ELECTIVE);

    return compact('tenant', 'stay', 'patient', 'nurse', 'billing');
}

function hoAssertNoJudgment(array $data): void
{
    // NOTE: 'assessment' is a legitimate SBAR field (the nurse's own written assessment), NOT forbidden.
    $forbidden = ['acuity', 'severity', 'score', 'risk', 'grade', 'priority', 'deterioration', 'flag',
        'abnormal', 'triage', 'ews', 'news', 'rating', 'alert'];
    foreach ($data as $key => $value) {
        expect(in_array((string) $key, $forbidden, true))->toBeFalse("interpretation key '{$key}' leaked into the handover payload");
        if (is_array($value)) {
            hoAssertNoJudgment($value);
        }
    }
}

test('a nurse records an SBAR handover for a stay — tenant + patient scoped, and audited', function () {
    $fx = hoFixture();

    $handover = app(HandoverService::class)->record($fx['nurse'], $fx['stay'], Handover::SHIFT_DAY, [
        'situation' => 'Post-op day 1, stable',
        'background' => 'Appendectomy overnight',
        'assessment' => 'Comfortable; obs within the ranges I recorded',
        'recommendation' => 'Continue analgesia; mobilise',
    ]);

    expect($handover->tenant_id)->toBe($fx['tenant']->id)
        ->and($handover->patient_id)->toBe($fx['patient']->id)
        ->and($handover->stay_id)->toBe($fx['stay']->id)
        ->and($handover->shift)->toBe('day')
        ->and($handover->situation)->toBe('Post-op day 1, stable')
        ->and($handover->assessment)->toBe('Comfortable; obs within the ranges I recorded'); // verbatim — the nurse's words

    expect(AuditEvent::query()->where('action', 'handover.recorded')->where('resource_id', $handover->id)->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();
});

test('handovers are append-only (a correction is a new handover; the shift history is preserved)', function () {
    $fx = hoFixture();
    $first = app(HandoverService::class)->record($fx['nurse'], $fx['stay'], Handover::SHIFT_DAY, ['situation' => 'first']);

    expect(fn () => $first->update(['situation' => 'edited']))->toThrow(HandoverException::class);       // model guard
    expect(fn () => DB::table('handovers')->where('id', $first->id)->delete())->toThrow(QueryException::class); // DB trigger

    app(HandoverService::class)->record($fx['nurse'], $fx['stay'], Handover::SHIFT_DAY, ['situation' => 'corrected'], 'typo fix');
    expect(Handover::query()->where('stay_id', $fx['stay']->id)->count())->toBe(2);
});

test('FENCE: a handover is nurse-authored — no acuity/score/risk/priority field, and nothing auto-populates it', function () {
    $fx = hoFixture();

    // Schema: no computed-judgment column (assessment IS present — a SBAR field the nurse writes).
    $cols = Schema::getColumnListing('handovers');
    foreach (['acuity', 'severity', 'score', 'risk', 'grade', 'priority', 'deterioration', 'flag', 'abnormal', 'triage', 'ews', 'news'] as $word) {
        expect($cols)->not->toContain($word, "handovers must not carry a clinical-judgment column: {$word}");
    }
    expect($cols)->toContain('situation')->toContain('assessment');

    // Nothing auto-populates: a fresh stay has zero handovers, and a recorded handover is verbatim.
    expect(Handover::query()->where('stay_id', $fx['stay']->id)->count())->toBe(0);
    $h = app(HandoverService::class)->record($fx['nurse'], $fx['stay'], Handover::SHIFT_NIGHT, ['situation' => 's', 'assessment' => 'exactly what the nurse wrote']);
    expect($h->assessment)->toBe('exactly what the nurse wrote'); // not derived/scored

    // The rendered payload carries no judgment key.
    hoCtx()->forget();
    $this->actingAs($fx['nurse'])
        ->get('/hospital/admissions/'.$fx['stay']->id.'/handover')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Hospital/Handover')
            ->has('handovers', 1)
            ->where('handovers', function ($handovers) {
                hoAssertNoJudgment(collect($handovers)->toArray());

                return true;
            }));
});

test('the handover history returns the stay\'s shift trail, newest first', function () {
    $fx = hoFixture();
    app(HandoverService::class)->record($fx['nurse'], $fx['stay'], Handover::SHIFT_DAY, ['situation' => 'day handover']);
    app(HandoverService::class)->record($fx['nurse'], $fx['stay'], Handover::SHIFT_NIGHT, ['situation' => 'night handover']);

    $history = app(HandoverService::class)->history($fx['stay']);
    expect($history)->toHaveCount(2)
        ->and($history->first()->shift)->toBe('night'); // newest first
});

test('handover is RBAC-gated: note.write to record, patient.view to view', function () {
    $fx = hoFixture();

    // billing (no note.write) cannot record; (no patient.view) cannot view.
    expect(fn () => app(HandoverService::class)->record($fx['billing'], $fx['stay'], Handover::SHIFT_DAY, ['situation' => 'x']))
        ->toThrow(AuthorizationException::class);
    hoCtx()->forget();
    $this->actingAs($fx['billing'])->get('/hospital/admissions/'.$fx['stay']->id.'/handover')->assertForbidden();

    // the ward nurse can view + record through the real stack.
    hoCtx()->forget();
    $this->actingAs($fx['nurse'])->get('/hospital/admissions/'.$fx['stay']->id.'/handover')->assertOk();
    hoCtx()->forget();
    $this->actingAs($fx['nurse'])
        ->post('/hospital/admissions/'.$fx['stay']->id.'/handover', ['shift' => 'day', 'situation' => 'via http'])
        ->assertRedirect();
    hoCtx()->set($fx['tenant']);
    expect(Handover::query()->where('stay_id', $fx['stay']->id)->where('situation', 'via http')->count())->toBe(1);
});

test('handovers are tenant scoped: a cross-tenant stay is 404 / rejected', function () {
    $fxA = hoFixture('alpha');
    $fxB = hoFixture('beta');

    hoCtx()->forget();
    $this->actingAs($fxB['nurse'])->get('/hospital/admissions/'.$fxA['stay']->id.'/handover')->assertNotFound();

    hoCtx()->set($fxB['tenant']);
    expect(fn () => app(HandoverService::class)->record($fxB['nurse'], $fxA['stay'], Handover::SHIFT_DAY, ['situation' => 'x']))
        ->toThrow(CrossTenantReferenceException::class);
});
