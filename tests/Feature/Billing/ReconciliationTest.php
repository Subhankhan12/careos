<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\ReconciliationRun;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\AccountingExportService;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PaymentService;
use Modules\Billing\Services\ReconciliationEngine;
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

const F7_PERIOD = '2026-06';

function f7Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function f7User(Tenant $tenant, string $role = 'billing'): User
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
function f7Fixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    f7Ctx()->set($tenant);

    $actor = f7User($tenant);
    $branch = Branch::query()->create([
        'name' => strtoupper(substr($slug, 0, 4)).' Branch',
        'code' => strtoupper(substr($slug, 0, 4)),
        'timezone' => 'Europe/Zurich',
    ]);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Recon',
        'last_name' => 'Patient',
        'date_of_birth' => '1988-01-01',
        'sex' => 'female',
    ]);
    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-generic',
        'name' => 'EU Generic',
        'version' => 1,
        'valid_from' => '2026-01-01',
        'status' => TariffCatalog::STATUS_ACTIVE,
        'rules' => [],
    ]);

    return compact('tenant', 'actor', 'branch', 'patient', 'catalog');
}

function f7Item(array $fx, string $code, int $price, int $vatBp): TariffItem
{
    return TariffItem::query()->create([
        'tariff_catalog_id' => $fx['catalog']->id,
        'code' => $code,
        'description' => 'Item '.$code,
        'unit_price_minor' => $price,
        'vat_rate_bp' => $vatBp,
        'unit' => 'session',
        'requires_service_documentation' => false,
        'active' => true,
    ]);
}

function f7Charge(array $fx, TariffItem $item, int $qty = 1): Charge
{
    return Charge::query()->create([
        'patient_id' => $fx['patient']->id,
        'branch_id' => $fx['branch']->id,
        'service_date' => '2026-06-01',
        'tariff_catalog_id' => $fx['catalog']->id,
        'tariff_item_id' => $item->id,
        'code' => $item->code,
        'description' => $item->description,
        'unit_price_minor' => $item->unit_price_minor,
        'vat_rate_bp' => $item->vat_rate_bp,
        'quantity' => $qty,
        'line_total_minor' => $qty * $item->unit_price_minor,
        'status' => Charge::STATUS_VALIDATED,
        'created_by' => $fx['actor']->id,
    ]);
}

/**
 * @param  list<Charge>  $charges
 */
function f7Issue(array $fx, array $charges): Invoice
{
    $service = app(IssueService::class);

    return $service->issue(
        $service->createDraftFromCharges(
            $fx['patient'],
            $charges,
            $fx['actor'],
            Invoice::PAYER_SELF_PAY,
            null,
            Carbon::parse('2026-06-05'),
            Carbon::parse('2026-06-19'),
        ),
        $fx['actor'],
    );
}

/**
 * Insert a row directly, bypassing model guards, for controlled corruption.
 *
 * @param  array<string, mixed>  $cols
 */
function f7Raw(string $table, array $cols): string
{
    $id = (string) Str::ulid();
    DB::table($table)->insert(array_merge([
        'id' => $id,
        'tenant_id' => f7Ctx()->id(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $cols));

    return $id;
}

/**
 * @return array<string, mixed>
 */
function f7Invariant(array $report, string $key): array
{
    foreach ($report['invariants'] as $invariant) {
        if ($invariant['invariant'] === $key) {
            return $invariant;
        }
    }

    throw new RuntimeException("Invariant {$key} missing from report.");
}

function f7Check(): array
{
    return app(ReconciliationEngine::class)->check(F7_PERIOD);
}

function f7AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

// ----------------------------------------------------------------------------
// I1 — issued total == sum(line totals) + sum(per-line VAT) [D-F3]
// ----------------------------------------------------------------------------

test('I1 clean: multi-rate issued invoice totals reconcile', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    $a = f7Charge($fx, f7Item($fx, 'VAT-810', 1000, 810));
    $b = f7Charge($fx, f7Item($fx, 'VAT-1900', 333, 1900), 3);
    f7Issue($fx, [$a, $b]);

    $report = f7Check();

    expect(f7Invariant($report, 'I1')['ok'])->toBeTrue()
        ->and(f7Invariant($report, 'I1')['delta_minor'])->toBe(0)
        ->and($report['passed'])->toBeTrue();
});

test('I1 violation: a line total that does not sum to the invoice total is caught', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    $item = f7Item($fx, 'RAW1', 1000, 0);
    $invId = f7Raw('invoices', [
        'patient_id' => $fx['patient']->id, 'payer_type' => 'self_pay', 'series' => 'INV',
        'status' => 'issued', 'currency' => 'EUR', 'number' => '900', 'issue_date' => '2026-06-05',
        'subtotal_minor' => 1000, 'vat_total_minor' => 0, 'total_minor' => 1000, 'open_balance_minor' => 1000,
    ]);
    $chargeId = f7Raw('charges', [
        'patient_id' => $fx['patient']->id, 'branch_id' => $fx['branch']->id, 'service_date' => '2026-06-01',
        'tariff_catalog_id' => $fx['catalog']->id, 'tariff_item_id' => $item->id, 'code' => 'RAW1', 'description' => 'Raw',
        'unit_price_minor' => 1000, 'vat_rate_bp' => 0, 'quantity' => 1, 'line_total_minor' => 1000,
        'status' => 'invoiced', 'invoice_id' => $invId, 'created_by' => $fx['actor']->id,
    ]);
    f7Raw('invoice_lines', [
        'invoice_id' => $invId, 'charge_id' => $chargeId, 'code' => 'RAW1', 'description' => 'Raw',
        'quantity' => 1, 'unit_price_minor' => 1000, 'vat_rate_bp' => 0, 'line_total_minor' => 1001, 'line_vat_minor' => 0,
    ]);
    f7Raw('invoice_balances', ['invoice_id' => $invId, 'status' => 'issued', 'open_balance_minor' => 1000, 'dunning_paused' => false]);

    $i1 = f7Invariant(f7Check(), 'I1');

    expect($i1['ok'])->toBeFalse()
        ->and($i1['delta_minor'])->not->toBe(0)
        ->and(collect($i1['rows'])->pluck('id')->all())->toContain($invId)
        ->and(collect($i1['rows'])->firstWhere('id', $invId)['delta_minor'])->toBe(-1);
});

// ----------------------------------------------------------------------------
// I2 — projection equals derived open balance
// ----------------------------------------------------------------------------

test('I2 clean: partially paid invoice projection equals derivation', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    $invoice = f7Issue($fx, [f7Charge($fx, f7Item($fx, 'PAY', 2000, 0))]);
    $payment = app(PaymentService::class)->record(800, Payment::METHOD_CARD, $fx['actor'], null, null, null, '2026-06-10');
    app(PaymentService::class)->allocate($payment, $invoice, 800, $fx['actor']);

    $report = f7Check();

    expect(f7Invariant($report, 'I2')['ok'])->toBeTrue()
        ->and($report['passed'])->toBeTrue()
        ->and($invoice->balance()->firstOrFail()->open_balance_minor)->toBe(1200);
});

test('I2 violation: a drifted projection row is caught', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    $invoice = f7Issue($fx, [f7Charge($fx, f7Item($fx, 'DRIFT', 1000, 0))]);
    // Corrupt the mutable projection directly (no payment backs the change).
    $invoice->balance()->firstOrFail()->forceFill(['open_balance_minor' => 970])->save();

    $i2 = f7Invariant(f7Check(), 'I2');

    expect($i2['ok'])->toBeFalse()
        ->and($i2['delta_minor'])->toBe(-30)
        ->and(collect($i2['rows'])->firstWhere('id', $invoice->id)['expected_minor'])->toBe(1000)
        ->and(collect($i2['rows'])->firstWhere('id', $invoice->id)['actual_minor'])->toBe(970);
});

// ----------------------------------------------------------------------------
// I3 — payment amount == net allocated + refunded + remainder (>= 0)
// ----------------------------------------------------------------------------

test('I3 clean: a fully allocated payment reconciles', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    $invoice = f7Issue($fx, [f7Charge($fx, f7Item($fx, 'FULL', 1500, 0))]);
    $payment = app(PaymentService::class)->record(1500, Payment::METHOD_BANK_TRANSFER, $fx['actor'], null, null, null, '2026-06-10');
    app(PaymentService::class)->allocate($payment, $invoice, 1500, $fx['actor']);

    $report = f7Check();

    expect(f7Invariant($report, 'I3')['ok'])->toBeTrue()
        ->and($report['passed'])->toBeTrue();
});

test('I3 violation: a payment committed beyond its amount is caught', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    $invoice = f7Issue($fx, [f7Charge($fx, f7Item($fx, 'OVER', 2000, 0))]);
    $payment = app(PaymentService::class)->record(1000, Payment::METHOD_CARD, $fx['actor'], null, null, null, '2026-06-10');
    // Raw-insert an allocation of 1500 from a 1000 payment; keep the invoice
    // projection consistent so ONLY I3 fails.
    f7Raw('payment_allocations', [
        'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_minor' => 1500,
        'reverses_allocation_id' => null, 'reason' => null, 'allocated_by' => $fx['actor']->id,
        'allocated_at' => '2026-06-11 10:00:00',
    ]);
    $invoice->balance()->firstOrFail()->forceFill(['open_balance_minor' => 500, 'status' => Invoice::STATUS_PARTIALLY_PAID])->save();

    $i3 = f7Invariant(f7Check(), 'I3');

    expect($i3['ok'])->toBeFalse()
        ->and(collect($i3['rows'])->firstWhere('id', $payment->id)['remainder_minor'])->toBe(-500)
        ->and(collect($i3['rows'])->firstWhere('id', $payment->id)['delta_minor'])->toBe(-500)
        ->and(f7Invariant(f7Check(), 'I2')['ok'])->toBeTrue();
});

// ----------------------------------------------------------------------------
// I4 — period invoice totals == invoiced charge totals; charge on exactly one
// ----------------------------------------------------------------------------

test('I4 clean: issued invoice totals equal invoiced charge totals', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    f7Issue($fx, [f7Charge($fx, f7Item($fx, 'C1', 1000, 810)), f7Charge($fx, f7Item($fx, 'C2', 500, 0))]);

    $report = f7Check();

    expect(f7Invariant($report, 'I4')['ok'])->toBeTrue()
        ->and(f7Invariant($report, 'I4')['delta_minor'])->toBe(0)
        ->and($report['passed'])->toBeTrue();
});

test('I4 violation: a charge invoiced on two invoices is caught', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    $charge = f7Charge($fx, f7Item($fx, 'DBL', 1000, 0));
    $invoiceA = f7Issue($fx, [$charge]);
    // Raw second invoice whose line references the SAME charge (double-invoiced).
    $invB = f7Raw('invoices', [
        'patient_id' => $fx['patient']->id, 'payer_type' => 'self_pay', 'series' => 'INV',
        'status' => 'issued', 'currency' => 'EUR', 'number' => '901', 'issue_date' => '2026-06-05',
        'subtotal_minor' => 1000, 'vat_total_minor' => 0, 'total_minor' => 1000, 'open_balance_minor' => 1000,
    ]);
    f7Raw('invoice_lines', [
        'invoice_id' => $invB, 'charge_id' => $charge->id, 'code' => 'DBL', 'description' => 'Dup',
        'quantity' => 1, 'unit_price_minor' => 1000, 'vat_rate_bp' => 0, 'line_total_minor' => 1000, 'line_vat_minor' => 0,
    ]);
    f7Raw('invoice_balances', ['invoice_id' => $invB, 'status' => 'issued', 'open_balance_minor' => 1000, 'dunning_paused' => false]);

    $i4 = f7Invariant(f7Check(), 'I4');

    expect($i4['ok'])->toBeFalse()
        ->and($i4['delta_minor'])->toBe(-1000)
        ->and(collect($i4['rows'])->firstWhere('id', $charge->id)['reason'])->toBe('double_invoiced')
        ->and(collect($i4['rows'])->firstWhere('id', $charge->id)['non_credit_note_line_count'])->toBe(2);
});

// ----------------------------------------------------------------------------
// I5 — credit notes reference a real original and never exceed it
// ----------------------------------------------------------------------------

test('I5 clean: a full credit note reconciles against its original', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    $invoice = f7Issue($fx, [f7Charge($fx, f7Item($fx, 'CRED', 1000, 0))]);
    app(IssueService::class)->creditNote($invoice, null, 'Full correction', $fx['actor']);

    $report = f7Check();

    expect(f7Invariant($report, 'I5')['ok'])->toBeTrue()
        ->and($report['passed'])->toBeTrue();
});

test('I5 violation: a credit note exceeding its original is caught', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    $original = f7Issue($fx, [f7Charge($fx, f7Item($fx, 'ORIG', 1000, 0))]);
    $origLine = $original->lines()->firstOrFail();
    $cnId = f7Raw('invoices', [
        'patient_id' => $fx['patient']->id, 'payer_type' => 'self_pay', 'series' => 'CN',
        'status' => 'issued', 'currency' => 'EUR', 'number' => '1', 'issue_date' => '2026-06-06',
        'subtotal_minor' => -1500, 'vat_total_minor' => 0, 'total_minor' => -1500, 'open_balance_minor' => 0,
        'credit_note_for_invoice_id' => $original->id,
    ]);
    f7Raw('invoice_lines', [
        'invoice_id' => $cnId, 'charge_id' => null, 'original_invoice_line_id' => $origLine->id,
        'code' => 'ORIG', 'description' => 'Credit', 'quantity' => -1, 'unit_price_minor' => 1500,
        'vat_rate_bp' => 0, 'line_total_minor' => -1500, 'line_vat_minor' => 0,
    ]);

    $i5 = f7Invariant(f7Check(), 'I5');

    expect($i5['ok'])->toBeFalse()
        ->and(collect($i5['rows'])->firstWhere('id', $original->id)['reason'])->toBe('credit_exceeds_original')
        ->and(collect($i5['rows'])->firstWhere('id', $original->id)['credited_total_minor'])->toBe(1500)
        ->and(collect($i5['rows'])->firstWhere('id', $original->id)['delta_minor'])->toBe(500);
});

// ----------------------------------------------------------------------------
// I6 — no orphan money
// ----------------------------------------------------------------------------

test('I6 clean: allocations and reversals reference real same-tenant rows', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    $invoice = f7Issue($fx, [f7Charge($fx, f7Item($fx, 'ORPH', 1000, 0))]);
    $payment = app(PaymentService::class)->record(1000, Payment::METHOD_CARD, $fx['actor'], null, null, null, '2026-06-10');
    $allocation = app(PaymentService::class)->allocate($payment, $invoice, 1000, $fx['actor']);
    app(PaymentService::class)->reverseAllocation($allocation, 'Wrong invoice', $fx['actor']);

    $report = f7Check();

    expect(f7Invariant($report, 'I6')['ok'])->toBeTrue()
        ->and($report['passed'])->toBeTrue();
});

test('I6 violation: an allocation with no matching payment is caught', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    $invoice = f7Issue($fx, [f7Charge($fx, f7Item($fx, 'GHOST', 1000, 0))]);
    $ghostPaymentId = (string) Str::ulid();

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    try {
        f7Raw('payment_allocations', [
            'payment_id' => $ghostPaymentId, 'invoice_id' => $invoice->id, 'amount_minor' => 400,
            'reverses_allocation_id' => null, 'reason' => null, 'allocated_by' => $fx['actor']->id,
            'allocated_at' => '2026-06-11 10:00:00',
        ]);
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
    // Keep the projection consistent so ONLY I6 fails.
    $invoice->balance()->firstOrFail()->forceFill(['open_balance_minor' => 600, 'status' => Invoice::STATUS_PARTIALLY_PAID])->save();

    $i6 = f7Invariant(f7Check(), 'I6');

    expect($i6['ok'])->toBeFalse()
        ->and($i6['delta_minor'])->toBe(400)
        ->and(collect($i6['rows'])->firstWhere('reason', 'missing_payment')['amount_minor'])->toBe(400)
        ->and(f7Invariant(f7Check(), 'I2')['ok'])->toBeTrue();
});

// ----------------------------------------------------------------------------
// Export gating, append-only, RBAC/tenant/audit
// ----------------------------------------------------------------------------

test('the export refuses to run when no reconciliation exists', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    f7Issue($fx, [f7Charge($fx, f7Item($fx, 'EXP', 1000, 0))]);

    expect(fn () => app(AccountingExportService::class)->export($fx['tenant'], F7_PERIOD, $fx['actor']))
        ->toThrow(RuntimeException::class);
});

test('the export refuses to run when the latest reconciliation failed', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    $invoice = f7Issue($fx, [f7Charge($fx, f7Item($fx, 'FAILEXP', 1000, 0))]);
    $invoice->balance()->firstOrFail()->forceFill(['open_balance_minor' => 5])->save(); // drift → I2 fails
    $run = app(ReconciliationEngine::class)->run($fx['tenant'], F7_PERIOD, $fx['actor']);

    expect($run->passed)->toBeFalse()
        ->and(fn () => app(AccountingExportService::class)->export($fx['tenant'], F7_PERIOD, $fx['actor']))
        ->toThrow(RuntimeException::class);
});

test('the export runs after a passed reconciliation and its totals equal the reconciled totals', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    f7Issue($fx, [f7Charge($fx, f7Item($fx, 'OK1', 1000, 810)), f7Charge($fx, f7Item($fx, 'OK2', 500, 0))]);
    $run = app(ReconciliationEngine::class)->run($fx['tenant'], F7_PERIOD, $fx['actor']);
    expect($run->passed)->toBeTrue();

    $path = app(AccountingExportService::class)->export($fx['tenant'], F7_PERIOD, $fx['actor']);

    expect(Storage::disk('local')->exists($path))->toBeTrue()
        ->and($path)->toStartWith('tenants/'.$fx['tenant']->id.'/billing/exports/');

    $csv = array_map('str_getcsv', array_filter(explode("\n", Storage::disk('local')->get($path))));
    $invoiceGross = 0;
    foreach (array_slice($csv, 1) as $row) {
        if (($row[0] ?? '') === 'invoice') {
            $invoiceGross += (int) $row[9];
        }
    }

    $i4Expected = f7Invariant($run->report, 'I4')['expected_minor'];
    expect($invoiceGross)->toBe($i4Expected)
        ->and(f7AuditRows($fx['tenant']->id, 'billing.exported'))->toHaveCount(1);
});

test('reconciliation_runs is append-only at the database level', function () {
    Storage::fake('local');
    $fx = f7Fixture();
    f7Issue($fx, [f7Charge($fx, f7Item($fx, 'AO', 1000, 0))]);
    $run = app(ReconciliationEngine::class)->run($fx['tenant'], F7_PERIOD, $fx['actor']);

    expect(fn () => DB::update('UPDATE reconciliation_runs SET passed = 0 WHERE id = ?', [$run->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::delete('DELETE FROM reconciliation_runs WHERE id = ?', [$run->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => $run->forceFill(['passed' => false])->save())
        ->toThrow(LogicException::class);
});

test('reconciliation and export are RBAC guarded tenant isolated audited and fail closed', function () {
    Storage::fake('local');
    $alpha = f7Fixture('alpha');
    f7Issue($alpha, [f7Charge($alpha, f7Item($alpha, 'RB', 1000, 0))]);
    $reception = f7User($alpha['tenant'], 'reception');

    expect(fn () => app(ReconciliationEngine::class)->run($alpha['tenant'], F7_PERIOD, $reception))
        ->toThrow(AuthorizationException::class);

    $run = app(ReconciliationEngine::class)->run($alpha['tenant'], F7_PERIOD, $alpha['actor']);

    expect(fn () => app(AccountingExportService::class)->export($alpha['tenant'], F7_PERIOD, $reception))
        ->toThrow(AuthorizationException::class);

    app(AccountingExportService::class)->export($alpha['tenant'], F7_PERIOD, $alpha['actor']);

    expect(f7AuditRows($alpha['tenant']->id, 'billing.reconciled'))->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($alpha['tenant']->id)['ok'])->toBeTrue();

    f7Fixture('beta');
    expect(ReconciliationRun::query()->whereKey($run->id)->exists())->toBeFalse();

    f7Ctx()->set($alpha['tenant']);
    expect(ReconciliationRun::query()->whereKey($run->id)->exists())->toBeTrue();

    f7Ctx()->forget();
    expect(fn () => ReconciliationRun::query()->count())->toThrow(TenantContextMissingException::class);
});
