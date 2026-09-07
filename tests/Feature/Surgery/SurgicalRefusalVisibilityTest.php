<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Billing\Models\Charge;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Models\CaseItemUsage;
use Modules\Surgery\Models\ImplantPlacement;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalCaseCharge;
use Modules\Surgery\Models\SurgicalItem;
use Modules\Surgery\Services\SurgicalBillingService;
use Modules\Surgery\Services\SurgicalCaseService;
use Modules\Surgery\Services\SurgicalStockService;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| QA-FIX.6c — P6-C3: Surgery surfaces render their refusals
|--------------------------------------------------------------------------
| The server refused correctly — sixteen `->withErrors([...])` sites across six
| controllers plus every validate() rule — and NOT ONE Surgery page rendered
| any of it. A refused action and a successful one were identical to the user:
| the page reloaded, nothing was recorded, no message appeared, and the POST
| returned 302, which is success-shaped. Driven in Phase 6: a blank implant lot
| and a 99999-unit stock request both vanished silently.
|
| TWO REFUSALS DID NOT EVEN REACH THAT STATE. They escaped as uncaught 500s and
| are fixed here too, because "the refusal is visible" is not satisfied by a
| crash: asking for theatre minutes before theatre time is priced
| (TariffNotFoundForDateException), and re-using a surgical item code (the
| unique(tenant_id, code) index, with nothing checking it first). Neither
| refusal is weakened — both still refuse, and now they say so.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{tenant: Tenant, surgeon: User, biller: User, case: SurgicalCase, gauze: SurgicalItem}
 */
function srvFixture(string $slug): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);

    $make = function (string $role) use ($tenant): User {
        $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

        return $user;
    };
    $surgeon = $make('surgeon');
    $biller = $make('org_admin');

    $surgeonProfile = StaffProfile::query()->create([
        'first_name' => 'Sara', 'last_name' => 'Sharp', 'display_name' => 'Dr Sara Sharp',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Percy', 'last_name' => 'Patient', 'date_of_birth' => '1972-02-02', 'sex' => 'male',
    ]);
    $case = app(SurgicalCaseService::class)->schedule(
        $surgeon, $patient, $surgeonProfile, 'Laparoscopic appendectomy', Carbon::parse('2026-06-15 08:00:00')
    );

    $stock = app(SurgicalStockService::class);
    $gauze = $stock->createItem($surgeon, 'SUT-GAUZE', 'Gauze swab', false);
    $stock->receive($surgeon, $gauze, 10);

    return compact('tenant', 'surgeon', 'biller', 'case', 'gauze');
}

// ------------------------------------------------- THE DRIVEN PHASE-6 PAIR ----

test('P6-C3: a blank implant lot is refused AND the refusal reaches the page', function () {
    $fx = srvFixture('srv-lot');
    $implant = app(SurgicalStockService::class)->createItem($fx['surgeon'], 'SUT-SCREW', 'Titanium screw', true);
    app(SurgicalStockService::class)->receive($fx['surgeon'], $implant, 5);

    $response = test()->actingAs($fx['surgeon'])
        ->from('/surgery/cases/'.$fx['case']->id.'/supplies')
        ->post('/surgery/cases/'.$fx['case']->id.'/supplies/implant', [
            'surgical_item_id' => $implant->id,
            'lot_number' => '',   // the exact Phase-6 gesture
        ]);

    // THE REFUSAL IS UNCHANGED — this part must not soften a guard to make it visible.
    expect(ImplantPlacement::query()->count())->toBe(0);

    // THE PROPERTY (D-182 — before the fix nothing carried the message to the user).
    $response->assertRedirect()->assertSessionHasErrors('lot_number');
});

test('P6-C3: an over-stock usage is refused AND the refusal reaches the page', function () {
    $fx = srvFixture('srv-stock');

    $response = test()->actingAs($fx['surgeon'])
        ->from('/surgery/cases/'.$fx['case']->id.'/supplies')
        ->post('/surgery/cases/'.$fx['case']->id.'/supplies/use', [
            'surgical_item_id' => $fx['gauze']->id,
            'quantity' => 99999,   // the exact Phase-6 gesture; 10 on hand
        ]);

    // The guard still fires and still leaves nothing behind.
    expect(CaseItemUsage::query()->count())->toBe(0);

    $response->assertRedirect()->assertSessionHasErrors('surgical_supplies');
});

// ------------------------------------------- THE TWO THAT USED TO BE 500s ----

test('P6-C3: theatre minutes before theatre time is priced is a REFUSAL, not a 500', function () {
    $fx = srvFixture('srv-tariff');
    // Price the procedure only. Theatre time has no tariff, which is the default on a fresh tenant.
    app(SurgicalBillingService::class)->priceProcedure($fx['biller'], 'APPEND-01', 'Appendectomy', 250000);

    $response = test()->actingAs($fx['biller'])
        ->from('/surgery/cases/'.$fx['case']->id.'/billing')
        ->post('/surgery/cases/'.$fx['case']->id.'/billing/charge', [
            'procedure_code' => 'APPEND-01',
            'theatre_minutes' => 45,
        ]);

    // BEFORE: TariffNotFoundForDateException escaped the controller and the operator got a 500 page.
    // NOW: a 302 carrying the engine's own sentence. The status code change is deliberate.
    $response->assertRedirect()->assertSessionHasErrors('surgical_billing');

    // AND NOTHING WAS BILLED — the refusal is still a refusal (D-208's atomicity, unchanged).
    expect(Charge::query()->count())->toBe(0)
        ->and(SurgicalCaseCharge::query()->count())->toBe(0);
});

test('P6-C3: a duplicate surgical item code is a REFUSAL naming the field, not a 500', function () {
    $fx = srvFixture('srv-dupe');

    // 'SUT-GAUZE' already exists in this tenant (created by the fixture).
    $response = test()->actingAs($fx['surgeon'])
        ->from('/surgery/inventory')
        ->post('/surgery/inventory/items', [
            'code' => 'SUT-GAUZE',
            'name' => 'A second gauze',
            'is_implant' => false,
        ]);

    // BEFORE: the unique(tenant_id, code) index raised a raw QueryException — an uncaught 500.
    $response->assertRedirect()->assertSessionHasErrors('code');

    // The DB constraint is still the hard guard: exactly one item with that code.
    expect(SurgicalItem::query()->where('code', 'SUT-GAUZE')->count())->toBe(1);
});

test('the duplicate-code rule is TENANT-SCOPED — another tenant may use the same code', function () {
    $fx = srvFixture('srv-t1');
    // A second tenant with its own surgeon; the same code must be free there.
    $fx2 = srvFixture('srv-t2');

    app(TenantContext::class)->set($fx2['tenant']);

    test()->actingAs($fx2['surgeon'])
        ->post('/surgery/inventory/items', ['code' => 'SUT-FRESH', 'name' => 'Fresh item', 'is_implant' => false])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(SurgicalItem::query()->where('code', 'SUT-FRESH')->count())->toBe(1);
});

// ------------------------------------------------------ POSITIVE CONTROLS ----

test('POSITIVE CONTROL — a SUCCESSFUL action flashes NO error at all', function () {
    $fx = srvFixture('srv-ok');

    // The same route as the refusal above, with a quantity that fits. If this flashed an error, the
    // rendered-error tests could be satisfied by a page that always shows something.
    test()->actingAs($fx['surgeon'])
        ->post('/surgery/cases/'.$fx['case']->id.'/supplies/use', [
            'surgical_item_id' => $fx['gauze']->id,
            'quantity' => 2,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(CaseItemUsage::query()->count())->toBe(1);
});

test('STRUCTURAL GUARD: every Surgery surface with a reachable refusal renders it', function () {
    // A payload assertion cannot see whether the page RENDERS the error bag (the QA-FIX.5a lesson):
    // Inertia shares `errors` on every response whether or not any template reads it. The guard is
    // therefore structural. Mutation-checked: deleting <RefusalNotice /> from any page reddens this.
    $mustRender = ['CaseBoard', 'Case', 'Inventory', 'CaseSupplies', 'SurgicalPricing', 'CaseBilling'];

    foreach ($mustRender as $page) {
        $vue = (string) file_get_contents(resource_path("js/pages/Surgery/{$page}.vue"));
        expect($vue)->toContain('<RefusalNotice />')
            ->and($vue)->toContain("import RefusalNotice from '@/Components/RefusalNotice.vue';");
    }

    // The component must read the WHOLE bag, not named keys: validate() keys by FIELD while the
    // controllers' withErrors key by DOMAIN, so naming keys would miss half the refusals.
    $component = (string) file_get_contents(resource_path('js/Components/RefusalNotice.vue'));
    expect($component)->toContain('Object.values(bag)')
        ->and($component)->toContain('page.props.errors')
        // …and it must announce itself, or it stays invisible to a screen reader.
        ->and($component)->toContain('role="alert"');
});

test('D-176: Checklist.vue has NO error region, because it has no refusal a user can trigger', function () {
    // `SurgicalChecklistController::confirm` validates `template_item_id` (required) and `checked`
    // (required boolean), and the page's only control is a checkbox bound to a real template item — so
    // neither rule can fail from a live control, and its one `surgical_checklist` domain key cannot
    // fire either. An error region there would be an affordance for a refusal that cannot occur, which
    // is the same defect class as an unbacked badge (D-176). Its ABSENCE is deliberate and pinned here
    // so a later "consistency" pass does not add one.
    $vue = (string) file_get_contents(resource_path('js/pages/Surgery/Checklist.vue'));

    expect($vue)->not->toContain('RefusalNotice');
});
