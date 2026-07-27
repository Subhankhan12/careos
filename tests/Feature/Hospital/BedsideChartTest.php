<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\WardRound;
use Modules\Hospital\Services\AdmissionService;
use Modules\Hospital\Services\BedService;
use Modules\Hospital\Services\BedsideChartService;
use Modules\Hospital\Services\WardService;
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
 * HOSPITAL.G4 — bedside charting REUSES the tested Clinical module against a stay, WITHOUT modifying
 * Clinical: a ward round is a reused Encounter (its one-open-per-practitioner invariant intact), the
 * note is the existing sign-and-lock ClinicalNote, vitals are the raw Vital + a stay-scoped read, and
 * orders are the structured Order. The fence carries through: raw vitals, no computed acuity/score.
 */

function bcCtx(): TenantContext
{
    return app(TenantContext::class);
}

function bcTenant(string $slug = 'charthosp'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Hospital', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    bcCtx()->set($tenant);

    return $tenant;
}

function bcUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, stay: Stay, patient: Patient, clinician: StaffProfile, user: User, billing: User, orderableItem: OrderableItem} */
function bcFixture(string $slug = 'charthosp'): array
{
    $tenant = bcTenant($slug);
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $user = bcUser($tenant, 'hospitalist'); // encounter.manage + note.write + note.sign + order.manage + admission.manage + patient.view
    $billing = bcUser($tenant, 'billing');  // NO patient.view / clinical perms
    $manager = bcUser($tenant, 'bed_manager');
    $ward = app(WardService::class)->create($manager, $branch->id, 'Ward 1', 'W1');
    $bed = app(BedService::class)->create($manager, $ward, '1', Bed::TYPE_GENERAL);
    $clinician = StaffProfile::query()->create([
        'first_name' => 'Hana', 'last_name' => 'Hospitalist', 'display_name' => 'Dr Hana Hospitalist',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Ivy', 'last_name' => 'Inpatient', 'date_of_birth' => '1975-03-03', 'sex' => 'female']);
    $stay = app(AdmissionService::class)->admit($user, $patient, $bed, $clinician, Stay::TYPE_ELECTIVE);
    $orderableItem = OrderableItem::query()->create(['category' => 'lab', 'code' => 'CBC', 'name' => 'Complete Blood Count', 'specimen_or_modality' => 'blood', 'active' => true]);

    return compact('tenant', 'stay', 'patient', 'clinician', 'user', 'billing', 'orderableItem');
}

function bcAssertNoJudgment(array $data): void
{
    $forbidden = ['acuity', 'severity', 'score', 'risk', 'grade', 'flag', 'abnormal', 'priority',
        'deterioration', 'triage', 'ews', 'news', 'band', 'rating', 'alert', 'interpretation', 'normal'];
    foreach ($data as $key => $value) {
        expect(in_array((string) $key, $forbidden, true))->toBeFalse("interpretation key '{$key}' leaked into the stay-chart payload");
        if (is_array($value)) {
            bcAssertNoJudgment($value);
        }
    }
}

test('a ward round creates a Clinical Encounter tied to the stay — Encounter reused, invariant intact', function () {
    $fx = bcFixture();

    $result = app(BedsideChartService::class)->startRound($fx['user'], $fx['stay']);
    $encounter = Encounter::query()->find($result['round']->encounter_id);

    expect($encounter)->not->toBeNull()
        ->and($encounter->patient_id)->toBe($fx['patient']->id)
        ->and($encounter->status)->toBe(Encounter::STATUS_OPEN)
        ->and($encounter->type)->toBe(Encounter::TYPE_OTHER) // a reused generic Encounter; no inpatient type added to Clinical
        ->and(WardRound::query()->where('stay_id', $fx['stay']->id)->where('encounter_id', $encounter->id)->count())->toBe(1)
        ->and($result['note']->status)->toBe(ClinicalNote::STATUS_DRAFT);

    // The one-open-per-practitioner invariant is UNCHANGED and enforced by the reused EncounterService:
    // a second round for the same stay (same patient + admitting clinician) is refused.
    expect(fn () => app(BedsideChartService::class)->startRound($fx['user'], $fx['stay']->fresh()))
        ->toThrow(InvalidArgumentException::class);
});

test('a vital recorded during the stay is raw, and the forStay read returns it (no bands/scores)', function () {
    $fx = bcFixture();
    app(BedsideChartService::class)->startRound($fx['user'], $fx['stay']);

    app(BedsideChartService::class)->recordVital($fx['user'], $fx['stay'], ['systolic' => 120, 'diastolic' => 80, 'heart_rate' => 72]);

    $vitals = app(BedsideChartService::class)->vitalsForStay($fx['stay']);
    expect($vitals['metrics']['systolic'][0]['value'])->toBe(120)
        ->and($vitals['metrics']['heart_rate'][0]['value'])->toBe(72);
    bcAssertNoJudgment($vitals); // raw — no interpretation key anywhere

    // recording a vital before any round has been started is refused
    $fx2 = bcFixture('chartb');
    expect(fn () => app(BedsideChartService::class)->recordVital($fx2['user'], $fx2['stay'], ['heart_rate' => 60]))
        ->toThrow(AdmissionException::class);
});

test('a ward-round note signs, locks, and amends through the existing Clinical flow', function () {
    $fx = bcFixture();
    $note = app(BedsideChartService::class)->startRound($fx['user'], $fx['stay'])['note'];
    $notes = app(ClinicalNoteService::class);

    $signed = $notes->sign($note, $fx['user']);
    expect($signed->status)->toBe(ClinicalNote::STATUS_SIGNED);

    // a signed note is locked (the existing immutability guard)
    expect(fn () => $signed->update(['subjective' => 'edited']))->toThrow(LogicException::class);

    // an amendment is a NEW version (the existing amend flow)
    $amended = $notes->amend($signed, [], 'correction after review', $fx['clinician'], $fx['user']);
    expect($amended->version)->toBe(2);
});

test('an order placed during the stay is a reused structured Order tied to the stay', function () {
    $fx = bcFixture();
    app(BedsideChartService::class)->startRound($fx['user'], $fx['stay']);

    $order = app(BedsideChartService::class)->placeOrder($fx['user'], $fx['stay'], $fx['orderableItem'], ['priority' => Order::PRIORITY_ROUTINE]);

    expect($order->status)->toBe(Order::STATUS_ORDERED)
        ->and($order->patient_id)->toBe($fx['patient']->id)
        ->and(app(BedsideChartService::class)->ordersForStay($fx['stay'])->count())->toBe(1);
});

test('FENCE: the stay-chart payload carries no acuity/severity/score/grade/flag field (raw only)', function () {
    $fx = bcFixture();
    app(BedsideChartService::class)->startRound($fx['user'], $fx['stay']);
    app(BedsideChartService::class)->recordVital($fx['user'], $fx['stay'], ['systolic' => 120, 'heart_rate' => 72]);

    bcCtx()->forget();
    $this->actingAs($fx['user'])
        ->get('/hospital/admissions/'.$fx['stay']->id.'/chart')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Hospital/StayChart')
            ->has('rounds', 1)
            ->has('vitals.metrics.systolic', 1)
            ->where('vitals', function ($vitals) {
                bcAssertNoJudgment(collect($vitals)->toArray());

                return true;
            })
            ->where('rounds', function ($rounds) {
                bcAssertNoJudgment(collect($rounds)->toArray());

                return true;
            }));
});

test('bedside charting is gated by the existing clinical permissions', function () {
    $fx = bcFixture();

    // billing (no patient.view) cannot view the chart; the hospitalist can.
    bcCtx()->forget();
    $this->actingAs($fx['billing'])->get('/hospital/admissions/'.$fx['stay']->id.'/chart')->assertForbidden();
    bcCtx()->forget();
    $this->actingAs($fx['user'])->get('/hospital/admissions/'.$fx['stay']->id.'/chart')->assertOk();

    // starting a round needs encounter.manage — billing is denied at the gate through the real stack.
    bcCtx()->forget();
    $this->actingAs($fx['billing'])->post('/hospital/admissions/'.$fx['stay']->id.'/rounds', [])->assertForbidden();
});

test('the stay chart is tenant scoped: a cross-tenant stay is 404, not disclosed', function () {
    $fxA = bcFixture('alpha');
    $fxB = bcFixture('beta');

    // tenant B's user cannot reach tenant A's stay chart (the string-id resolve is tenant-scoped).
    bcCtx()->forget();
    $this->actingAs($fxB['user'])
        ->get('/hospital/admissions/'.$fxA['stay']->id.'/chart')
        ->assertNotFound();
});
