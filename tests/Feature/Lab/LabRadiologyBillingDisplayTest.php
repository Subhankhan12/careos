<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Charge;
use Modules\Billing\Services\ChargeSetReader;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Services\LabBillingService;
use Modules\Lab\Services\LabCatalogService;
use Modules\Lab\Services\LabOrderService;
use Modules\Lab\Services\LabResultService;
use Modules\Lab\Services\SpecimenService;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Radiology\Models\RadiologyOrder;
use Modules\Radiology\Services\RadiologyBillingService;
use Modules\Radiology\Services\RadiologyCatalogService;
use Modules\Radiology\Services\RadiologyOrderService;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| QA-FIX.8a — P8-C1: the Lab and Radiology billing screens tell the truth
|--------------------------------------------------------------------------
| Both pages declared the PROP `invoice` AND a top-level `function invoice()`.
| In <script setup> the function shadows the prop in the template, and a
| function is always truthy, so:
|   - `v-if="invoice"` was permanently TRUE  → both pages announced
|     "Issued invoice · Total: NaN" on an order that had never been charged;
|   - `money(invoice.total_minor)` read `undefined` → the literal string "NaN",
|     even on a GENUINELY INVOICED order;
|   - `:href="invoice.url"` was undefined → "Open invoice" resolved to the page
|     you were already on;
|   - `v-if="isCharged && !invoice"` was permanently FALSE → the issue-invoice
|     button NEVER rendered, so an outpatient lab or imaging invoice could not
|     be issued through the product at all.
| Driven in Phase 8 on both modules, in both the uninvoiced and invoiced states.
|
| This is the P6-C1 defect QA-FIX.6a (D-208) fixed in Surgery, and the remedy is
| that one: rename the action to `issueInvoice()`, and — because a rename alone
| would still leave the page deriving `quantity × unit_price_minor` and a
| client-side sum, and formatting without a currency — move every money figure
| to `ChargeSetReader`, the engine-side reader in Modules/Billing.
|
| THE READER HAS TO LIVE IN BILLING, and that is not a style choice. Both
| modules carry a byte-level money fence (LabBillingTest / RadiologyBillingTest)
| asserting the engine's total columns appear NOWHERE under Modules/Lab/src or
| Modules/Radiology/src. Reading the stored line total directly in either
| controller would redden a passing guard; `ChargeSetReader::present()` returns
| it already formatted, so the module shows the figure without naming it.
*/

/**
 * The CODE of a .vue file, with comments removed.
 *
 * THE SCANS BELOW MUST READ CODE, NOT PROSE. Every one of these guards forbids a fragment of the OLD
 * defect — `function invoice(`, `isCharged && !invoice` — and the fixed pages explain that defect in their
 * own header comments, quoting it verbatim so the next reader knows why the file is shaped this way. A
 * fence that its own rationale trips is a fence that gets deleted (the QA-FIX.7b lesson, third time this
 * programme). So the rationale is stripped and the code is scanned.
 */
function lrbCode(string $module): string
{
    $src = (string) file_get_contents(resource_path("js/pages/{$module}/Billing.vue"));
    $src = preg_replace('~<!--.*?-->~s', ' ', $src) ?? $src;          // template comments
    $src = preg_replace('~/\*.*?\*/~s', ' ', $src) ?? $src;           // block comments
    $out = preg_replace('~(?<![:\'"])//[^\n]*~', ' ', $src) ?? $src;  // line comments, not urls

    // D-174: the strip must not have eaten the file — otherwise every "not->toContain" passes on ''.
    expect(strlen($out))->toBeGreaterThan(2000);
    expect($out)->toContain('defineProps');

    return $out;
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{tenant: Tenant, biller: User, labTech: User, labOrder: LabOrder, radOrder: RadiologyOrder} */
function lrbFixture(string $slug): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);

    $make = function (string $role) use ($tenant): User {
        $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

        return $user;
    };
    $biller = $make('org_admin');   // billing.manage — the only kind of account that can reach these screens
    $labTech = $make('lab_tech');

    StaffProfile::query()->create([
        'first_name' => 'Ravi', 'last_name' => 'Rao', 'display_name' => 'Dr Ravi Rao',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);

    // LAB: an order carried all the way to `resulted`, then priced.
    $test = app(LabCatalogService::class)->authorTest($biller, 'LAB-K', 'Potassium', 'Blood', 'mmol/L', '3.5–5.1');
    $labOrder = app(LabOrderService::class)->place($biller, $patient, $test, LabOrder::PRIORITY_ROUTINE)['labOrder'];
    $specimen = app(SpecimenService::class)->collect($labTech, $labOrder);
    app(LabResultService::class)->record($labTech, $specimen, ['value' => '4.2']);
    app(LabBillingService::class)->priceTest($biller, $test, 2500);

    // RADIOLOGY: an order + a priced exam.
    $exam = app(RadiologyCatalogService::class)->authorExam($biller, 'RAD-CXR', 'Chest X-ray', 'Röntgen', 'Thorax');
    $radOrder = app(RadiologyOrderService::class)->place($biller, $patient, $exam, RadiologyOrder::PRIORITY_ROUTINE)['radiologyOrder'];
    app(RadiologyBillingService::class)->priceExam($biller, $exam, 4500);

    return compact('tenant', 'biller', 'labTech', 'labOrder', 'radOrder');
}

// ------------------------------------------------- THE ENGINE'S OWN FIGURE ----

test('P8-C1: the LAB billing screen shows the ENGINE total, and it ties to the engine\'s own sum', function () {
    $fx = lrbFixture('lrb-lab-total');
    $charge = app(LabBillingService::class)->chargeOrder($fx['biller'], $fx['labOrder']);

    // The engine's figure, read from Billing (this test may name the column; the MODULES may not).
    $engineTotal = (int) Charge::query()->whereKey($charge->id)->sum('line_total_minor');
    expect($engineTotal)->toBe(2500); // D-174: a real, non-zero amount

    app(TenantContext::class)->forget();
    test()->actingAs($fx['biller'])
        ->get('/lab/orders/'.$fx['labOrder']->id.'/billing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Billing')
            ->where('capturedTotal.minor', $engineTotal)
            // Currency is READ from the charges' own tariff catalog, never hardcoded or guessed.
            ->where('capturedTotal.currency', 'EUR')
            ->where('capturedTotal.formatted', 'EUR 25.00')
            ->has('charges', 1)
            ->where('charges.0.amount_formatted', 'EUR 25.00')
            ->where('charges.0.rate_formatted', 'EUR 25.00')
            // THE RATE IS NOT SHIPPED AS A NUMBER: the page cannot multiply what it does not have.
            ->missing('charges.0.unit_price_minor')
            // …and no invoice exists, so the "Issued" figure must be absent entirely.
            ->where('invoice', null));
});

test('P8-C1: the RADIOLOGY billing screen shows the ENGINE total, and it ties to the engine\'s own sum', function () {
    $fx = lrbFixture('lrb-rad-total');
    $charge = app(RadiologyBillingService::class)->chargeOrder($fx['biller'], $fx['radOrder']);

    $engineTotal = (int) Charge::query()->whereKey($charge->id)->sum('line_total_minor');
    expect($engineTotal)->toBe(4500);

    app(TenantContext::class)->forget();
    test()->actingAs($fx['biller'])
        ->get('/radiology/orders/'.$fx['radOrder']->id.'/billing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Radiology/Billing')
            ->where('capturedTotal.minor', $engineTotal)
            ->where('capturedTotal.currency', 'EUR')
            ->where('capturedTotal.formatted', 'EUR 45.00')
            ->has('charges', 1)
            ->where('charges.0.amount_formatted', 'EUR 45.00')
            ->missing('charges.0.unit_price_minor')
            ->where('invoice', null));
});

test('P8-C1: an ISSUED invoice shows the INVOICE total — the authoritative figure, formatted, with a real link', function () {
    $fx = lrbFixture('lrb-issued');
    app(LabBillingService::class)->chargeOrder($fx['biller'], $fx['labOrder']);
    $invoice = app(LabBillingService::class)->invoiceOrder($fx['biller'], $fx['labOrder']->fresh());

    $expected = app(ChargeSetReader::class)->format((int) $invoice->total_minor, (string) $invoice->currency);

    app(TenantContext::class)->forget();
    test()->actingAs($fx['biller'])
        ->get('/lab/orders/'.$fx['labOrder']->id.'/billing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoice.total_formatted', $expected)
            ->where('invoice.id', $invoice->id)
            // The dead self-link is gone: the URL is the INVOICE's, not this page's.
            ->where('invoice.url', route('billing.invoices.show', $invoice->id))
            // And the raw integer is no longer shipped, so nothing can divide it by 100 again.
            ->missing('invoice.total_minor'));

    // THE FIGURE IS NOT "NaN" AND NOT EMPTY — the literal symptom, asserted on the string itself.
    expect($expected)->not->toContain('NaN')->and($expected)->toContain('EUR');
});

// ------------------------------------------------------- STRUCTURAL GUARDS ----

test('P8-C1 STRUCTURAL GUARD: neither page shadows the `invoice` prop with a function of the same name', function () {
    // A payload assertion CANNOT see this (the QA-FIX.5a lesson): the props are byte-identical whether or
    // not a same-named function shadows one of them in the TEMPLATE. The defect lived entirely inside the
    // SFC. Mutation-checked: restoring `function invoice(` in either file reddens this test.
    foreach (['Lab', 'Radiology'] as $module) {
        $vue = lrbCode($module);

        expect($vue)->not->toContain('function invoice(')
            ->and($vue)->toContain('function issueInvoice(')
            ->and($vue)->toContain('@click="issueInvoice"')
            // The prop must still exist — otherwise this passes on a page that simply deleted it.
            ->and($vue)->toContain('invoice: { id: string; url: string; total_formatted: string } | null');
    }
});

test('P8-C1: the ISSUE-INVOICE control renders on a charged, uninvoiced order — it was unreachable before', function () {
    // THE PROPERTY (D-182). `v-if="isCharged && !invoice"` was permanently false, so this button did not
    // exist for anyone; issuing an outpatient lab/imaging invoice was impossible through the product. The
    // guard is structural for the same reason as above, and the gate report carries the browser proof.
    // Mutation-checked: restoring the `isCharged && !invoice` guard reddens this.
    foreach (['Lab', 'Radiology'] as $module) {
        $vue = lrbCode($module);

        // The button branch is reached whenever there are charges and no invoice…
        expect($vue)->toContain('<div v-if="isCharged" class="mt-4">')
            ->and($vue)->toContain('v-else-if="actions.can_bill"')
            // …and the "Issued" side is the Link, chosen by the PROP.
            ->and($vue)->toContain('<Link v-if="invoice" :href="invoice.url"');

        // AND THE COMBINATION THAT USED TO BE DEAD IS NOW LIVE. `isCharged && !invoice` is not the defect
        // and never was — the defect was the SHADOWING that made `!invoice` permanently false. With the
        // prop unshadowed the expression means exactly what it says, and the inpatient note it guards
        // (explaining why an admitted patient gets no order-level invoice) can finally appear.
        expect($vue)->toContain('v-if="isCharged && !invoice"');
    }
});

test('P8-C1: the "Issued" figure does not render on an uninvoiced order', function () {
    // The page used to print "Issued invoice · Total: NaN" beneath "No charge captured yet." The total row
    // now renders only when there ARE charges, and which label it uses is chosen by the prop.
    foreach (['Lab', 'Radiology'] as $module) {
        $vue = lrbCode($module);
        $key = strtolower($module);

        expect($vue)->toContain('<div v-if="isCharged" class="mt-4 flex items-center justify-between')
            ->and($vue)->toContain("invoice ? t('{$key}.billing.total') : t('{$key}.billing.estimate')")
            ->and($vue)->toContain('invoice ? invoice.total_formatted : capturedTotal.formatted');
    }
});

test('the two totals are labelled differently, because they are different figures', function () {
    // Making the figure VISIBLE means making it accurate. Pre-invoice the number is the net (ex-VAT) Σ of
    // THIS order's lines. Once issued it is the INVOICE's total, and `invoiceOrder()` gathers every
    // validated, uninvoiced charge for the patient across the whole service DAY, so it can legitimately
    // exceed those lines. Labelling both "Total" would assert that the number sums the list it sits under.
    // Mutation-checked: setting either `total` back to "Total" reddens this.
    $lang = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);

    foreach (['lab', 'radiology'] as $module) {
        expect($lang[$module]['billing']['total'])->toBe('Invoice total')
            ->and($lang[$module]['billing']['estimate'])->toBe('Estimated total')
            ->and($lang[$module]['billing']['total'])->not->toBe($lang[$module]['billing']['estimate'])
            // The table column is the engine's LINE amount and is named as such, not "Estimate".
            ->and($lang[$module]['billing']['amount'])->toBe('Amount');
    }
});

test('NO LAB OR RADIOLOGY SURFACE COMPUTES OR FORMATS DISPLAYED MONEY — the Phase-3 rule, extended to the Vue', function () {
    // The existing PHP fences scan Modules/Lab/src and Modules/Radiology/src only — which is exactly how a
    // page-side sum over money reached both shipped pages and survived every suite for eight phases. This
    // is that fence for the SURFACES. Mutation-checked: restoring the `estimateMinor` reduce or the local
    // `money()` helper in either file reddens this test.
    $forbidden = [
        '.reduce(',          // a page-side sum — the defect PatientBalanceReader was written to end
        'unit_price_minor',  // the rate as a NUMBER: a page holding it can multiply by it
        'Intl.NumberFormat', // formatting money client-side instead of receiving it formatted
        '/ 100',             // the minor-units division
        'toFixed(',          // its fallback
    ];

    $files = array_merge(glob(resource_path('js/pages/Lab/*.vue')), glob(resource_path('js/pages/Radiology/*.vue')));
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        foreach ($forbidden as $needle) {
            expect(str_contains($src, $needle))
                ->toBeFalse('A Lab/Radiology surface must not compute or format displayed money ('.$needle.') — found in '.basename($file));
        }
    }

    // POSITIVE SIDE: the two money surfaces must actually RENDER the server-formatted strings, so this test
    // cannot be satisfied by pages that simply stopped showing money at all.
    foreach (['Lab', 'Radiology'] as $module) {
        $billing = (string) file_get_contents(resource_path("js/pages/{$module}/Billing.vue"));
        expect($billing)->toContain('c.amount_formatted')
            ->and($billing)->toContain('c.rate_formatted')
            ->and($billing)->toContain('capturedTotal.formatted')
            ->and($billing)->toContain('invoice.total_formatted');
    }
});

test('THE MODULES STILL NAME NO MONEY COLUMN — the existing byte fences stay green', function () {
    // The reason ChargeSetReader lives in Modules/Billing. If a later change reads the stored total
    // directly in a Lab/Radiology controller, THIS fails here as well as in the module's own suite.
    foreach (['Lab', 'Radiology'] as $module) {
        $files = collect(File::allFiles(base_path("Modules/{$module}/src")))
            ->filter(fn ($f): bool => $f->getExtension() === 'php');

        foreach ($files as $file) {
            foreach (['line_total_minor', 'vat_total_minor', 'subtotal_minor', 'intdiv('] as $needle) {
                expect(str_contains(File::get($file->getPathname()), $needle))
                    ->toBeFalse("{$module} must not compute billing money ({$needle}) — {$file->getRelativePathname()}");
            }
        }
    }
});

// ------------------------------------------------------- POSITIVE CONTROL ----

test('POSITIVE CONTROL — the Surgery billing surface is untouched by this part', function () {
    // QA-FIX.6a fixed the same defect there. This part must not have regressed it while copying the remedy.
    $vue = (string) file_get_contents(resource_path('js/pages/Surgery/CaseBilling.vue'));

    expect($vue)->not->toContain('function invoice(')
        ->and($vue)->toContain('function issueInvoice(')
        ->and($vue)->toContain('capturedTotal.formatted')
        ->and($vue)->toContain('invoice.total_formatted');

    $lang = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);
    expect($lang['surgery']['billing']['total'])->toBe('Invoice total')
        ->and($lang['surgery']['billing']['estimate'])->toBe('Estimated total');
});
