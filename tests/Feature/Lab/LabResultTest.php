<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderResult;
use Modules\Lab\Exceptions\LabResultException;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\LabResult;
use Modules\Lab\Models\Specimen;
use Modules\Lab\Services\LabCatalogService;
use Modules\Lab\Services\LabOrderService;
use Modules\Lab\Services\LabResultService;
use Modules\Lab\Services\SpecimenService;
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
 * LAB.G4 — manual result entry + reference-range display: THE FENCE GATE. A lab result IS a Clinical
 * `OrderResult` (REUSED via OrderService::recordResult — append-only, raw, source=manual), linked to the LAB.G3
 * specimen that produced it; the reference range is DISPLAYED reference data beside the raw value. Per
 * docs/HOSPITAL-PHASE3-LAB-MAP.md §2.5/§4. THE FENCE (the sharpest in lab): the system computes NO
 * abnormal/high/low/critical flag, no delta-check, no interpretation — the value + range are FACTS the clinician
 * reads.
 */

function resCtx(): TenantContext
{
    return app(TenantContext::class);
}

function resUser(Tenant $tenant, string $role): User
{
    // twoFactorEnabled so the mandatory-MFA middleware lets HTTP requests reach the route/gate.
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, admin: User, labTech: User, phlebotomist: User, reception: User, patient: Patient, labOrder: LabOrder, specimen: Specimen} */
function resFixture(string $slug = 'res'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Lab', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    resCtx()->set($tenant);

    Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $admin = resUser($tenant, 'org_admin');        // setup: author test, place order
    $labTech = resUser($tenant, 'lab_tech');       // order.manage + lab.result — enters results (reuses the Clinical path)
    $phlebotomist = resUser($tenant, 'phlebotomist'); // lab.result but NO order.manage — cannot drive the reused result path
    $reception = resUser($tenant, 'reception');    // no lab.result
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $test = app(LabCatalogService::class)->authorTest($admin, 'LAB-K', 'Potassium', 'Blood', 'mmol/L', '3.5–5.1');
    $labOrder = app(LabOrderService::class)->place($admin, $patient, $test, LabOrder::PRIORITY_ROUTINE)['labOrder'];
    $specimen = app(SpecimenService::class)->collect($admin, $labOrder); // status=collected

    return compact('tenant', 'admin', 'labTech', 'phlebotomist', 'reception', 'patient', 'labOrder', 'specimen');
}

test('a manual result REUSES the Clinical OrderResult (raw, source=manual), links the specimen, and advances specimen + Order to resulted', function () {
    $fx = resFixture();

    $res = app(LabResultService::class)->record($fx['labTech'], $fx['specimen'], ['value' => '4.2']);
    $result = $res['result'];
    $labResult = $res['labResult'];

    // The result IS a Clinical OrderResult — raw value, source=manual, patient-scoped. No parallel entity.
    expect($result)->toBeInstanceOf(OrderResult::class)
        ->and($result->result_value)->toBe('4.2')
        ->and($result->source)->toBe(OrderResult::SOURCE_MANUAL)
        ->and($result->patient_id)->toBe($fx['patient']->id);

    // The thin overlay links the reused OrderResult to the specimen that produced it (carries NO value).
    expect($labResult)->toBeInstanceOf(LabResult::class)
        ->and($labResult->order_result_id)->toBe($result->id)
        ->and($labResult->specimen_id)->toBe($fx['specimen']->id)
        ->and(Schema::hasColumn('lab_results', 'result_value'))->toBeFalse(); // no value on the overlay

    // The specimen advanced through the G3 legal machine (collected → in_lab → resulted).
    expect($fx['specimen']->fresh()->status)->toBe(Specimen::STATUS_RESULTED);

    // The reused Clinical Order advanced to resulted (its lifecycle is Clinical's — the result step moves it).
    $order = Order::query()->findOrFail($fx['labOrder']->order_id);
    expect($order->status)->toBe(Order::STATUS_RESULTED);

    // Audited: the lab overlay (patient-scoped) + the reused Clinical order.resulted + the specimen.resulted event.
    expect(AuditEvent::query()->where('action', 'lab.result_recorded')->where('patient_id', $fx['patient']->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'order.resulted')->where('patient_id', $fx['patient']->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'specimen.resulted')->where('patient_id', $fx['patient']->id)->count())->toBe(1);

    // Read-logged (patient-scoped) — the reused OrderResult supports read logging.
    $result->auditRead();
    expect(AuditEvent::query()->where('action', 'read')->where('resource_type', 'order_result')->where('patient_id', $fx['patient']->id)->count())->toBe(1);
});

test('THE FENCE: the payload carries the RAW value + the DISPLAYED reference range but NO computed abnormal/high/low/critical/flag/delta/interpretation field', function () {
    $fx = resFixture();
    app(LabResultService::class)->record($fx['labTech'], $fx['specimen'], ['value' => '4.2']);

    $response = $this->actingAs($fx['labTech'])->get('/lab/orders/'.$fx['labOrder']->id.'/results');
    $response->assertOk();
    $props = $response->viewData('page')['props'];

    // The range is DISPLAYED reference data beside the raw value — both facts, present in the payload.
    expect($props['reference']['reference_range'])->toBe('3.5–5.1')
        ->and($props['reference']['unit'])->toBe('mmol/L')
        ->and($props['results'][0]['result_value'])->toBe('4.2');

    // THE PROOF: no key anywhere in the payload is a computed judgment (abnormal/high/low/critical/flag/etc).
    $keys = [];
    $walk = function ($node) use (&$keys, &$walk): void {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                if (is_string($k)) {
                    $keys[] = strtolower($k);
                }
                $walk($v);
            }
        }
    };
    $walk($props);

    foreach (['abnormal', 'flag', 'critical', 'interpret', 'delta', 'grade', 'verdict', 'severity', '_high', '_low', 'is_high', 'is_low'] as $forbidden) {
        foreach ($keys as $key) {
            expect(str_contains($key, $forbidden))->toBeFalse("The result payload must not carry a computed-judgment key ({$forbidden}): saw '{$key}'");
        }
    }

    // Neither the reused OrderResult nor the lab overlay carries an interpretation/flag column.
    foreach (['abnormal', 'abnormal_flag', 'flag', 'is_abnormal', 'critical', 'high', 'low', 'interpretation', 'delta', 'grade', 'severity'] as $col) {
        expect(Schema::hasColumn('order_results', $col))->toBeFalse("order_results must stay raw ({$col})");
        expect(Schema::hasColumn('lab_results', $col))->toBeFalse("lab_results must not compute a judgment ({$col})");
    }

    // The module computes nothing — no grade/flag-a-result logic anywhere in Modules\Lab\src.
    $files = collect(File::allFiles(base_path('Modules/Lab/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['gradeResult', 'flagAbnormal', 'isAbnormal', 'isCritical', 'computeFlag', 'deltaCheck', 'interpretValue'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Lab must not compute a result verdict ({$needle})");
        }
    }
});

test('the reference range is DISPLAYED reference data, never GRADED — an out-of-range value carries NO computed flag', function () {
    $fx = resFixture();

    // A value well ABOVE the recorded range (3.5–5.1). The system stores it RAW and grades NOTHING.
    $res = app(LabResultService::class)->record($fx['labTech'], $fx['specimen'], ['value' => '9.9']);

    // The raw value is stored verbatim; no interpretation attribute is set on the reused OrderResult.
    expect($res['result']->result_value)->toBe('9.9');
    foreach (array_keys($res['result']->getAttributes()) as $attr) {
        foreach (['abnormal', 'flag', 'critical', 'high', 'low', 'interpret', 'delta', 'grade'] as $bad) {
            expect(str_contains(strtolower($attr), $bad))->toBeFalse("OrderResult must carry no computed-judgment attribute ({$attr})");
        }
    }

    // The payload shows the out-of-range value + the range side by side — still no flag.
    $props = $this->actingAs($fx['labTech'])->get('/lab/orders/'.$fx['labOrder']->id.'/results')->viewData('page')['props'];
    expect($props['results'][0]['result_value'])->toBe('9.9')
        ->and($props['reference']['reference_range'])->toBe('3.5–5.1');
});

test('results are APPEND-ONLY: a correction is a NEW OrderResult, never a mutation (model guard + DB triggers)', function () {
    $fx = resFixture();
    $res = app(LabResultService::class)->record($fx['labTech'], $fx['specimen'], ['value' => '4.2']);
    $labResult = $res['labResult'];
    $result = $res['result'];

    // The lab overlay: model guard (belt).
    expect(fn () => $labResult->update(['specimen_id' => 'tampered']))->toThrow(LabResultException::class);

    // The lab overlay: DB trigger (suspenders).
    expect(fn () => DB::table('lab_results')->where('id', $labResult->id)->update(['specimen_id' => 'x']))->toThrow(QueryException::class);
    expect(fn () => DB::table('lab_results')->where('id', $labResult->id)->delete())->toThrow(QueryException::class);

    // The reused OrderResult itself is immutable — a correction is a NEW row, never an edit (the existing trigger).
    expect(fn () => DB::table('order_results')->where('id', $result->id)->update(['result_value' => '5.5']))->toThrow(QueryException::class);
    expect(fn () => DB::table('order_results')->where('id', $result->id)->delete())->toThrow(QueryException::class);
});

test('RBAC: entering a result is lab.result-gated (and reuses order.manage) — reception + a lab.result-only phlebotomist are refused', function () {
    $fx = resFixture();
    $svc = app(LabResultService::class);

    // reception holds no lab.result — refused at the lab-domain gate.
    expect(fn () => $svc->record($fx['reception'], $fx['specimen'], ['value' => '4.2']))->toThrow(AuthorizationException::class);

    // The phlebotomist has lab.result but NOT order.manage — the reused Clinical result path (order.manage) refuses.
    expect(fn () => $svc->record($fx['phlebotomist'], $fx['specimen'], ['value' => '4.2']))->toThrow(AuthorizationException::class);

    // The lab tech holds BOTH (order.manage + lab.result) — records successfully.
    expect($svc->record($fx['labTech'], $fx['specimen'], ['value' => '4.2'])['result']->result_value)->toBe('4.2');
});

test('a specimen that is already resulted cannot be resulted again (terminal)', function () {
    $fx = resFixture();
    $svc = app(LabResultService::class);

    $svc->record($fx['labTech'], $fx['specimen'], ['value' => '4.2']); // specimen → resulted
    expect(fn () => $svc->record($fx['labTech'], $fx['specimen']->fresh(), ['value' => '4.3']))
        ->toThrow(LabResultException::class);
});

test('cross-tenant is fail-closed: a tenant cannot record a result against another tenant specimen', function () {
    $fxA = resFixture('alpha');

    $fxB = resFixture('beta');
    resCtx()->set($fxB['tenant']);

    expect(fn () => app(LabResultService::class)->record($fxB['labTech'], $fxA['specimen'], ['value' => '4.2']))
        ->toThrow(CrossTenantReferenceException::class);
});
