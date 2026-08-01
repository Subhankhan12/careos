<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\Clinical\Contracts\LabConnectivity;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Services\ManualLabConnectivity;
use Modules\Lab\Exceptions\LabOrderException;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\LabTest;
use Modules\Lab\Services\LabCatalogService;
use Modules\Lab\Services\LabOrderService;
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
 * LAB.G2 — lab order entry: a lab order IS a Clinical Order (REUSED via OrderService::place, ~85% reuse), with
 * a thin lab overlay (specimen-type + a RECORDED priority flag, incl STAT). Per docs/HOSPITAL-PHASE3-LAB-MAP.md.
 * FENCE: the priority is the clinician's recorded flag — never computed. Clinical's Order is not duplicated.
 */

function laboCtx(): TenantContext
{
    return app(TenantContext::class);
}

function laboUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, branch: Branch, admin: User, doctor: User, reception: User, patient: Patient, test: LabTest} */
function laboFixture(string $slug = 'labo'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Lab', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    laboCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $admin = laboUser($tenant, 'org_admin');   // lab.catalog (author the test) + everything
    $doctor = laboUser($tenant, 'doctor');      // order.manage (orders labs)
    $reception = laboUser($tenant, 'reception'); // NO order.manage
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $test = app(LabCatalogService::class)->authorTest($admin, 'LAB-K', 'Potassium', 'Blood', 'mmol/L', '3.5–5.1');

    return compact('tenant', 'branch', 'admin', 'doctor', 'reception', 'patient', 'test');
}

test('placing a lab order REUSES the Clinical Order (lifecycle) + appends the thin overlay (specimen + priority); audited', function () {
    $fx = laboFixture();

    $result = app(LabOrderService::class)->place($fx['doctor'], $fx['patient'], $fx['test'], LabOrder::PRIORITY_URGENT, null, null, 'Check K');

    $order = $result['order'];
    $labOrder = $result['labOrder'];

    // The lab order IS a reused Clinical Order — its lifecycle starts at 'ordered'; the orderable is the lab test.
    expect($order)->toBeInstanceOf(Order::class)
        ->and($order->status)->toBe(Order::STATUS_ORDERED)
        ->and($order->orderable_item_id)->toBe($fx['test']->orderable_item_id)
        ->and($order->patient_id)->toBe($fx['patient']->id)
        // The thin overlay records the two lab placement facts.
        ->and($labOrder->order_id)->toBe($order->id)
        ->and($labOrder->specimen_type)->toBe('Blood')       // defaulted from the catalog
        ->and($labOrder->priority)->toBe('urgent')           // the RECORDED flag
        // Audited: Clinical audits the order; the overlay audits lab.order_placed (patient-scoped).
        ->and(AuditEvent::query()->where('action', 'order.placed')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'lab.order_placed')->where('patient_id', $fx['patient']->id)->count())->toBe(1);

    // An explicit specimen overrides the catalog default.
    $r2 = app(LabOrderService::class)->place($fx['doctor'], $fx['patient'], $fx['test'], LabOrder::PRIORITY_ROUTINE, 'Serum');
    expect($r2['labOrder']->specimen_type)->toBe('Serum');
});

test('FENCE: the priority is the clinician RECORDED flag (STAT overlay-only); nothing computes/ranks a priority; the Clinical Order is UNTOUCHED', function () {
    $fx = laboFixture();

    $result = app(LabOrderService::class)->place($fx['doctor'], $fx['patient'], $fx['test'], LabOrder::PRIORITY_STAT);

    // STAT is a RECORDED lab flag on the overlay; Clinical's Order is UNTOUCHED — its priority stays the default
    // routine (Clinical's Order accepts routine/urgent only; STAT lives only on the lab overlay).
    expect($result['labOrder']->priority)->toBe('stat')
        ->and($result['order']->priority)->toBe(Order::PRIORITY_ROUTINE);

    // No computed-priority/urgency column on the overlay — priority is a recorded flag, not a computed score.
    $forbidden = ['urgency_score', 'computed_priority', 'priority_score', 'rank', 'escalation', 'urgency'];
    $columns = Schema::getColumnListing('lab_orders');
    foreach ($forbidden as $word) {
        expect($columns)->not->toContain($word, "lab_orders must not carry a computed-priority column: {$word}");
    }

    // Clinical's `orders` schema was NOT extended with lab fields — the overlay is Lab-side (Clinical untouched).
    $orderCols = Schema::getColumnListing('orders');
    expect($orderCols)->not->toContain('specimen_type')->and($orderCols)->not->toContain('lab_priority');

    // The module computes/ranks no priority — no rank/escalate/compute-priority logic anywhere in Modules\Lab\src.
    $files = collect(File::allFiles(base_path('Modules/Lab/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['computePriority', 'rankByUrgency', 'autoEscalate', 'urgencyScore', 'sortByPriority'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Lab must not compute/rank a priority ({$needle})");
        }
    }
});

test('the LabConnectivity transmit() stays the MANUAL no-op on place — no homemade HL7', function () {
    $fx = laboFixture();

    // The seam is the manual no-op — placing a lab order calls transmit() (inside OrderService::place) and it
    // does nothing (no exception, no network). Lab consumes the Clinical seam; it builds no HL7 client.
    expect(app(LabConnectivity::class))->toBeInstanceOf(ManualLabConnectivity::class);
    expect(fn () => app(LabOrderService::class)->place($fx['doctor'], $fx['patient'], $fx['test'], LabOrder::PRIORITY_ROUTINE))
        ->not->toThrow(Exception::class);

    // No homemade HL7/analyzer client in the lab module (the defining LIS value is partner-gated, LAB.G7).
    $files = collect(File::allFiles(base_path('Modules/Lab/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['implements LabConnectivity', 'FhirClient', 'Hl7Client', 'AnalyzerFeed', 'MllpConnection', 'parseHl7'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Lab must not build a homemade HL7 client ({$needle})");
        }
    }
});

test('the lab-order overlay is APPEND-ONLY (model guard + DB trigger); invalid priority rejected', function () {
    $fx = laboFixture();
    $labOrder = app(LabOrderService::class)->place($fx['doctor'], $fx['patient'], $fx['test'], LabOrder::PRIORITY_ROUTINE)['labOrder'];

    // Model guard (belt).
    expect(fn () => $labOrder->update(['priority' => 'stat']))->toThrow(LabOrderException::class);

    // DB trigger (suspenders) — a raw update/delete bypassing the model still fails.
    expect(fn () => DB::table('lab_orders')->where('id', $labOrder->id)->update(['priority' => 'stat']))->toThrow(QueryException::class);
    expect(fn () => DB::table('lab_orders')->where('id', $labOrder->id)->delete())->toThrow(QueryException::class);

    // An unknown priority is rejected (a recorded flag from a closed set, not a computed value).
    expect(fn () => app(LabOrderService::class)->place($fx['doctor'], $fx['patient'], $fx['test'], 'whenever'))
        ->toThrow(LabOrderException::class);
});

test('RBAC: placing a lab order is order.manage-gated (reused); reception is refused', function () {
    $fx = laboFixture();

    // reception holds no order.manage — the reused OrderService::place refuses it.
    expect(fn () => app(LabOrderService::class)->place($fx['reception'], $fx['patient'], $fx['test'], LabOrder::PRIORITY_ROUTINE))
        ->toThrow(AuthorizationException::class);

    // The doctor (order.manage) can place.
    expect(app(LabOrderService::class)->place($fx['doctor'], $fx['patient'], $fx['test'], LabOrder::PRIORITY_ROUTINE)['order']->status)->toBe(Order::STATUS_ORDERED);
});

test('cross-tenant is fail-closed: a tenant cannot place a lab order on another tenant patient/test', function () {
    $fxA = laboFixture('alpha');

    $fxB = laboFixture('beta');
    laboCtx()->set($fxB['tenant']);

    // Under tenant B, tenant A's patient is invisible — the reused OrderService::place fails closed.
    expect(fn () => app(LabOrderService::class)->place($fxB['doctor'], $fxA['patient'], $fxB['test'], LabOrder::PRIORITY_ROUTINE))
        ->toThrow(CrossTenantReferenceException::class);
});
