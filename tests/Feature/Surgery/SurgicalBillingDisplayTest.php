<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Exceptions\TariffNotFoundForDateException;
use Modules\Billing\Models\Charge;
use Modules\Billing\Services\ChargeSetReader;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalCaseCharge;
use Modules\Surgery\Services\SurgicalBillingService;
use Modules\Surgery\Services\SurgicalCaseService;
use Modules\Surgery\Services\SurgicalStockService;
use Modules\Surgery\Services\SurgicalUsageService;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| QA-FIX.6a — P6-C1 (the engine total renders) + P6-M10 (capture is atomic)
|--------------------------------------------------------------------------
| P6-C1: `CaseBilling.vue` declared the PROP `invoice` AND a top-level
| `function invoice()`. In <script setup> the function shadows the prop in the
| template, and a function is always truthy — so the capture form's
| `v-if="!invoice"` was permanently false, the issue-invoice button was
| unreachable, "View invoice" pointed at the current page, and the total
| rendered `money(invoice.total_minor)` on a FUNCTION: literally "NaN" over a
| real CHF 2,974.00 case, driven in a browser.
|
| The same page also derived its own money — `quantity × unit_price_minor` per
| line and a `.reduce()` for the total — a SECOND derivation of figures the
| engine had already computed and stored in `charges.line_total_minor`. Phase 3
| proved zero `.reduce(` across the twelve Billing surfaces; this was the one.
|
| P6-M10: `chargeCase()` captured every charge first (each committing in its own
| transaction inside `ChargeCaptureService::capture()`) and wrote the link rows
| afterwards. Those links ARE the idempotency key, so a throw partway through
| orphaned the earlier charges AND left the guard reading empty — a retry
| re-billed what had already succeeded. It was latent only because P6-C1 made
| the capture control unreachable, so fixing C1 alone would have switched on a
| defect nobody had ever run. Both are fixed here, in that order.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Self-contained fixture (deliberately not reusing SurgicalBillingTest's helpers: Pest declares those at
 * file scope, so depending on them across files makes this suite sensitive to load order).
 *
 * @return array{tenant: Tenant, branch: Branch, surgeon: User, biller: User, case: SurgicalCase}
 */
function sbdFixture(string $slug): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);

    $make = function (string $role) use ($tenant): User {
        $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

        return $user;
    };
    $surgeon = $make('surgeon');    // surgery.manage + note.write — NOT billing.manage
    $biller = $make('org_admin');   // the only role holding billing.manage

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

    return compact('tenant', 'branch', 'surgeon', 'biller', 'case');
}

/** Drive the case to `completed` through the real lifecycle. */
function sbdComplete(array $fx): SurgicalCase
{
    $cases = app(SurgicalCaseService::class);
    foreach ([SurgicalCase::STATUS_PRE_OP, SurgicalCase::STATUS_IN_PROGRESS, SurgicalCase::STATUS_COMPLETED] as $to) {
        $cases->transition($fx['surgeon'], $fx['case']->fresh(), $to);
    }

    return $fx['case']->fresh();
}

/**
 * Price the bundle, use a consumable ×3, complete, and capture.
 * 250000 (procedure) + 30000 (theatre ×60) + 2400 (gauze ×3) = 282400 — D-174 non-trivial.
 *
 * @return Collection<int, Charge>
 */
function sbdCompleteAndCharge(array $fx): Collection
{
    $svc = app(SurgicalBillingService::class);
    $svc->priceProcedure($fx['biller'], 'APPEND-01', 'Laparoscopic appendectomy', 250000);
    $svc->priceTheatreTime($fx['biller'], 500);

    $stock = app(SurgicalStockService::class);
    $gauze = $stock->createItem($fx['surgeon'], 'SUT-GAUZE', 'Gauze swab', false);
    $stock->receive($fx['surgeon'], $gauze, 100);
    $svc->priceItem($fx['biller'], $gauze, 800);
    app(SurgicalUsageService::class)->recordUsage($fx['surgeon'], $fx['case'], $gauze, 3);

    sbdComplete($fx);

    return $svc->chargeCase($fx['biller'], $fx['case']->fresh(), 'APPEND-01', 60);
}

// ---------------------------------------------------------------- P6-C1 ----

test('P6-C1: the case-billing screen shows the ENGINE total, and it ties to Σ line_total_minor', function () {
    $fx = sbdFixture('sbd-total');
    $charges = sbdCompleteAndCharge($fx);

    $engineTotal = (int) Charge::query()->whereIn('id', $charges->pluck('id')->all())->sum('line_total_minor');
    expect($engineTotal)->toBe(282400); // D-174: a non-trivial amount, not 0 and not a round 1.00

    test()->actingAs($fx['biller'])
        ->get('/surgery/cases/'.$fx['case']->id.'/billing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Surgery/CaseBilling')
            // The figure the page shows IS the engine's, not a re-derivation of it.
            ->where('capturedTotal.minor', $engineTotal)
            // The currency is READ from the charges' own tariff catalog, never hardcoded: this tenant is
            // EUR (the settings default) while the demo hospital is CHF, and the same code yields both.
            ->where('capturedTotal.currency', 'EUR')
            ->where('capturedTotal.formatted', "EUR 2'824.00")
            ->has('charges', 3)
            // Each line carries the engine's stored amount, already formatted…
            ->where('charges.0.amount_minor', 250000)
            ->where('charges.0.amount_formatted', "EUR 2'500.00")
            // …and the RATE is not shipped at all: the page cannot multiply what it does not have.
            ->missing('charges.0.unit_price_minor'));
});

test('P6-C1: an ISSUED invoice shows the invoice total — the authoritative figure, not the estimate', function () {
    $fx = sbdFixture('sbd-invoiced');
    sbdCompleteAndCharge($fx);

    $invoice = app(SurgicalBillingService::class)->invoiceCase($fx['biller'], $fx['case']->fresh());

    test()->actingAs($fx['biller'])
        ->get('/surgery/cases/'.$fx['case']->id.'/billing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // The engine's own invoice total, formatted through the same formatter as the lines.
            ->where('invoice.total_formatted', app(ChargeSetReader::class)
                ->format((int) $invoice->total_minor, (string) $invoice->currency))
            ->where('invoice.url', route('billing.invoices.show', $invoice->id))
            // The dead self-link is gone: the URL is the invoice's, not this page's.
            ->where('invoice.id', $invoice->id));
});

test('P6-C1 STRUCTURAL GUARD: the `invoice` prop is never shadowed by a function of the same name', function () {
    // A payload assertion CANNOT see this (the QA-FIX.5a lesson): the props are byte-identical whether or
    // not a same-named function shadows one of them in the TEMPLATE. The defect lived entirely inside the
    // SFC, so the guard is structural and the browser verification in the gate report is the proof.
    // Mutation-checked: restoring `function invoice(` reddens this test.
    $vue = (string) file_get_contents(resource_path('js/pages/Surgery/CaseBilling.vue'));

    expect($vue)->not->toContain('function invoice(')
        ->and($vue)->toContain('function issueInvoice(')
        ->and($vue)->toContain('@click="issueInvoice"');

    // The prop must still exist — otherwise this passes on a page that simply deleted it.
    expect($vue)->toContain('invoice: { id: string; url: string; total_formatted: string } | null');

    // And the template must still branch on the prop, so "Total" vs "Estimate" stays honest.
    expect($vue)->toContain("invoice ? t('surgery.billing.total') : t('surgery.billing.estimate')");
});

test('the two totals are labelled differently, because they are different figures', function () {
    // Making the figure VISIBLE (P6-C1) means making it accurate. Pre-invoice the number is the net Σ of
    // THIS CASE's charges — the lines directly above it. Once issued it is the INVOICE's total, and
    // `invoiceCase()` gathers every validated, uninvoiced charge for the patient across the whole service
    // DAY, so it can legitimately exceed those lines. Labelling both "Total" would assert that the number
    // sums the list it sits under. Mutation-checked: setting `total` back to "Total" reddens this.
    $lang = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);

    expect($lang['surgery']['billing']['total'])->toBe('Invoice total')
        ->and($lang['surgery']['billing']['estimate'])->toBe('Estimated total')
        // The two strings must stay distinguishable — the whole point is that they name different things.
        ->and($lang['surgery']['billing']['total'])->not->toBe($lang['surgery']['billing']['estimate']);
});

test('NO SURGERY SURFACE COMPUTES OR FORMATS DISPLAYED MONEY — the Phase-3 BILLAR rule, extended to the Vue', function () {
    // The existing PHP fence (SurgicalBillingTest) scans `Modules/Surgery/src` only — which is exactly how a
    // page-side sum over money reached a shipped Surgery page and survived every suite. This is that fence
    // for the surfaces. Mutation-checked: restoring the old `estimateMinor` reduce, or the old `fmt()`
    // helper on the pricing page, reddens this test.
    //
    // WHAT IS FORBIDDEN IS THE DERIVATION OF A FIGURE PRESENTED AS FACT. Each needle below is a way a page
    // can invent or restate a money figure the engine already owns.
    $forbidden = [
        '.reduce(',         // a page-side sum — the defect PatientBalanceReader was written to end
        'unit_price_minor', // the rate: a page holding it can multiply by it
        'toLocaleString',   // formatting money client-side instead of receiving it formatted
    ];

    // `/ 100` IS DELIBERATELY NOT ON THAT LIST, and the reason is narrow and stated so a later reader knows
    // it was considered rather than missed. `SurgicalPricing.vue` populates an EDIT FIELD in major units
    // from `price_minor` and converts back on send. That is an input affordance on a value the user is
    // typing — not a figure asserted to them — and there is nowhere else for it to live short of the server
    // formatting form inputs. Every price that page DISPLAYS arrives as `price_formatted`, which the
    // assertions below pin. If a `/ 100` ever appears in a DISPLAY path again, the `toLocaleString` and
    // `price_formatted` guards are what should catch it.
    $files = glob(resource_path('js/pages/Surgery/*.vue'));
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        foreach ($forbidden as $needle) {
            expect(str_contains($src, $needle))
                ->toBeFalse('A Surgery surface must not compute or format displayed money ('.$needle.') — found in '.basename($file));
        }
    }

    // POSITIVE SIDE: the two money surfaces must actually RENDER the server-formatted strings, so this test
    // cannot be satisfied by a page that simply stopped showing money at all.
    $billing = (string) file_get_contents(resource_path('js/pages/Surgery/CaseBilling.vue'));
    expect($billing)->toContain('c.amount_formatted')
        ->and($billing)->toContain('capturedTotal.formatted')
        ->and($billing)->toContain('invoice.total_formatted');

    $pricing = (string) file_get_contents(resource_path('js/pages/Surgery/SurgicalPricing.vue'));
    expect($pricing)->toContain('shown(p.price_formatted)')
        ->and($pricing)->toContain('shown(item.price_formatted)');
});

// --------------------------------------------------------------- P6-M10 ----

test('P6-M10: a capture that fails partway leaves NOTHING behind — no orphan charge, no link', function () {
    $fx = sbdFixture('sbd-atomic');
    $svc = app(SurgicalBillingService::class);

    // Price the PROCEDURE but NOT theatre-time, then ask for both. Capture #1 succeeds; capture #2 raises
    // TariffNotFoundForDateException. Before the fix, #1 had already committed in its own transaction.
    $svc->priceProcedure($fx['biller'], 'APPEND-01', 'Laparoscopic appendectomy', 250000);
    sbdComplete($fx);

    expect(fn () => $svc->chargeCase($fx['biller'], $fx['case']->fresh(), 'APPEND-01', 60))
        ->toThrow(TariffNotFoundForDateException::class);

    // THE PROPERTY (D-182 — this FAILS before the fix, where it found 1 charge and 0 links).
    expect(Charge::query()->count())->toBe(0)
        ->and(SurgicalCaseCharge::query()->count())->toBe(0);
});

test('P6-M10: after a failed capture, a RETRY bills the case exactly once — no double-billing', function () {
    $fx = sbdFixture('sbd-retry');
    $svc = app(SurgicalBillingService::class);

    $svc->priceProcedure($fx['biller'], 'APPEND-01', 'Laparoscopic appendectomy', 250000);
    sbdComplete($fx);

    expect(fn () => $svc->chargeCase($fx['biller'], $fx['case']->fresh(), 'APPEND-01', 60))
        ->toThrow(TariffNotFoundForDateException::class);

    // The operator prices what was missing and retries — the natural response to the error.
    $svc->priceTheatreTime($fx['biller'], 500);
    $captured = $svc->chargeCase($fx['biller'], $fx['case']->fresh(), 'APPEND-01', 60);

    // Exactly ONE procedure charge. Before the fix the orphan survived AND the idempotency guard read
    // empty, so the retry captured a SECOND procedure charge: the guard permitted the double-billing it
    // exists to prevent.
    expect($captured)->toHaveCount(2)
        ->and(Charge::query()->where('code', 'APPEND-01')->count())->toBe(1)
        ->and(Charge::query()->count())->toBe(2)
        ->and(SurgicalCaseCharge::query()->count())->toBe(2)
        ->and((int) Charge::query()->sum('line_total_minor'))->toBe(280000);
});

test('P6-M10: every captured charge has its link row — the invariant that makes re-billing impossible', function () {
    $fx = sbdFixture('sbd-linked');
    $charges = sbdCompleteAndCharge($fx);

    $linked = SurgicalCaseCharge::query()->pluck('charge_id')->sort()->values()->all();
    expect($charges->pluck('id')->sort()->values()->all())->toBe($linked)
        // No charge exists outside the link table — an orphan is exactly what P6-M10 produced.
        ->and(Charge::query()->whereNotIn('id', $linked)->count())->toBe(0);
});

test('P6-M10: a FAILED capture writes no audit entry, and the hash chain still verifies', function () {
    $fx = sbdFixture('sbd-audit');
    $svc = app(SurgicalBillingService::class);

    $svc->priceProcedure($fx['biller'], 'APPEND-01', 'Laparoscopic appendectomy', 250000);
    sbdComplete($fx);

    // Wrapping the captures in an outer transaction turns the inner audit writes into savepoints, so a
    // rollback must take the audit rows with it — a charge that never happened must not be audited.
    $before = DB::table('audit_events')->count();

    expect(fn () => $svc->chargeCase($fx['biller'], $fx['case']->fresh(), 'APPEND-01', 60))
        ->toThrow(TariffNotFoundForDateException::class);

    expect(DB::table('audit_events')->count())->toBe($before)
        // …and the chain is still contiguous: no gap, no dangling prev_hash.
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();
});

test('POSITIVE CONTROL — a clean capture still produces the full set, is still idempotent, and still audits', function () {
    $fx = sbdFixture('sbd-clean');
    $svc = app(SurgicalBillingService::class);

    $first = sbdCompleteAndCharge($fx);
    expect($first)->toHaveCount(3);

    // Re-running returns the SAME charges and creates nothing new (the pre-existing guarantee, unchanged).
    $again = $svc->chargeCase($fx['biller'], $fx['case']->fresh(), 'APPEND-01', 60);
    expect($again->pluck('id')->sort()->values()->all())->toBe($first->pluck('id')->sort()->values()->all())
        ->and(Charge::query()->count())->toBe(3)
        ->and(SurgicalCaseCharge::query()->count())->toBe(3)
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();
});
