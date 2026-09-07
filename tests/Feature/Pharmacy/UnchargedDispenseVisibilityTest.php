<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Models\DispenseCharge;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Pharmacy\Models\MedicationOrder;
use Modules\Pharmacy\Models\MedicationStock;
use Modules\Pharmacy\Services\DispensingService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| QA-FIX.5b — a technician's dispense no longer vanishes silently (P5-C2, D-207)
|--------------------------------------------------------------------------
| chargeForDispense() opens with Gate::authorize('billing.manage'), which
| pharmacy_technician deliberately lacks, and the controller called it inside
| `catch (Throwable) { }`. An AUTHORIZATION failure was therefore swallowed
| identically to a transient hiccup: stock decremented, the dispense
| committed, no charge existed, and the screen was byte-identical to the
| pharmacist's. Dispensing is that role's PRIMARY job.
|
| BRANCH CHOSEN: (b). The technician's dispense still produces NO charge —
| the permission boundary is deliberately authored and every other capture
| path in the product requires billing.manage on the ACTOR — but the outcome
| is now recorded and VISIBLE instead of silent.
*/

function udvFixture(string $slug = 'unch'): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Klinik '.$slug, 'slug' => 'klinik-'.$slug, 'region' => 'eu', 'status' => 'active',
    ]);
    app(TenantContext::class)->set($tenant);

    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);

    $make = function (string $email, string $roleKey) use ($tenant, $branch): User {
        $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create(['email' => $email]);
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
        ]);
        StaffProfile::query()->create([
            'user_id' => $user->id, 'first_name' => 'Staff', 'last_name' => ucfirst($roleKey),
            'display_name' => 'Staff '.$roleKey, 'profession' => 'pharmacy', 'primary_branch_id' => $branch->id,
        ]);

        return $user;
    };

    $pharmacist = $make('pharmacist@'.$slug.'.test', 'pharmacist');
    $technician = $make('technician@'.$slug.'.test', 'pharmacy_technician');

    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-'.$slug, 'name' => 'EU', 'version' => 1,
        'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => [],
    ]);
    $tariffItem = TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id, 'code' => 'MED-'.strtoupper($slug), 'description' => 'Amoxicillin',
        'unit_price_minor' => 120, 'vat_rate_bp' => 0, 'unit' => 'unit',
        'requires_service_documentation' => false, 'active' => true,
    ]);
    $item = FormularyItem::query()->create([
        'code' => 'MED-'.strtoupper($slug), 'name' => 'Amoxicillin', 'form' => 'capsule',
        'strength' => '500 mg', 'tariff_item_id' => $tariffItem->id, 'active' => true,
    ]);
    MedicationStock::query()->create([
        'formulary_item_id' => $item->id, 'location' => 'Main', 'on_hand' => 100,
        'unit' => 'unit', 'reorder_threshold' => 5,
    ]);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Greta', 'last_name' => 'Zimmermann', 'date_of_birth' => '1949-05-05', 'sex' => 'female',
    ]);
    $order = MedicationOrder::query()->create([
        'patient_id' => $patient->id, 'formulary_item_id' => $item->id,
        'dose_amount' => '1', 'dose_unit' => 'Kapsel', 'route' => 'PO', 'frequency' => 'TID',
        'prn' => false, 'status' => 'active', 'starts_at' => now()->subDay(),
        'prescribed_by' => $pharmacist->id,
    ]);

    return compact('tenant', 'branch', 'pharmacist', 'technician', 'patient', 'item', 'order');
}

/** Dispense through the real HTTP path so the controller's catch blocks are exercised. */
function udvDispense(array $fx, string $role, int $quantity = 1)
{
    return test()->actingAs($fx[$role])
        ->post('/pharmacy/medication-orders/'.$fx['order']->id.'/dispense', ['quantity' => $quantity]);
}

test('a PHARMACIST dispense still produces a charge — the positive control', function () {
    $fx = udvFixture('pos');

    udvDispense($fx, 'pharmacist')->assertRedirect();

    expect(Dispense::query()->count())->toBe(1)
        ->and(DispenseCharge::query()->count())->toBe(1)
        ->and(Dispense::query()->uncharged()->count())->toBe(0);
});

test('a TECHNICIAN dispense records the care, produces no charge, and is FINDABLE (branch b)', function () {
    $fx = udvFixture('tech');

    udvDispense($fx, 'technician')->assertRedirect();

    // The clinical record is intact — the drug left the shelf and that is recorded.
    expect(Dispense::query()->count())->toBe(1)
        ->and(MedicationStock::query()->first()->on_hand)->toBe(99);

    // No charge, per the chosen branch: the permission boundary is left intact.
    expect(DispenseCharge::query()->count())->toBe(0);

    // THE FIX: it is no longer silent. Phase 5's defect was that nothing could find this row.
    expect(Dispense::query()->uncharged()->count())->toBe(1);
});

test('THE AUTHORIZATION FAILURE IS RECORDED, NOT DISCARDED — and is distinguished from a transient one', function () {
    $fx = udvFixture('log');

    // The empty `catch (Throwable) {}` discarded both kinds identically. They are now separate paths
    // with separate messages, so an unbilled dispense has a trace.
    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'pharmacy.dispense.uncharged.not_permitted'
            && ($context['reason'] ?? null) === 'actor lacks billing.manage');
    Log::shouldReceive('warning')->never();

    udvDispense($fx, 'technician')->assertRedirect();
});

test('A TRANSIENT billing failure still does not unwind the dispense — the property the catch exists for', function () {
    $fx = udvFixture('trans');

    // Break the billing engine the way a genuine hiccup would, and assert the DISPENSE survives. This
    // is the property the swallow was protecting and it must not be lost by distinguishing the cases:
    // the drug has physically left the shelf.
    TariffItem::query()->delete();

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();

    udvDispense($fx, 'pharmacist')->assertRedirect();

    expect(Dispense::query()->count())->toBe(1)
        ->and(MedicationStock::query()->first()->on_hand)->toBe(99)
        ->and(DispenseCharge::query()->count())->toBe(0)
        ->and(Dispense::query()->uncharged()->count())->toBe(1);
});

test('the uncharged dispense is VISIBLE on the dispensing screen, not only in the database', function () {
    $fx = udvFixture('vis');

    udvDispense($fx, 'technician')->assertRedirect();

    // P5-M4: Phase 5 found NO surface listed uncharged dispenses, so "reconcilable later" was unbacked.
    test()->actingAs($fx['pharmacist'])
        ->get('/pharmacy/patients/'.$fx['patient']->id.'/dispensing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pharmacy/Dispensing')
            ->where('uncharged_count', 1)
            ->has('history', 1)
            ->where('history.0.charged', false));
});

test('POSITIVE CONTROL — a charged dispense is marked charged, so the flag is a real distinction', function () {
    $fx = udvFixture('flag');

    udvDispense($fx, 'pharmacist')->assertRedirect();

    test()->actingAs($fx['pharmacist'])
        ->get('/pharmacy/patients/'.$fx['patient']->id.'/dispensing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('uncharged_count', 0)
            ->where('history.0.charged', true));
});

test('POSITIVE CONTROL — the dispense triple is still atomic under its row lock (the Phase-5 guard)', function () {
    $fx = udvFixture('atomic');
    $service = app(DispensingService::class);

    // Ask for more than exists: the guard fires INSIDE the transaction, and nothing is left behind.
    $before = MedicationStock::query()->first()->on_hand;

    try {
        $service->dispense($fx['pharmacist'], $fx['order'], 100000);
    } catch (Throwable) {
        // expected
    }

    expect(MedicationStock::query()->first()->on_hand)->toBe($before)
        ->and(Dispense::query()->count())->toBe(0)
        ->and(DispenseCharge::query()->count())->toBe(0);
});

test('the uncharged scope is a FACT, not a verdict — it does not say why', function () {
    $fx = udvFixture('why');

    // Two different reasons, one query. An unpriced medication and a not-permitted actor both land in
    // the same list, and the scope does not pretend to distinguish them.
    udvDispense($fx, 'technician')->assertRedirect();           // not permitted
    TariffItem::query()->delete();                              // now unpriced
    udvDispense($fx, 'pharmacist')->assertRedirect();

    expect(Dispense::query()->count())->toBe(2)
        ->and(Dispense::query()->uncharged()->count())->toBe(2);
});
