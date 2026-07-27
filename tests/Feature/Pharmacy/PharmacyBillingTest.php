<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Models\DispenseCharge;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Pharmacy\Models\MedicationOrder;
use Modules\Pharmacy\Services\DispensingService;
use Modules\Pharmacy\Services\MedicationOrderService;
use Modules\Pharmacy\Services\PharmacyBillingService;
use Modules\Pharmacy\Services\StockService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * PHARMACY.G5 — pharmacy billing. A dispensed med accrues a Charge through the EXISTING billing engine and
 * reconciles-to-the-unit. STRICTLY ORCHESTRATION — NO new billing/pricing/VAT/line-total math (a med is a
 * tenant-authored TariffItem; the engine prices everything). The bed-day (HOSPITAL.G6) pattern.
 */

const PB_PERIOD = '2026-06';

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function pbCtx(): TenantContext
{
    return app(TenantContext::class);
}

function pbUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, pharmacist: User, prescriber: User, reception: User, patient: Patient, item: FormularyItem, order: MedicationOrder} */
function pbFixture(string $slug = 'pharmbill'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Pharmacy', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    pbCtx()->set($tenant);

    Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $pharmacist = pbUser($tenant, 'pharmacist'); // billing.manage + dispense.manage + patient.view (G5)
    $prescriber = pbUser($tenant, 'doctor');     // medication.prescribe — creates the order
    $reception = pbUser($tenant, 'reception');   // NO billing.manage
    $patient = app(PatientService::class)->create(['first_name' => 'Ivy', 'last_name' => 'Inpatient', 'date_of_birth' => '1975-03-03', 'sex' => 'female']);
    $item = FormularyItem::query()->create(['code' => 'MED-PARACETAMOL-500', 'name' => 'Paracetamol', 'form' => FormularyItem::FORM_TABLET, 'strength' => '500 mg']);
    $order = app(MedicationOrderService::class)->prescribe($prescriber, $patient, $item, ['dose_amount' => '500', 'dose_unit' => 'mg', 'route' => 'PO', 'frequency' => 'QID']);

    return compact('tenant', 'pharmacist', 'prescriber', 'reception', 'patient', 'item', 'order');
}

/** A dispensed, priced charge helper. */
function pbDispenseCharge(array $fx, int $priceMinor, int $qty): Charge
{
    app(PharmacyBillingService::class)->priceItem($fx['pharmacist'], $fx['item'], $priceMinor, 'unit');
    app(StockService::class)->receive($fx['pharmacist'], $fx['item'], 100);
    $dispense = app(DispensingService::class)->dispense($fx['pharmacist'], $fx['order'], $qty);

    return app(PharmacyBillingService::class)->chargeForDispense($fx['pharmacist'], $dispense);
}

test('a medication is priced as a tenant-authored TariffItem (integer minor units, no licensed pricing)', function () {
    $fx = pbFixture();

    $tariff = app(PharmacyBillingService::class)->priceItem($fx['pharmacist'], $fx['item'], 800, 'unit');

    // The price lives in the EXISTING tariff store — a tenant-authored item in the 'pharmacy' catalog.
    $catalog = TariffCatalog::query()->where('key', PharmacyBillingService::CATALOG_KEY)->firstOrFail();
    expect($tariff->tariff_catalog_id)->toBe($catalog->id)
        ->and($tariff->code)->toBe('MED-PARACETAMOL-500')   // the tenant's OWN code — NOT a licensed identifier
        ->and($tariff->unit_price_minor)->toBe(800)          // integer minor units
        ->and(is_int($tariff->unit_price_minor))->toBeTrue()
        ->and($tariff->vat_rate_bp)->toBe(0)
        ->and($fx['item']->fresh()->tariff_item_id)->toBe($tariff->id)
        ->and($fx['item']->fresh()->isPriced())->toBeTrue();
});

test('a dispense captures a Charge through the EXISTING engine (engine prices + snapshots), idempotently', function () {
    $fx = pbFixture();

    $charge = pbDispenseCharge($fx, 800, 2);

    // The engine resolved the tariff by code, snapshotted the fee, and computed the line total (2 × 800).
    expect($charge)->toBeInstanceOf(Charge::class)
        ->and($charge->code)->toBe('MED-PARACETAMOL-500')
        ->and($charge->quantity)->toBe(2)
        ->and($charge->unit_price_minor)->toBe(800)       // snapshotted BY THE ENGINE
        ->and($charge->line_total_minor)->toBe(1600)      // 2 × 800, computed BY THE ENGINE
        ->and(DispenseCharge::query()->where('charge_id', $charge->id)->count())->toBe(1);

    // Idempotent: re-charging the same dispense returns the SAME charge (the unique dispense_charges key).
    $dispense = Dispense::query()->firstOrFail();
    $again = app(PharmacyBillingService::class)->chargeForDispense($fx['pharmacist'], $dispense);
    expect($again->id)->toBe($charge->id)
        ->and(Charge::query()->count())->toBe(1);
});

test('a patient invoice INCLUDING a dispensed-med charge RECONCILES-TO-THE-UNIT (six invariants, delta = 0)', function () {
    Storage::fake('local');
    $fx = pbFixture();

    pbDispenseCharge($fx, 800, 2); // a 1600 med charge

    $invoice = app(PharmacyBillingService::class)->invoicePatient(
        $fx['pharmacist'], $fx['patient'], Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'),
    );

    expect($invoice->status)->toBe(Invoice::STATUS_ISSUED)
        ->and($invoice->series)->toBe(Invoice::SERIES_INVOICE)
        ->and($invoice->number)->not->toBeNull()          // gapless number from the existing issuer
        ->and($invoice->total_minor)->toBe(1600);

    // THE KEY PROOF: the existing ReconciliationEngine ties the period to the unit WITH a med charge present.
    $report = app(ReconciliationEngine::class)->check(PB_PERIOD);
    $i4 = collect($report['invariants'])->firstWhere('invariant', 'I4');
    expect($report['passed'])->toBeTrue()
        ->and($i4['ok'])->toBeTrue()
        ->and($i4['delta_minor'])->toBe(0);
});

test('FENCE: the Pharmacy module computes NO billing money — pricing/VAT/line-total math lives only in the engine', function () {
    // A med price is a RATE the tenant authors (unit_price_minor / vat_rate_bp = 0 keys are allowed); what
    // must NEVER appear is COMPUTED money: line/VAT/subtotal totals or VAT rounding.
    $forbidden = ['line_total_minor', 'vat_total_minor', 'subtotal_minor', 'vatMinor', 'intdiv('];
    $files = collect(File::allFiles(base_path('Modules/Pharmacy/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');

    expect($files)->not->toBeEmpty();
    foreach ($files as $file) {
        foreach ($forbidden as $needle) {
            expect(str_contains(File::get($file->getPathname()), $needle))
                ->toBeFalse("Pharmacy must not compute billing money ({$needle}) — found in {$file->getRelativePathname()}");
        }
    }

    // And a med price is a rate, not a clinical verdict — no cost-based-substitution/appropriateness column.
    $cols = Schema::getColumnListing('formulary_items');
    foreach (['substitution', 'appropriateness', 'verdict', 'severity', 'preferred_alternative'] as $word) {
        expect($cols)->not->toContain($word, "formulary_items must not carry a cost/clinical-judgment column: {$word}");
    }
});

test('the per-unit fee is snapshotted at capture: re-pricing a med later never changes a past charge', function () {
    $fx = pbFixture();

    $charge = pbDispenseCharge($fx, 800, 1);
    expect($charge->unit_price_minor)->toBe(800)->and($charge->line_total_minor)->toBe(800);

    // The tenant re-prices the med upward — a future concern, not retroactive.
    app(PharmacyBillingService::class)->priceItem($fx['pharmacist'], $fx['item'], 99999, 'unit');
    expect(TariffItem::query()->where('code', 'MED-PARACETAMOL-500')->firstOrFail()->unit_price_minor)->toBe(99999);

    // The already-captured charge keeps the snapshotted fee.
    expect($charge->fresh()->unit_price_minor)->toBe(800)
        ->and($charge->fresh()->line_total_minor)->toBe(800);
});

test('pharmacy pricing is RBAC-gated (billing.manage) and tenant scoped, fail closed', function () {
    $fx = pbFixture();

    // reception (no billing.manage) cannot price a med.
    expect(fn () => app(PharmacyBillingService::class)->priceItem($fx['reception'], $fx['item'], 800, 'unit'))
        ->toThrow(AuthorizationException::class);

    // the pricing surface is billing.manage-gated through the real stack.
    pbCtx()->forget();
    $this->actingAs($fx['pharmacist'])->get('/pharmacy/pricing')->assertOk();
    pbCtx()->forget();
    $this->actingAs($fx['reception'])->get('/pharmacy/pricing')->assertForbidden();

    // cross-tenant: tenant B pharmacist cannot price tenant A's formulary item — fail closed.
    $fxB = pbFixture('beta');
    pbCtx()->set($fxB['tenant']);
    expect(fn () => app(PharmacyBillingService::class)->priceItem($fxB['pharmacist'], $fx['item'], 800, 'unit'))
        ->toThrow(CrossTenantReferenceException::class);
});
