<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Pharmacy\Exceptions\DispensingException;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Pharmacy\Models\MedicationOrder;
use Modules\Pharmacy\Models\MedicationStock;
use Modules\Pharmacy\Models\StockMovement;
use Modules\Pharmacy\Services\DispensingService;
use Modules\Pharmacy\Services\MedicationOrderService;
use Modules\Pharmacy\Services\StockService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * PHARMACY.G4 — dispensing + inventory. Net-new operational domain: append-only stock movements + dispenses;
 * the on-hand is consistent with the ledger; dispensing decrements stock SAFELY (no oversell, no negative,
 * concurrency-safe — the lock idiom; the parallel proof is DispenseParallelHammerTest). RECORD-NOT-JUDGE:
 * operational facts only, "below threshold" is a factual comparison, NO safety judgment in dispensing.
 */

function dpCtx(): TenantContext
{
    return app(TenantContext::class);
}

function dpTenant(string $slug = 'disphosp'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Hospital', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    dpCtx()->set($tenant);

    return $tenant;
}

function dpUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, prescriber: User, pharmacist: User, reception: User, billing: User, patient: Patient, item: FormularyItem, order: MedicationOrder} */
function dpFixture(string $slug = 'disphosp'): array
{
    $tenant = dpTenant($slug);
    $prescriber = dpUser($tenant, 'doctor');     // medication.prescribe — creates the order
    $pharmacist = dpUser($tenant, 'pharmacist'); // dispense.manage + patient.view — dispenses / manages stock
    $reception = dpUser($tenant, 'reception');   // patient.view but NOT dispense.manage
    $billing = dpUser($tenant, 'billing');       // NO patient.view
    $patient = app(PatientService::class)->create(['first_name' => 'Ivy', 'last_name' => 'Inpatient', 'date_of_birth' => '1975-03-03', 'sex' => 'female']);
    $item = FormularyItem::query()->create(['code' => 'MED-PARACETAMOL-500', 'name' => 'Paracetamol', 'form' => FormularyItem::FORM_TABLET, 'strength' => '500 mg']);
    $order = app(MedicationOrderService::class)->prescribe($prescriber, $patient, $item, ['dose_amount' => '500', 'dose_unit' => 'mg', 'route' => 'PO', 'frequency' => 'QID']);

    return compact('tenant', 'prescriber', 'pharmacist', 'reception', 'billing', 'patient', 'item', 'order');
}

test('receiving + adjusting stock writes append-only movements and keeps on-hand consistent with the ledger, audited', function () {
    $fx = dpFixture();

    $stock = app(StockService::class)->receive($fx['pharmacist'], $fx['item'], 10, 'tablet', 4);
    expect($stock->on_hand)->toBe(10)->and($stock->unit)->toBe('tablet');

    app(StockService::class)->receive($fx['pharmacist'], $fx['item'], 5);
    app(StockService::class)->adjust($fx['pharmacist'], $stock->fresh(), 12, 'stock take');

    // on-hand consistent with the latest movement's resulting_on_hand.
    $latest = StockMovement::query()->where('medication_stock_id', $stock->id)->orderByDesc('occurred_at')->orderByDesc('id')->first();
    expect($stock->fresh()->on_hand)->toBe(12)
        ->and($latest->resulting_on_hand)->toBe(12)
        ->and($latest->type)->toBe('adjusted')
        ->and($latest->quantity_change)->toBe(-3)
        ->and(StockMovement::query()->where('medication_stock_id', $stock->id)->count())->toBe(3); // received, received, adjusted

    expect(AuditEvent::query()->where('action', 'stock.received')->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'stock.adjusted')->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();

    // An append-only movement cannot be edited/deleted.
    expect(fn () => $latest->update(['reason' => 'x']))->toThrow(DispensingException::class);
    expect(fn () => DB::table('stock_movements')->where('id', $latest->id)->delete())->toThrow(QueryException::class);
});

test('dispensing against a G2 order decrements stock atomically as an append-only dispense + movement, audited + read-logged', function () {
    $fx = dpFixture();
    app(StockService::class)->receive($fx['pharmacist'], $fx['item'], 10);

    $dispense = app(DispensingService::class)->dispense($fx['pharmacist'], $fx['order'], 3);

    expect($dispense)->toBeInstanceOf(Dispense::class)
        ->and($dispense->patient_id)->toBe($fx['patient']->id)
        ->and($dispense->medication_order_id)->toBe($fx['order']->id)
        ->and($dispense->quantity)->toBe(3)
        ->and($dispense->dispensed_by)->toBe($fx['pharmacist']->id);

    // Stock decremented + a matching 'dispensed' movement linked to the dispense.
    $stock = MedicationStock::query()->where('formulary_item_id', $fx['item']->id)->firstOrFail();
    expect($stock->on_hand)->toBe(7)
        ->and(StockMovement::query()->where('dispense_id', $dispense->id)->where('type', 'dispensed')->where('quantity_change', -3)->where('resulting_on_hand', 7)->count())->toBe(1);

    // Append-only dispense; audited (patient-scoped) + chain intact.
    expect(fn () => DB::table('dispenses')->where('id', $dispense->id)->update(['quantity' => 9]))->toThrow(QueryException::class);
    expect(AuditEvent::query()->where('action', 'medication.dispensed')->where('patient_id', $fx['patient']->id)->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();
});

test('the stock guard refuses overselling and never goes negative', function () {
    $fx = dpFixture();
    app(StockService::class)->receive($fx['pharmacist'], $fx['item'], 2);

    // Can't dispense more than on-hand — refused, stock unchanged, no dispense written.
    expect(fn () => app(DispensingService::class)->dispense($fx['pharmacist'], $fx['order'], 5))
        ->toThrow(DispensingException::class);
    expect(MedicationStock::query()->where('formulary_item_id', $fx['item']->id)->firstOrFail()->on_hand)->toBe(2)
        ->and(Dispense::query()->count())->toBe(0);

    // An adjust cannot take on-hand negative.
    $stock = MedicationStock::query()->where('formulary_item_id', $fx['item']->id)->firstOrFail();
    expect(fn () => app(StockService::class)->adjust($fx['pharmacist'], $stock, -1, 'bad'))->toThrow(DispensingException::class);
});

test('dispensing against a discontinued order is refused (a factual state check, not a safety judgment)', function () {
    $fx = dpFixture();
    app(StockService::class)->receive($fx['pharmacist'], $fx['item'], 10);
    app(MedicationOrderService::class)->transition($fx['prescriber'], $fx['order'], MedicationOrder::STATUS_DISCONTINUED, 'stopped');

    expect(fn () => app(DispensingService::class)->dispense($fx['pharmacist'], $fx['order']->fresh(), 1))
        ->toThrow(DispensingException::class);
    expect(MedicationStock::query()->where('formulary_item_id', $fx['item']->id)->firstOrFail()->on_hand)->toBe(10) // unchanged
        ->and(Dispense::query()->count())->toBe(0);
});

test('FENCE: stock/dispensing are operational facts — below-threshold is a factual comparison, no graded/safety column', function () {
    $fx = dpFixture();

    // No computed-judgment/safety column on any of the three tables.
    foreach (['medication_stocks', 'stock_movements', 'dispenses'] as $table) {
        $cols = Schema::getColumnListing($table);
        foreach (['severity', 'score', 'risk', 'grade', 'priority', 'alert_level', 'safety_flag', 'verdict', 'interaction'] as $word) {
            expect($cols)->not->toContain($word, "{$table} must not carry a judgment/safety column: {$word}");
        }
    }

    // below-threshold is a plain on-hand-vs-threshold comparison, never a graded alert.
    $stock = app(StockService::class)->receive($fx['pharmacist'], $fx['item'], 3, 'tablet', 5);
    expect($stock->isBelowThreshold())->toBeTrue(); // 3 <= 5
    app(StockService::class)->receive($fx['pharmacist'], $fx['item'], 10);
    expect($stock->fresh()->isBelowThreshold())->toBeFalse(); // 13 > 5

    // No safety checking happens in dispensing (the seam is orders/administration) — a dispense is a fact.
    app(StockService::class)->receive($fx['pharmacist'], $fx['item'], 10);
    $dispense = app(DispensingService::class)->dispense($fx['pharmacist'], $fx['order'], 1);
    expect($dispense->quantity)->toBe(1); // recorded verbatim, nothing computed/flagged
});

test('dispensing + inventory are RBAC-gated (dispense.manage) and tenant/patient scoped', function () {
    $fx = dpFixture();
    app(StockService::class)->receive($fx['pharmacist'], $fx['item'], 10);

    // reception holds patient.view but NOT dispense.manage → cannot receive or dispense.
    expect(fn () => app(StockService::class)->receive($fx['reception'], $fx['item'], 1))->toThrow(AuthorizationException::class);
    expect(fn () => app(DispensingService::class)->dispense($fx['reception'], $fx['order'], 1))->toThrow(AuthorizationException::class);

    // Inventory (tenant-level) is dispense.manage-only: pharmacist 200, reception 403, billing 403.
    dpCtx()->forget();
    $this->actingAs($fx['pharmacist'])->get('/pharmacy/inventory')->assertOk();
    dpCtx()->forget();
    $this->actingAs($fx['reception'])->get('/pharmacy/inventory')->assertForbidden();

    // Dispensing view is patient.view (read-logged); the dispense write is dispense.manage.
    dpCtx()->forget();
    $this->actingAs($fx['billing'])->get('/pharmacy/patients/'.$fx['patient']->id.'/dispensing')->assertForbidden();
    dpCtx()->forget();
    $this->actingAs($fx['reception'])->get('/pharmacy/patients/'.$fx['patient']->id.'/dispensing')->assertOk();
    dpCtx()->forget();
    $this->actingAs($fx['reception'])->post('/pharmacy/medication-orders/'.$fx['order']->id.'/dispense', ['quantity' => 1])->assertForbidden();
    dpCtx()->forget();
    $this->actingAs($fx['pharmacist'])->post('/pharmacy/medication-orders/'.$fx['order']->id.'/dispense', ['quantity' => 1])->assertRedirect();
    dpCtx()->set($fx['tenant']);
    expect(Dispense::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('resource_id', $fx['patient']->id)->where('action', 'read')->count())->toBeGreaterThanOrEqual(1);

    // cross-tenant: tenant B pharmacist cannot dispense against tenant A's order; A's patient dispensing is 404 to B.
    $fxB = dpFixture('beta');
    dpCtx()->set($fxB['tenant']);
    expect(fn () => app(DispensingService::class)->dispense($fxB['pharmacist'], $fx['order'], 1))
        ->toThrow(CrossTenantReferenceException::class);
    dpCtx()->forget();
    $this->actingAs($fxB['pharmacist'])->get('/pharmacy/patients/'.$fx['patient']->id.'/dispensing')->assertNotFound();
});
