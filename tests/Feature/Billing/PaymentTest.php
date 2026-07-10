<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAllocation;
use Modules\Billing\Models\Refund;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PaymentService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function f5Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function f5User(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, catalog: TariffCatalog}
 */
function f5Fixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    f5Ctx()->set($tenant);

    $actor = f5User($tenant);
    $branch = Branch::query()->create([
        'name' => strtoupper(substr($slug, 0, 4)).' Branch',
        'code' => strtoupper(substr($slug, 0, 4)),
        'timezone' => 'Europe/Zurich',
    ]);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Payment',
        'last_name' => 'Patient',
        'date_of_birth' => '1988-01-01',
        'sex' => 'female',
    ]);
    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-generic',
        'name' => 'EU Generic',
        'version' => 1,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'status' => TariffCatalog::STATUS_ACTIVE,
        'rules' => [],
    ]);

    return compact('tenant', 'actor', 'branch', 'patient', 'catalog');
}

/**
 * Issue an invoice whose total_minor equals a chosen amount by using a
 * VAT-free tariff item priced at that amount.
 */
function f5Invoice(array $fixture, int $totalMinor, string $code = 'PAY'): Invoice
{
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $fixture['catalog']->id,
        'code' => $code,
        'description' => 'Payable item '.$code,
        'unit_price_minor' => $totalMinor,
        'vat_rate_bp' => 0,
        'unit' => 'session',
        'requires_service_documentation' => false,
        'active' => true,
    ]);

    $charge = Charge::query()->create([
        'patient_id' => $fixture['patient']->id,
        'branch_id' => $fixture['branch']->id,
        'service_date' => '2026-05-10',
        'tariff_catalog_id' => $fixture['catalog']->id,
        'tariff_item_id' => $item->id,
        'code' => $item->code,
        'description' => $item->description,
        'unit_price_minor' => $item->unit_price_minor,
        'vat_rate_bp' => $item->vat_rate_bp,
        'quantity' => 1,
        'line_total_minor' => $item->unit_price_minor,
        'status' => Charge::STATUS_VALIDATED,
        'created_by' => $fixture['actor']->id,
    ]);

    $service = app(IssueService::class);

    return $service->issue(
        $service->createDraftFromCharges($fixture['patient'], [$charge], $fixture['actor']),
        $fixture['actor'],
    );
}

function f5AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('recording a payment appends a row and derives the full unallocated remainder', function () {
    $fixture = f5Fixture();
    $service = app(PaymentService::class);

    $payment = $service->record(
        amountMinor: 5000,
        method: Payment::METHOD_BANK_TRANSFER,
        actor: $fixture['actor'],
        patient: $fixture['patient'],
        payerReference: 'Payer Ltd',
    );

    expect($payment->amount_minor)->toBe(5000)
        ->and($payment->currency)->toBe('EUR')
        ->and($service->unallocated($payment))->toBe(5000)
        ->and(Payment::query()->whereKey($payment->id)->exists())->toBeTrue()
        ->and(f5AuditRows($fixture['tenant']->id, 'payment.recorded'))->toHaveCount(1);
});

test('allocation drives invoice status through invoice_balances only and never touches the frozen invoice row', function () {
    Storage::fake('local');
    $fixture = f5Fixture();
    $invoice = f5Invoice($fixture, 2270);
    $frozenSnapshot = $invoice->only(['status', 'number', 'subtotal_minor', 'vat_total_minor', 'total_minor', 'open_balance_minor']);
    $service = app(PaymentService::class);

    $payment = $service->record(2270, Payment::METHOD_CARD, $fixture['actor'], $fixture['patient']);

    $service->allocate($payment, $invoice, 1000, $fixture['actor']);

    expect($invoice->balance()->firstOrFail()->status)->toBe(Invoice::STATUS_PARTIALLY_PAID)
        ->and($invoice->balance()->firstOrFail()->open_balance_minor)->toBe(1270)
        ->and($service->openBalance($invoice))->toBe(1270);

    $service->allocate($payment, $invoice, 1270, $fixture['actor']);

    expect($invoice->balance()->firstOrFail()->status)->toBe(Invoice::STATUS_PAID)
        ->and($invoice->balance()->firstOrFail()->open_balance_minor)->toBe(0)
        ->and($service->openBalance($invoice))->toBe(0)
        ->and($service->unallocated($payment))->toBe(0)
        // The frozen legal invoice row is untouched by any allocation.
        ->and($invoice->refresh()->only(['status', 'number', 'subtotal_minor', 'vat_total_minor', 'total_minor', 'open_balance_minor']))->toBe($frozenSnapshot)
        ->and(f5AuditRows($fixture['tenant']->id, 'payment.allocated'))->toHaveCount(2);
});

test('allocation cannot exceed the invoice open balance and the balance never goes negative', function () {
    Storage::fake('local');
    $fixture = f5Fixture();
    $invoice = f5Invoice($fixture, 2270);
    $service = app(PaymentService::class);
    $payment = $service->record(5000, Payment::METHOD_CASH, $fixture['actor']);

    $service->allocate($payment, $invoice, 2270, $fixture['actor']);

    expect(fn () => $service->allocate($payment, $invoice, 1, $fixture['actor']))
        ->toThrow(InvalidArgumentException::class)
        ->and($service->openBalance($invoice))->toBe(0)
        ->and($invoice->balance()->firstOrFail()->open_balance_minor)->toBeGreaterThanOrEqual(0);
});

test('allocation cannot exceed the payment unallocated remainder', function () {
    Storage::fake('local');
    $fixture = f5Fixture();
    $invoiceA = f5Invoice($fixture, 2270, 'A');
    $invoiceB = f5Invoice($fixture, 2270, 'B');
    $service = app(PaymentService::class);
    $payment = $service->record(2270, Payment::METHOD_BANK_TRANSFER, $fixture['actor']);

    $service->allocate($payment, $invoiceA, 2270, $fixture['actor']);

    expect(fn () => $service->allocate($payment, $invoiceB, 1, $fixture['actor']))
        ->toThrow(InvalidArgumentException::class)
        ->and($service->unallocated($payment))->toBe(0)
        ->and($service->openBalance($invoiceB))->toBe(2270);
});

test('an overpayment remainder stays unallocated and visible and can be refunded', function () {
    Storage::fake('local');
    $fixture = f5Fixture();
    $invoice = f5Invoice($fixture, 1000);
    $service = app(PaymentService::class);
    $payment = $service->record(1500, Payment::METHOD_CARD, $fixture['actor']);

    $service->allocate($payment, $invoice, 1000, $fixture['actor']);

    expect($invoice->balance()->firstOrFail()->status)->toBe(Invoice::STATUS_PAID)
        ->and($service->unallocated($payment))->toBe(500);

    $refund = $service->refund($payment, 500, 'Overpayment returned', $fixture['actor']);

    expect($refund->amount_minor)->toBe(500)
        ->and($service->unallocated($payment))->toBe(0);
});

test('reversal restores the payment remainder and the invoice open balance exactly', function () {
    Storage::fake('local');
    $fixture = f5Fixture();
    $invoice = f5Invoice($fixture, 2270);
    $service = app(PaymentService::class);
    $payment = $service->record(2270, Payment::METHOD_BANK_TRANSFER, $fixture['actor']);
    $allocation = $service->allocate($payment, $invoice, 2270, $fixture['actor']);

    expect($invoice->balance()->firstOrFail()->status)->toBe(Invoice::STATUS_PAID);

    $reversal = $service->reverseAllocation($allocation, 'Allocated to the wrong invoice', $fixture['actor']);

    expect($reversal->amount_minor)->toBe(-2270)
        ->and($reversal->reverses_allocation_id)->toBe($allocation->id)
        ->and($service->openBalance($invoice))->toBe(2270)
        ->and($service->unallocated($payment))->toBe(2270)
        ->and($invoice->balance()->firstOrFail()->status)->toBe(Invoice::STATUS_ISSUED)
        ->and($invoice->balance()->firstOrFail()->open_balance_minor)->toBe(2270)
        ->and(f5AuditRows($fixture['tenant']->id, 'payment.allocation_reversed'))->toHaveCount(1);
});

test('reversal requires a reason and an allocation cannot be reversed twice', function () {
    Storage::fake('local');
    $fixture = f5Fixture();
    $invoice = f5Invoice($fixture, 1000);
    $service = app(PaymentService::class);
    $payment = $service->record(1000, Payment::METHOD_CASH, $fixture['actor']);
    $allocation = $service->allocate($payment, $invoice, 1000, $fixture['actor']);

    expect(fn () => $service->reverseAllocation($allocation, '   ', $fixture['actor']))
        ->toThrow(InvalidArgumentException::class);

    $service->reverseAllocation($allocation, 'First reversal', $fixture['actor']);

    expect(fn () => $service->reverseAllocation($allocation, 'Second reversal', $fixture['actor']))
        ->toThrow(InvalidArgumentException::class)
        ->and(PaymentAllocation::query()->where('reverses_allocation_id', $allocation->id)->count())->toBe(1);
});

test('refunds are separate append-only rows that cannot exceed the unallocated remainder', function () {
    $fixture = f5Fixture();
    $service = app(PaymentService::class);
    $payment = $service->record(1000, Payment::METHOD_CARD, $fixture['actor'], $fixture['patient']);

    $refund = $service->refund($payment, 400, 'Partial refund', $fixture['actor']);

    expect($refund->amount_minor)->toBe(400)
        ->and($refund->payment_id)->toBe($payment->id)
        ->and(Refund::query()->where('payment_id', $payment->id)->count())->toBe(1)
        ->and($service->unallocated($payment))->toBe(600)
        ->and(fn () => $service->refund($payment, 601, 'Too much', $fixture['actor']))
        ->toThrow(InvalidArgumentException::class)
        ->and(f5AuditRows($fixture['tenant']->id, 'payment.refunded'))->toHaveCount(1);
});

test('refunding allocated money is blocked until the allocation is reversed', function () {
    Storage::fake('local');
    $fixture = f5Fixture();
    $invoice = f5Invoice($fixture, 1000);
    $service = app(PaymentService::class);
    $payment = $service->record(1000, Payment::METHOD_BANK_TRANSFER, $fixture['actor']);
    $allocation = $service->allocate($payment, $invoice, 1000, $fixture['actor']);

    expect(fn () => $service->refund($payment, 1, 'No unallocated money', $fixture['actor']))
        ->toThrow(InvalidArgumentException::class);

    $service->reverseAllocation($allocation, 'Free the money for refund', $fixture['actor']);

    $refund = $service->refund($payment, 1000, 'Now refundable', $fixture['actor']);

    expect($refund->amount_minor)->toBe(1000)
        ->and($service->unallocated($payment))->toBe(0);
});

test('payments allocations and refunds are append-only at the database level', function () {
    Storage::fake('local');
    $fixture = f5Fixture();
    $invoice = f5Invoice($fixture, 1000);
    $service = app(PaymentService::class);
    $payment = $service->record(1000, Payment::METHOD_CARD, $fixture['actor']);
    $allocation = $service->allocate($payment, $invoice, 400, $fixture['actor']);
    $refund = $service->refund($payment, 300, 'Partial', $fixture['actor']);

    expect(fn () => DB::update('UPDATE payments SET amount_minor = 9999 WHERE id = ?', [$payment->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::delete('DELETE FROM payments WHERE id = ?', [$payment->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::update('UPDATE payment_allocations SET amount_minor = 9999 WHERE id = ?', [$allocation->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::delete('DELETE FROM payment_allocations WHERE id = ?', [$allocation->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::update('UPDATE refunds SET amount_minor = 9999 WHERE id = ?', [$refund->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::delete('DELETE FROM refunds WHERE id = ?', [$refund->id]))
        ->toThrow(QueryException::class);

    // Model-level guards mirror the DB triggers.
    expect(fn () => $payment->forceFill(['amount_minor' => 9999])->save())->toThrow(LogicException::class)
        ->and(fn () => $allocation->forceFill(['amount_minor' => 9999])->save())->toThrow(LogicException::class)
        ->and(fn () => $refund->forceFill(['amount_minor' => 9999])->save())->toThrow(LogicException::class);
});

test('payment flows are RBAC guarded tenant isolated and fail closed without context', function () {
    Storage::fake('local');
    $alpha = f5Fixture('alpha');
    $service = app(PaymentService::class);
    $reception = f5User($alpha['tenant'], 'reception');

    expect(fn () => $service->record(1000, Payment::METHOD_CARD, $reception, $alpha['patient']))
        ->toThrow(AuthorizationException::class);

    $payment = $service->record(1000, Payment::METHOD_CARD, $alpha['actor'], $alpha['patient']);

    expect(app(AuditService::class)->verifyChain($alpha['tenant']->id)['ok'])->toBeTrue();

    f5Fixture('beta');

    expect(Payment::query()->whereKey($payment->id)->exists())->toBeFalse();

    f5Ctx()->set($alpha['tenant']);
    expect(Payment::query()->whereKey($payment->id)->exists())->toBeTrue();

    f5Ctx()->forget();

    expect(fn () => Payment::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => PaymentAllocation::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => Refund::query()->count())->toThrow(TenantContextMissingException::class);
});
