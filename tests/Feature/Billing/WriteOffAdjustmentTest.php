<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceAdjustment;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\AdjustmentService;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PaymentService;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * BILLAR.P1 — write-offs (bad debt) + contractual adjustments modelled as OPERATOR-GATED, append-only,
 * RECONCILING ledger movements. They post to the ledger, reduce the open balance, are audited, and the
 * ReconciliationEngine's I1–I6 still tie out to the unit WITH them (I2 extended, not weakened). The
 * billing agent never writes one — a non-operator is refused. These tests ADD coverage; no existing
 * behaviour test is modified.
 */

const WA_PERIOD = '2026-06';

function waCtx(): TenantContext
{
    return app(TenantContext::class);
}

function waUser(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, catalog: TariffCatalog}
 */
function waFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    waCtx()->set($tenant);

    $actor = waUser($tenant); // 'billing' role holds billing.manage
    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $patient = app(PatientService::class)->create(['first_name' => 'Recon', 'last_name' => 'Patient', 'date_of_birth' => '1988-01-01', 'sex' => 'female']);
    $catalog = TariffCatalog::query()->create(['key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1, 'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => []]);

    return compact('tenant', 'actor', 'branch', 'patient', 'catalog');
}

/** Issue an invoice of `$priceMinor` (VAT-exempt for round numbers) with an open balance = total. */
function waIssuedInvoice(array $fx, int $priceMinor = 20000): Invoice
{
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $fx['catalog']->id, 'code' => '4000', 'description' => 'Consultation',
        'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);
    $charge = Charge::query()->create([
        'patient_id' => $fx['patient']->id, 'branch_id' => $fx['branch']->id, 'service_date' => '2026-06-01',
        'tariff_catalog_id' => $fx['catalog']->id, 'tariff_item_id' => $item->id, 'code' => $item->code,
        'description' => $item->description, 'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0,
        'quantity' => 1, 'line_total_minor' => $priceMinor, 'status' => Charge::STATUS_VALIDATED, 'created_by' => $fx['actor']->id,
    ]);

    $service = app(IssueService::class);

    return $service->issue(
        $service->createDraftFromCharges($fx['patient'], [$charge], $fx['actor'], Invoice::PAYER_SELF_PAY, null, Carbon::parse('2026-06-05'), Carbon::parse('2026-06-19')),
        $fx['actor'],
    );
}

function waOpen(Invoice $invoice): int
{
    return app(PaymentService::class)->openBalance($invoice->refresh());
}

/** The reconciliation report for the period (pure; no auth). */
function waReport(): array
{
    return app(ReconciliationEngine::class)->check(WA_PERIOD);
}

// ── A write-off posts a real ledger movement + reconciles to the unit ────────────────────────────

test('a write-off posts an append-only ledger movement, reduces the balance, and reconciles (I1-I6, delta=0)', function () {
    $fx = waFixture();
    $invoice = waIssuedInvoice($fx, 20000);

    $adj = app(AdjustmentService::class)->writeOff($invoice, 5000, 'Uncollectible — estate closed.', $fx['actor']);

    // Real integer-minor ledger movement, tied to the invoice, reason recorded, by the operator.
    expect($adj->type)->toBe(InvoiceAdjustment::TYPE_WRITE_OFF)
        ->and($adj->amount_minor)->toBe(5000)
        ->and($adj->reason)->toBe('Uncollectible — estate closed.')
        ->and($adj->created_by)->toBe($fx['actor']->id);

    // The balance dropped by exactly the write-off; the projection agrees with the derivation.
    expect(waOpen($invoice))->toBe(15000)
        ->and((int) InvoiceBalance::query()->where('invoice_id', $invoice->id)->value('open_balance_minor'))->toBe(15000);

    // The reconcile engine still ties out to the unit WITH the write-off — 6 invariants, every delta 0.
    $report = waReport();
    expect($report['passed'])->toBeTrue()
        ->and($report['invariants'])->toHaveCount(6);
    foreach ($report['invariants'] as $inv) {
        expect($inv['ok'])->toBeTrue()->and($inv['delta_minor'])->toBe(0);
    }

    // Audited (operator-attributed).
    $audited = DB::selectOne('SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?', [$fx['tenant']->id, 'billing.written_off'])->c;
    expect((int) $audited)->toBe(1);
});

// ── A contractual adjustment posts + reconciles ──────────────────────────────────────────────────

test('a contractual adjustment posts (with an agreement ref) and reconciles (delta=0)', function () {
    $fx = waFixture();
    $invoice = waIssuedInvoice($fx, 20000);

    $adj = app(AdjustmentService::class)->contractualAdjustment($invoice, 3000, 'Insurer-agreed rate.', 'AGR-2026-001', $fx['actor']);

    expect($adj->type)->toBe(InvoiceAdjustment::TYPE_CONTRACTUAL)
        ->and($adj->amount_minor)->toBe(3000)
        ->and($adj->reference)->toBe('AGR-2026-001')
        ->and(waOpen($invoice))->toBe(17000);

    $report = waReport();
    expect($report['passed'])->toBeTrue();
    foreach ($report['invariants'] as $inv) {
        expect($inv['delta_minor'])->toBe(0);
    }

    $audited = DB::selectOne('SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?', [$fx['tenant']->id, 'billing.adjusted'])->c;
    expect((int) $audited)->toBe(1);
});

// ── THE RECONCILE PROOF: balance = charges − payments − credits − adjustments − write-offs ───────

test('the balance equals charges minus payments minus adjustments minus write-offs, and reconciles to the unit', function () {
    $fx = waFixture();
    $invoice = waIssuedInvoice($fx, 20000); // charges = 20000

    // A payment of 4000, allocated to the invoice.
    $payments = app(PaymentService::class);
    $payment = $payments->record(4000, 'bank_transfer', $fx['actor'], $fx['patient'], null, 'EUR', Carbon::parse('2026-06-10'));
    $payments->allocate($payment, $invoice, 4000, $fx['actor']);

    // A contractual adjustment of 3000 and a write-off of 5000.
    $adjustments = app(AdjustmentService::class);
    $adjustments->contractualAdjustment($invoice, 3000, 'Insurer-agreed rate.', 'AGR-2026-002', $fx['actor']);
    $adjustments->writeOff($invoice, 5000, 'Residual uncollectible.', $fx['actor']);

    // 20000 − 4000 (payment) − 3000 (adjustment) − 5000 (write-off) = 8000.
    expect(waOpen($invoice))->toBe(8000)
        ->and((int) InvoiceBalance::query()->where('invoice_id', $invoice->id)->value('open_balance_minor'))->toBe(8000);

    $report = waReport();
    expect($report['passed'])->toBeTrue()->and($report['invariants'])->toHaveCount(6);
    foreach ($report['invariants'] as $inv) {
        expect($inv['ok'])->toBeTrue()->and($inv['delta_minor'])->toBe(0);
    }
});

// ── OPERATOR-GATED: a non-operator (the agent has no path) cannot write off or adjust ────────────

test('write-offs and adjustments are operator-gated — a non-billing.manage user is refused', function () {
    $fx = waFixture();
    $invoice = waIssuedInvoice($fx, 20000);
    $reception = waUser($fx['tenant'], 'reception'); // no billing.manage

    $svc = app(AdjustmentService::class);
    expect(fn () => $svc->writeOff($invoice, 1000, 'nope', $reception))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->contractualAdjustment($invoice, 1000, 'nope', null, $reception))->toThrow(AuthorizationException::class);

    // Nothing was posted; the balance is untouched.
    expect(waOpen($invoice))->toBe(20000)
        ->and(InvoiceAdjustment::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
});

// ── Over-adjustment guard: never reduce the balance below zero ────────────────────────────────────

test('the over-adjustment guard forbids writing off more than the open balance', function () {
    $fx = waFixture();
    $invoice = waIssuedInvoice($fx, 20000);

    expect(fn () => app(AdjustmentService::class)->writeOff($invoice, 20001, 'too much', $fx['actor']))
        ->toThrow(InvalidArgumentException::class);

    expect(waOpen($invoice))->toBe(20000); // unchanged
});

// ── Append-only: a correction is a reversal ROW, not a mutation ──────────────────────────────────

test('adjustments are append-only — corrections are reversal rows; a reversal restores the balance and reconciles', function () {
    $fx = waFixture();
    $invoice = waIssuedInvoice($fx, 20000);
    $svc = app(AdjustmentService::class);

    $writeOff = $svc->writeOff($invoice, 5000, 'Uncollectible.', $fx['actor']);
    expect(waOpen($invoice))->toBe(15000);

    // The row itself cannot be updated or deleted (ORM guard; the DB triggers back it too).
    expect(fn () => $writeOff->update(['amount_minor' => 1]))->toThrow(LogicException::class);
    expect(fn () => $writeOff->delete())->toThrow(LogicException::class);

    // A correction is a reversal row (the exact negative) — the balance is restored, and it reconciles.
    $reversal = $svc->reverse($writeOff, 'Recovered — patient paid.', $fx['actor']);
    expect($reversal->amount_minor)->toBe(-5000)
        ->and($reversal->reverses_adjustment_id)->toBe($writeOff->id)
        ->and(waOpen($invoice))->toBe(20000);

    // A reversal cannot itself be reversed, and an adjustment cannot be double-reversed.
    expect(fn () => $svc->reverse($reversal, 'x', $fx['actor']))->toThrow(InvalidArgumentException::class);
    expect(fn () => $svc->reverse($writeOff, 'again', $fx['actor']))->toThrow(InvalidArgumentException::class);

    $report = waReport();
    expect($report['passed'])->toBeTrue();
    foreach ($report['invariants'] as $inv) {
        expect($inv['delta_minor'])->toBe(0);
    }
});

// ── The existing tie-out is unweakened: a payment-only invoice still reconciles (delta=0) ────────

test('the base case is unweakened — a payment-only invoice (no adjustments) still reconciles to the unit', function () {
    $fx = waFixture();
    $invoice = waIssuedInvoice($fx, 20000);
    $payments = app(PaymentService::class);
    $payment = $payments->record(20000, 'card', $fx['actor'], $fx['patient'], null, 'EUR', Carbon::parse('2026-06-10'));
    $payments->allocate($payment, $invoice, 20000, $fx['actor']);

    expect(waOpen($invoice))->toBe(0);

    $report = waReport();
    expect($report['passed'])->toBeTrue()->and($report['invariants'])->toHaveCount(6);
    foreach ($report['invariants'] as $inv) {
        expect($inv['ok'])->toBeTrue()->and($inv['delta_minor'])->toBe(0);
    }
});
