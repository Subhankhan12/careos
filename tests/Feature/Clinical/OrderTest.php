<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Contracts\LabConnectivity;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Clinical\Models\OrderResult;
use Modules\Clinical\Services\ManualLabConnectivity;
use Modules\Clinical\Services\OrderableItemService;
use Modules\Clinical\Services\OrderService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function ordCtx(): TenantContext
{
    return app(TenantContext::class);
}

function ordTenant(string $slug): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    ordCtx()->set($tenant);

    return $tenant;
}

function ordUser(Tenant $tenant, string $role = 'doctor'): User
{
    ordCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

function ordPatient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Order',
        'last_name' => 'Patient',
        'date_of_birth' => '1990-05-15',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function ordItem(array $overrides = []): OrderableItem
{
    return OrderableItem::query()->create([
        'category' => OrderableItem::CATEGORY_LAB,
        'code' => 'FBC',
        'name' => 'Full blood count',
        'specimen_or_modality' => 'Blood',
        'active' => true,
        ...$overrides,
    ]);
}

test('place -> collected -> resulted (manual) -> reviewed enforces legal transitions', function () {
    $tenant = ordTenant('alpha');
    $doctor = ordUser($tenant);
    $patient = ordPatient();
    $item = ordItem();
    $service = app(OrderService::class);

    $order = $service->place($patient, null, $item, ['priority' => 'urgent', 'clinical_note' => 'suspect anaemia'], $doctor);
    expect($order->status)->toBe(Order::STATUS_ORDERED)
        ->and($order->priority)->toBe('urgent')
        ->and((int) $order->ordered_by)->toBe($doctor->id);

    // Illegal jump: ordered -> reviewed is not a tracking transition.
    expect(fn () => $service->transition($order, 'reviewed', $doctor))->toThrow(InvalidArgumentException::class);
    // Cannot review before a result exists.
    expect(fn () => $service->markReviewed($order, $doctor))->toThrow(InvalidArgumentException::class);

    $service->transition($order, Order::STATUS_COLLECTED, $doctor);
    expect($order->refresh()->status)->toBe(Order::STATUS_COLLECTED);

    $result = $service->recordResult($order, ['value' => '12.3 g/dL'], $doctor);
    expect($order->refresh()->status)->toBe(Order::STATUS_RESULTED)
        ->and($result->result_value)->toBe('12.3 g/dL')
        ->and($result->source)->toBe(OrderResult::SOURCE_MANUAL);

    // A resulted order can no longer be re-resulted.
    expect(fn () => $service->recordResult($order->refresh(), ['value' => 'x'], $doctor))->toThrow(InvalidArgumentException::class);

    $reviewed = $service->markReviewed($order->refresh(), $doctor);
    expect($reviewed->status)->toBe(Order::STATUS_REVIEWED)
        // "Reviewed" records the HUMAN, not a system judgment.
        ->and((int) $reviewed->reviewed_by)->toBe($doctor->id)
        ->and($reviewed->reviewed_at)->not->toBeNull();
});

test('an order result is append-only at the DB level', function () {
    $tenant = ordTenant('alpha');
    $doctor = ordUser($tenant);
    $patient = ordPatient();
    $result = app(OrderService::class)->recordResult(
        app(OrderService::class)->place($patient, null, ordItem(), [], $doctor),
        ['value' => '5.0'],
        $doctor,
    );

    expect(fn () => DB::table('order_results')->where('id', $result->id)->update(['result_value' => 'tampered']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('order_results')->where('id', $result->id)->delete())
        ->toThrow(QueryException::class);
});

test('results carry NO interpretation fields — stored and shown raw', function () {
    $tenant = ordTenant('alpha');
    $doctor = ordUser($tenant);
    $patient = ordPatient();
    $order = app(OrderService::class)->place($patient, null, ordItem(), [], $doctor);
    app(OrderService::class)->recordResult($order, ['value' => '4.2'], $doctor);

    // The table has NO flag/range/abnormal/score columns.
    $columns = Schema::getColumnListing('order_results');
    $forbidden = ['flag', 'range', 'abnormal', 'normal', 'high', 'low', 'score', 'interpretation', 'reference_range', 'colour', 'color'];
    foreach ($forbidden as $bad) {
        expect(in_array($bad, $columns, true))->toBeFalse("order_results must not have a '{$bad}' column");
    }

    // The chart output for a result is the raw value only — no interpretation keys.
    $chart = app(OrderService::class)->chartOrders($patient, $doctor);
    $resultOut = $chart->first()->results->first();
    // (auditRead ran; nothing derived was added.)
    expect($resultOut->result_value)->toBe('4.2');
});

test('the orderable catalog is tenant-authored and tenant-isolated', function () {
    $alpha = ordTenant('alpha');
    $doctor = ordUser($alpha);
    app(OrderableItemService::class)->create(['category' => 'imaging', 'code' => 'CUSTOM-MRI', 'name' => 'MRI head'], $doctor);
    $seeded = app(OrderableItemService::class)->seedStarter();
    expect($seeded)->toBe(5)
        ->and(OrderableItem::query()->count())->toBe(6); // 1 authored + 5 starter

    // A reception user cannot author items.
    $reception = ordUser($alpha, 'reception');
    expect(fn () => app(OrderableItemService::class)->create(['category' => 'lab', 'code' => 'X', 'name' => 'X'], $reception))
        ->toThrow(AuthorizationException::class);

    // Another tenant sees none of alpha's catalog.
    ordTenant('beta');
    expect(OrderableItem::query()->count())->toBe(0);
});

test('lab connectivity is a Manual no-op interface — no external client, no live ingestion', function () {
    Http::preventStrayRequests();
    Http::fake();
    $tenant = ordTenant('alpha');
    $doctor = ordUser($tenant);

    // The bound implementation is the Manual no-op.
    expect(app(LabConnectivity::class))->toBeInstanceOf(ManualLabConnectivity::class);

    // Placing an order transmits — and that is a no-op: nothing leaves the process.
    app(OrderService::class)->place(ordPatient(), null, ordItem(), [], $doctor);
    Http::assertNothingSent();

    // There is no live ingestion.
    expect(fn () => app(LabConnectivity::class)->ingestResult(['anything']))->toThrow(RuntimeException::class);
});

test('placing an order requires order.manage', function () {
    $tenant = ordTenant('alpha');
    $reception = ordUser($tenant, 'reception'); // no order.manage
    $patient = ordPatient();
    $item = ordItem();

    expect(fn () => app(OrderService::class)->place($patient, null, $item, [], $reception))
        ->toThrow(AuthorizationException::class);

    expect(Order::query()->count())->toBe(0);
});

test('orders are audited, result reads are patient-scoped read-logged, and tenant isolated', function () {
    $alpha = ordTenant('alpha');
    $doctor = ordUser($alpha);
    $patient = ordPatient();
    $order = app(OrderService::class)->place($patient, null, ordItem(), [], $doctor);
    app(OrderService::class)->recordResult($order, ['value' => '7.1'], $doctor);
    app(OrderService::class)->markReviewed($order->refresh(), $doctor);

    foreach (['order.placed', 'order.resulted', 'order.reviewed'] as $action) {
        $rows = DB::select('SELECT * FROM audit_events WHERE tenant_id = ? AND action = ? AND patient_id = ?', [$alpha->id, $action, $patient->id]);
        expect($rows)->toHaveCount(1);
    }

    // Disclosing orders on the chart read-logs both the order and its result.
    app(OrderService::class)->chartOrders($patient, $doctor);
    $orderReads = DB::select("SELECT * FROM audit_events WHERE tenant_id = ? AND action = 'read' AND resource_type = 'order' AND patient_id = ?", [$alpha->id, $patient->id]);
    $resultReads = DB::select("SELECT * FROM audit_events WHERE tenant_id = ? AND action = 'read' AND resource_type = 'order_result' AND patient_id = ?", [$alpha->id, $patient->id]);
    expect($orderReads)->not->toBeEmpty()
        ->and($resultReads)->not->toBeEmpty()
        ->and(app(AuditService::class)->verifyChain($alpha->id)['ok'])->toBeTrue();

    // A second tenant sees no orders.
    ordTenant('beta');
    expect(Order::query()->count())->toBe(0)
        ->and(OrderResult::query()->count())->toBe(0);
});
