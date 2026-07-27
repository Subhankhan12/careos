<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Pharmacy\Contracts\MedicationSafetyProvider;
use Modules\Pharmacy\Exceptions\MedicationOrderException;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Pharmacy\Models\MedicationOrder;
use Modules\Pharmacy\Models\MedicationOrderEvent;
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
 * PHARMACY.G2 — medication orders. A NET-NEW prescribing entity (dose/route/frequency/PRN) that THREADS the
 * G1 medication-safety SEAM: it CALLS MedicationSafetyProvider::checkOrder at placement (today the
 * null-object → no alerts, never blocks). RECORD-NOT-JUDGE: the order is what the clinician entered — the
 * system computes no dose, suggests no med, ranks nothing, and performs NO homemade safety checking.
 */

function moCtx(): TenantContext
{
    return app(TenantContext::class);
}

function moTenant(string $slug = 'medhosp'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Hospital', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    moCtx()->set($tenant);

    return $tenant;
}

function moUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, prescriber: User, nurse: User, billing: User, patient: Patient, item: FormularyItem} */
function moFixture(string $slug = 'medhosp'): array
{
    $tenant = moTenant($slug);
    $prescriber = moUser($tenant, 'doctor'); // medication.prescribe + patient.view
    $nurse = moUser($tenant, 'nurse');       // patient.view but NOT medication.prescribe
    $billing = moUser($tenant, 'billing');   // NO patient.view
    $patient = app(PatientService::class)->create(['first_name' => 'Ivy', 'last_name' => 'Inpatient', 'date_of_birth' => '1975-03-03', 'sex' => 'female']);
    $item = FormularyItem::query()->create(['code' => 'MED-PARACETAMOL-500', 'name' => 'Paracetamol', 'form' => FormularyItem::FORM_TABLET, 'strength' => '500 mg']);

    return compact('tenant', 'prescriber', 'nurse', 'billing', 'patient', 'item');
}

/** @return array{dose_amount: string, dose_unit: string, route: string, frequency: string} */
function moOrderData(): array
{
    return ['dose_amount' => '500', 'dose_unit' => 'mg', 'route' => MedicationOrder::ROUTE_PO, 'frequency' => 'QID'];
}

function moAssertNoJudgment(array $data): void
{
    // dose/route/frequency/status/prn are the clinician's operational entries — not judgments.
    $forbidden = ['acuity', 'severity', 'score', 'risk', 'grade', 'priority', 'deterioration', 'flag',
        'abnormal', 'triage', 'rating', 'verdict', 'suggested', 'recommended', 'ranked', 'interaction_severity'];
    foreach ($data as $key => $value) {
        expect(in_array((string) $key, $forbidden, true))->toBeFalse("interpretation key '{$key}' leaked into the medication-order payload");
        if (is_array($value)) {
            moAssertNoJudgment($value);
        }
    }
}

test('a clinician places a NET-NEW medication order (dose/route/frequency/PRN, patient + optional stay), audited', function () {
    $fx = moFixture();
    $stayId = (string) Str::ulid(); // a SOFT inpatient-stay reference (no FK)

    $order = app(MedicationOrderService::class)->prescribe($fx['prescriber'], $fx['patient'], $fx['item'], [
        ...moOrderData(), 'prn' => true, 'prn_reason' => 'for pain', 'note' => 'post-op',
    ], $stayId);

    expect($order)->toBeInstanceOf(MedicationOrder::class)
        ->and($order->tenant_id)->toBe($fx['tenant']->id)
        ->and($order->patient_id)->toBe($fx['patient']->id)
        ->and($order->prescribed_by)->toBe($fx['prescriber']->id)
        ->and($order->formulary_item_id)->toBe($fx['item']->id)
        ->and($order->stay_id)->toBe($stayId)          // inpatient soft-link
        ->and($order->dose_amount)->toBe('500')        // verbatim — the clinician's entry
        ->and($order->dose_unit)->toBe('mg')
        ->and($order->route)->toBe('PO')
        ->and($order->frequency)->toBe('QID')
        ->and($order->prn)->toBeTrue()
        ->and($order->status)->toBe(MedicationOrder::STATUS_ACTIVE);

    // A 'placed' event + a patient-scoped audit row; chain intact.
    expect(MedicationOrderEvent::query()->where('medication_order_id', $order->id)->where('event_type', 'placed')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'medication_order.placed')->where('patient_id', $fx['patient']->id)->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();

    // Outpatient: a null stay_id is fine.
    $outpatient = app(MedicationOrderService::class)->prescribe($fx['prescriber'], $fx['patient'], $fx['item'], moOrderData());
    expect($outpatient->stay_id)->toBeNull();
});

test('the medication-safety SEAM is CALLED at placement, is advisory + human-owned, and NEVER blocks the order', function () {
    $fx = moFixture();

    // A partner-engine SPY that returns an alert — proving (a) the seam is CALLED at placement, and (b) even
    // WITH an alert the order is NOT blocked (advisory + human-owned; CareOS auto-acts on nothing).
    $spy = new class implements MedicationSafetyProvider
    {
        public bool $orderChecked = false;

        public function checkOrder(SafetyContext $context): SafetyResult
        {
            $this->orderChecked = true;

            return new SafetyResult([new SafetyAlert('PARTNER-INT-001', 'advisory interaction', 'certified-partner')]);
        }

        public function checkAdministration(SafetyContext $context): SafetyResult
        {
            return SafetyResult::none();
        }
    };
    app()->instance(MedicationSafetyProvider::class, $spy);

    $order = app(MedicationOrderService::class)->prescribe($fx['prescriber'], $fx['patient'], $fx['item'], moOrderData());

    expect($spy->orderChecked)->toBeTrue()                                             // the seam was CALLED
        ->and(MedicationOrder::query()->whereKey($order->id)->exists())->toBeTrue();   // NOT blocked despite the alert

    // With the shipped default (the G1 null-object), the review asserts nothing — no alerts.
    app()->forgetInstance(MedicationSafetyProvider::class);
    expect(app(MedicationOrderService::class)->safetyReview($fx['patient'])->hasAlerts())->toBeFalse();
});

test('NO homemade checking logic: CareOS never manufactures a safety finding (the crux)', function () {
    // A homemade interaction/dose/contraindication checker would CONSTRUCT SafetyAlerts. CareOS ships only
    // the null-object (returns none()); no file in the module ever `new SafetyAlert(...)`s. (Partners do,
    // on their side of the seam.)
    $files = collect(File::allFiles(base_path('Modules/Pharmacy/src')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php');

    expect($files)->not->toBeEmpty();
    foreach ($files as $file) {
        expect(str_contains(File::get($file->getPathname()), 'new SafetyAlert('))
            ->toBeFalse("Pharmacy must not manufacture a safety finding (homemade checking) — found in {$file->getRelativePathname()}");
    }
});

test('a medication order is append-only in its history and legal-only in its lifecycle', function () {
    $fx = moFixture();
    $order = app(MedicationOrderService::class)->prescribe($fx['prescriber'], $fx['patient'], $fx['item'], moOrderData());

    // Legal transitions: active -> held -> active (resume) -> discontinued; each an append-only event.
    app(MedicationOrderService::class)->transition($fx['prescriber'], $order, MedicationOrder::STATUS_HELD, 'awaiting review');
    app(MedicationOrderService::class)->transition($fx['prescriber'], $order->fresh(), MedicationOrder::STATUS_ACTIVE);
    app(MedicationOrderService::class)->transition($fx['prescriber'], $order->fresh(), MedicationOrder::STATUS_DISCONTINUED, 'course complete');

    expect($order->fresh()->status)->toBe(MedicationOrder::STATUS_DISCONTINUED)
        ->and(MedicationOrderEvent::query()->where('medication_order_id', $order->id)->count())->toBe(4); // placed, held, resumed, discontinued

    // Illegal transition: a discontinued order is terminal.
    expect(fn () => app(MedicationOrderService::class)->transition($fx['prescriber'], $order->fresh(), MedicationOrder::STATUS_ACTIVE))
        ->toThrow(MedicationOrderException::class);

    // Append-only history: an event cannot be edited (model guard) or deleted (DB trigger).
    $event = MedicationOrderEvent::query()->where('medication_order_id', $order->id)->firstOrFail();
    expect(fn () => $event->update(['reason' => 'edited']))->toThrow(MedicationOrderException::class);
    expect(fn () => DB::table('medication_order_events')->where('id', $event->id)->delete())->toThrow(QueryException::class);
});

test('FENCE: the order is clinician-authored — no computed dose/suggestion/safety-verdict column, nothing auto-populates, no judgment in the payload', function () {
    $fx = moFixture();

    // Schema: no computed-judgment/safety-verdict column.
    $cols = Schema::getColumnListing('medication_orders');
    foreach (['severity', 'score', 'risk', 'grade', 'priority', 'verdict', 'suggested', 'recommended', 'interaction', 'safety_flag', 'computed_dose'] as $word) {
        expect($cols)->not->toContain($word, "medication_orders must not carry a computed-judgment column: {$word}");
    }
    expect($cols)->toContain('dose_amount')->toContain('route')->toContain('frequency');

    // Nothing auto-populates: a fresh patient has zero orders; a placed order is verbatim.
    expect(MedicationOrder::query()->where('patient_id', $fx['patient']->id)->count())->toBe(0);
    app(MedicationOrderService::class)->prescribe($fx['prescriber'], $fx['patient'], $fx['item'], ['dose_amount' => 'exactly-what-the-clinician-typed', 'dose_unit' => 'mg', 'route' => 'IV', 'frequency' => 'BID']);

    // The rendered payload carries no judgment key, and the alerts area is empty (no computed alert).
    moCtx()->forget();
    $this->actingAs($fx['prescriber'])
        ->get('/pharmacy/patients/'.$fx['patient']->id.'/medications')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pharmacy/MedicationOrders')
            ->where('alerts', [])                 // the seam asserts nothing today
            ->where('active.0.dose', 'exactly-what-the-clinician-typed mg')
            ->where('active', function ($active): bool {
                moAssertNoJudgment((array) $active);

                return true;
            }));
});

test('medication orders are RBAC-gated (medication.prescribe to order, patient.view to view) and read-logged', function () {
    $fx = moFixture();

    // nurse holds patient.view but NOT medication.prescribe → cannot prescribe.
    expect(fn () => app(MedicationOrderService::class)->prescribe($fx['nurse'], $fx['patient'], $fx['item'], moOrderData()))
        ->toThrow(AuthorizationException::class);

    // billing (no patient.view) cannot even view; the prescriber can (and it read-logs the patient).
    moCtx()->forget();
    $this->actingAs($fx['billing'])->get('/pharmacy/patients/'.$fx['patient']->id.'/medications')->assertForbidden();
    moCtx()->forget();
    $this->actingAs($fx['prescriber'])->get('/pharmacy/patients/'.$fx['patient']->id.'/medications')->assertOk();
    moCtx()->set($fx['tenant']);
    expect(AuditEvent::query()->where('resource_id', $fx['patient']->id)->where('action', 'read')->count())->toBeGreaterThanOrEqual(1);

    // nurse can view but the prescribe route is refused at the gate.
    moCtx()->forget();
    $this->actingAs($fx['nurse'])->get('/pharmacy/patients/'.$fx['patient']->id.'/medications')->assertOk();
    moCtx()->forget();
    $this->actingAs($fx['nurse'])->post('/pharmacy/patients/'.$fx['patient']->id.'/medications', moOrderData() + ['formulary_item_id' => $fx['item']->id])->assertForbidden();

    // the prescriber can place through the real stack.
    moCtx()->forget();
    $this->actingAs($fx['prescriber'])->post('/pharmacy/patients/'.$fx['patient']->id.'/medications', moOrderData() + ['formulary_item_id' => $fx['item']->id])->assertRedirect();
    moCtx()->set($fx['tenant']);
    expect(MedicationOrder::query()->where('patient_id', $fx['patient']->id)->count())->toBe(1);
});

test('medication orders are tenant + patient scoped and fail closed on a cross-tenant patient', function () {
    $fxA = moFixture('alpha');
    $fxB = moFixture('beta');

    // tenant B prescriber prescribing for tenant A's patient is rejected (cross-tenant), fail closed.
    app(TenantContext::class)->set($fxB['tenant']);
    expect(fn () => app(MedicationOrderService::class)->prescribe($fxB['prescriber'], $fxA['patient'], $fxB['item'], moOrderData()))
        ->toThrow(CrossTenantReferenceException::class);

    // tenant A's patient is invisible to tenant B through the real stack.
    moCtx()->forget();
    $this->actingAs($fxB['prescriber'])->get('/pharmacy/patients/'.$fxA['patient']->id.'/medications')->assertNotFound();
});
