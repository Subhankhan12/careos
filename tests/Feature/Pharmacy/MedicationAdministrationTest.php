<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Pharmacy\Contracts\MedicationSafetyProvider;
use Modules\Pharmacy\Exceptions\MedicationAdministrationException;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Pharmacy\Models\MedicationAdministration;
use Modules\Pharmacy\Models\MedicationOrder;
use Modules\Pharmacy\Services\MedicationAdministrationService;
use Modules\Pharmacy\Services\MedicationOrderService;
use Modules\Pharmacy\Support\SafetyAlert;
use Modules\Pharmacy\Support\SafetyContext;
use Modules\Pharmacy\Support\SafetyResult;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * PHARMACY.G3 — the eMAR. A NET-NEW append-only administration record against a G2 order. RECORD-NOT-JUDGE:
 * "given/held/refused" is a FACT the nurse records; the system computes no safety verdict and no "late/
 * missed" grade. THREADS the safety seam at the ADMINISTRATION point (calls `checkAdministration`, today
 * the null-object → none(), never blocks; NO homemade checking).
 */

function maCtx(): TenantContext
{
    return app(TenantContext::class);
}

function maTenant(string $slug = 'emarhosp'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Hospital', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    maCtx()->set($tenant);

    return $tenant;
}

function maUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, prescriber: User, nurse: User, reception: User, billing: User, patient: Patient, order: MedicationOrder} */
function maFixture(string $slug = 'emarhosp'): array
{
    $tenant = maTenant($slug);
    $prescriber = maUser($tenant, 'doctor');    // medication.prescribe — creates the order
    $nurse = maUser($tenant, 'nurse');          // note.write + patient.view — administers
    $reception = maUser($tenant, 'reception');  // patient.view but NOT note.write
    $billing = maUser($tenant, 'billing');      // NO patient.view
    $patient = app(PatientService::class)->create(['first_name' => 'Ivy', 'last_name' => 'Inpatient', 'date_of_birth' => '1975-03-03', 'sex' => 'female']);
    $item = FormularyItem::query()->create(['code' => 'MED-PARACETAMOL-500', 'name' => 'Paracetamol', 'form' => FormularyItem::FORM_TABLET, 'strength' => '500 mg']);
    $order = app(MedicationOrderService::class)->prescribe($prescriber, $patient, $item, ['dose_amount' => '500', 'dose_unit' => 'mg', 'route' => 'PO', 'frequency' => 'QID']);

    return compact('tenant', 'prescriber', 'nurse', 'reception', 'billing', 'patient', 'order');
}

function maAssertNoJudgment(array $data): void
{
    // outcome/dose/scheduled_at/administered_at are the nurse's operational facts — not judgments.
    $forbidden = ['severity', 'score', 'risk', 'grade', 'priority', 'acuity', 'flag', 'verdict', 'late',
        'missed', 'overdue', 'abnormal', 'rating', 'alert_level', 'urgency'];
    foreach ($data as $key => $value) {
        expect(in_array((string) $key, $forbidden, true))->toBeFalse("interpretation key '{$key}' leaked into the eMAR payload");
        if (is_array($value)) {
            maAssertNoJudgment($value);
        }
    }
}

test('a nurse records an administration (given/held/refused) against a G2 order — dose given, scoped, audited', function () {
    $fx = maFixture();

    $given = app(MedicationAdministrationService::class)->record($fx['nurse'], $fx['order'], ['outcome' => MedicationAdministration::OUTCOME_GIVEN]);
    expect($given)->toBeInstanceOf(MedicationAdministration::class)
        ->and($given->tenant_id)->toBe($fx['tenant']->id)
        ->and($given->patient_id)->toBe($fx['patient']->id)
        ->and($given->medication_order_id)->toBe($fx['order']->id)
        ->and($given->administered_by)->toBe($fx['nurse']->id)
        ->and($given->outcome)->toBe('given')
        ->and($given->dose_amount)->toBe('500')     // defaults from the order for a 'given' dose
        ->and($given->dose_unit)->toBe('mg');

    // held / refused carry the nurse's reason, no dose given.
    $held = app(MedicationAdministrationService::class)->record($fx['nurse'], $fx['order'], ['outcome' => MedicationAdministration::OUTCOME_HELD, 'reason' => 'BP low']);
    expect($held->outcome)->toBe('held')->and($held->reason)->toBe('BP low')->and($held->dose_amount)->toBeNull();

    app(MedicationAdministrationService::class)->record($fx['nurse'], $fx['order'], ['outcome' => MedicationAdministration::OUTCOME_REFUSED, 'reason' => 'patient declined']);

    expect(MedicationAdministration::query()->where('medication_order_id', $fx['order']->id)->count())->toBe(3)
        ->and(AuditEvent::query()->where('action', 'medication.administered')->where('patient_id', $fx['patient']->id)->count())->toBe(3)
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();
});

test('the medication-safety SEAM is CALLED at administration, is advisory + human-owned, and NEVER blocks', function () {
    $fx = maFixture();

    $spy = new class implements MedicationSafetyProvider
    {
        public bool $administrationChecked = false;

        public function checkOrder(SafetyContext $context): SafetyResult
        {
            return SafetyResult::none();
        }

        public function checkAdministration(SafetyContext $context): SafetyResult
        {
            $this->administrationChecked = true;

            return new SafetyResult([new SafetyAlert('PARTNER-ADMIN-001', 'advisory (partner)', 'certified-partner')]);
        }
    };
    app()->instance(MedicationSafetyProvider::class, $spy);

    $administration = app(MedicationAdministrationService::class)->record($fx['nurse'], $fx['order'], ['outcome' => 'given']);

    expect($spy->administrationChecked)->toBeTrue()                                              // the seam was CALLED at administration
        ->and(MedicationAdministration::query()->whereKey($administration->id)->exists())->toBeTrue(); // NOT blocked despite the alert

    // With the shipped default (the null-object), the review asserts nothing.
    app()->forgetInstance(MedicationSafetyProvider::class);
    expect(app(MedicationAdministrationService::class)->safetyReview($fx['patient'])->hasAlerts())->toBeFalse();
});

test('NO homemade checking logic: CareOS never manufactures a safety finding at the administration layer', function () {
    // The whole module (orders G2 + eMAR G3) never constructs a SafetyAlert — the null-object returns none().
    $files = collect(File::allFiles(base_path('Modules/Pharmacy/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    expect($files)->not->toBeEmpty();
    foreach ($files as $file) {
        expect(str_contains(File::get($file->getPathname()), 'new SafetyAlert('))
            ->toBeFalse("Pharmacy must not manufacture a safety finding (homemade checking) — found in {$file->getRelativePathname()}");
    }
});

test('the MAR is append-only: a correction is a NEW administration and history is preserved', function () {
    $fx = maFixture();
    $first = app(MedicationAdministrationService::class)->record($fx['nurse'], $fx['order'], ['outcome' => 'held', 'reason' => 'typo']);

    // A correction is a NEW row — the original stands.
    app(MedicationAdministrationService::class)->record($fx['nurse'], $fx['order'], ['outcome' => 'given']);
    expect(MedicationAdministration::query()->where('medication_order_id', $fx['order']->id)->count())->toBe(2);

    // Append-only: an administration cannot be edited (model guard) or deleted (DB trigger).
    expect(fn () => $first->update(['reason' => 'edited']))->toThrow(MedicationAdministrationException::class);
    expect(fn () => DB::table('medication_administrations')->where('id', $first->id)->delete())->toThrow(QueryException::class);
});

test('FENCE: the outcome is the nurse\'s fact — no computed safety/verdict/late-flag column, nothing auto-populates, late/missed is a raw time comparison', function () {
    $fx = maFixture();

    // Schema: no computed-judgment / verdict / graded-flag column.
    $cols = Schema::getColumnListing('medication_administrations');
    foreach (['severity', 'score', 'risk', 'grade', 'priority', 'verdict', 'flag', 'late', 'missed', 'overdue', 'safety_flag'] as $word) {
        expect($cols)->not->toContain($word, "medication_administrations must not carry a computed-judgment column: {$word}");
    }
    expect($cols)->toContain('outcome')->toContain('scheduled_at')->toContain('administered_at');

    // Nothing auto-populates the outcome — it is verbatim what the nurse recorded; a scheduled dose late in
    // real time is stored as raw times (scheduled_at + administered_at), NEVER a computed 'late' flag.
    $a = app(MedicationAdministrationService::class)->record($fx['nurse'], $fx['order'], [
        'outcome' => 'given', 'scheduled_at' => Carbon::parse('2026-06-15 08:00:00')->toIso8601String(), 'administered_at' => Carbon::parse('2026-06-15 09:30:00')->toIso8601String(),
    ]);
    expect($a->outcome)->toBe('given'); // verbatim

    // The rendered eMAR payload carries no judgment key, and the alerts area is empty.
    maCtx()->forget();
    $this->actingAs($fx['nurse'])
        ->get('/pharmacy/patients/'.$fx['patient']->id.'/emar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pharmacy/Emar')
            ->where('alerts', [])
            ->where('history', function ($history): bool {
                maAssertNoJudgment((array) $history);

                return true;
            })
            ->where('due', function ($due): bool {
                maAssertNoJudgment((array) $due); // the due worklist carries no priority/acuity/urgency

                return true;
            }));
});

test('the due-dose worklist is FACTUAL: the active orders, never a computed priority; discontinued orders drop off', function () {
    $fx = maFixture();

    // One active order → one due row.
    expect(app(MedicationAdministrationService::class)->dueForPatient($fx['patient'])->pluck('id')->all())
        ->toBe([$fx['order']->id]);

    // Discontinue it → it drops off the due worklist (a factual reflection of order status, not a judgment).
    app(MedicationOrderService::class)->transition($fx['prescriber'], $fx['order'], MedicationOrder::STATUS_DISCONTINUED, 'done');
    expect(app(MedicationAdministrationService::class)->dueForPatient($fx['patient']))->toHaveCount(0);
});

test('the eMAR is RBAC-gated (note.write to administer, patient.view to view), read-logged, and tenant/patient scoped', function () {
    $fx = maFixture();

    // reception holds patient.view but NOT note.write → cannot administer.
    expect(fn () => app(MedicationAdministrationService::class)->record($fx['reception'], $fx['order'], ['outcome' => 'given']))
        ->toThrow(AuthorizationException::class);

    // billing (no patient.view) cannot view; the nurse can (and it read-logs the patient).
    maCtx()->forget();
    $this->actingAs($fx['billing'])->get('/pharmacy/patients/'.$fx['patient']->id.'/emar')->assertForbidden();
    maCtx()->forget();
    $this->actingAs($fx['nurse'])->get('/pharmacy/patients/'.$fx['patient']->id.'/emar')->assertOk();
    maCtx()->set($fx['tenant']);
    expect(AuditEvent::query()->where('resource_id', $fx['patient']->id)->where('action', 'read')->count())->toBeGreaterThanOrEqual(1);

    // reception can view but the administer route is refused at the gate; the nurse can record.
    maCtx()->forget();
    $this->actingAs($fx['reception'])->get('/pharmacy/patients/'.$fx['patient']->id.'/emar')->assertOk();
    maCtx()->forget();
    $this->actingAs($fx['reception'])->post('/pharmacy/medication-orders/'.$fx['order']->id.'/administer', ['outcome' => 'given'])->assertForbidden();
    maCtx()->forget();
    $this->actingAs($fx['nurse'])->post('/pharmacy/medication-orders/'.$fx['order']->id.'/administer', ['outcome' => 'given'])->assertRedirect();
    maCtx()->set($fx['tenant']);
    expect(MedicationAdministration::query()->where('medication_order_id', $fx['order']->id)->count())->toBe(1);

    // cross-tenant: tenant B nurse cannot administer against tenant A's order; A's patient eMAR is 404 to B.
    $fxB = maFixture('beta');
    maCtx()->set($fxB['tenant']);
    expect(fn () => app(MedicationAdministrationService::class)->record($fxB['nurse'], $fx['order'], ['outcome' => 'given']))
        ->toThrow(CrossTenantReferenceException::class);
    maCtx()->forget();
    $this->actingAs($fxB['nurse'])->get('/pharmacy/patients/'.$fx['patient']->id.'/emar')->assertNotFound();
});
