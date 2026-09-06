<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAllocation;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PaymentService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Reporting\Services\MetricsService;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| QA-FIX.3b — the two COLLECTED figures say which basis they are (P3-H1)
|--------------------------------------------------------------------------
| Phase 3 measured /billing/aging showing "COLLECTED (MONTH TO DATE) 1066.53"
| while /billing/report showed "COLLECTED (PERIOD) 1114.56" on the same day.
| BOTH ARE CORRECT for their own question:
|
|   received : MetricsService::paymentsReceivedTotalMinor() — "sum of payments
|              with `received_on` in the range … refunds are separate rows in
|              their own table and are not netted here"
|   applied  : MetricsService::netCollectionsMinor() — "net payment allocations
|              applied in a period (reversals net out) — the collections that
|              reduce AR"
|
| This is a LABELLING fix. Neither engine method's definition changed, and these
| tests pin that: a mutation swapping one basis for the other turns them red.
*/

const CBL_PERIOD_FROM = '2026-06-01';
const CBL_PERIOD_TO = '2026-06-30';

function cblFixture(): array
{
    $tenant = Tenant::query()->create(['name' => 'Basis Care', 'slug' => 'basis', 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $actor = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $actor->id,
        'role_id' => Role::query()->where('key', 'billing')->firstOrFail()->id,
    ]);

    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'BASI', 'timezone' => 'Europe/Zurich']);
    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1,
        'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => [],
    ]);

    return compact('tenant', 'actor', 'branch', 'catalog');
}

function cblInvoice(array $fx, Patient $patient, int $priceMinor): Invoice
{
    static $seq = 91000;
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $fx['catalog']->id, 'code' => (string) (++$seq), 'description' => 'Consultation',
        'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);
    $charge = Charge::query()->create([
        'patient_id' => $patient->id, 'branch_id' => $fx['branch']->id, 'service_date' => '2026-06-01',
        'tariff_catalog_id' => $fx['catalog']->id, 'tariff_item_id' => $item->id, 'code' => $item->code,
        'description' => $item->description, 'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0,
        'quantity' => 1, 'line_total_minor' => $priceMinor, 'status' => Charge::STATUS_VALIDATED,
        'created_by' => $fx['actor']->id,
    ]);
    $service = app(IssueService::class);

    return $service->issue(
        $service->createDraftFromCharges(
            $patient, [$charge], $fx['actor'], Invoice::PAYER_SELF_PAY, null,
            Carbon::parse('2026-06-01'), Carbon::parse('2026-06-20'),
        ),
        $fx['actor'],
    );
}

function cblPatient(): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Bea', 'last_name' => 'Basis', 'date_of_birth' => '1980-02-02', 'sex' => 'female',
    ]);
}

beforeEach(fn () => Carbon::setTestNow('2026-06-25 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

test('the aging page names its basis on screen — cash RECEIVED, by payment date', function () {
    $fx = cblFixture();

    $this->actingAs($fx['actor'])
        ->get(route('billing.aging'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Billing/Aging'));

    // GOV.P3: assert the RENDERED caption, not just that a source string exists somewhere.
    $lang = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);

    expect($lang['billing']['aging']['collectedMtd'])->toBe('Cash received (month to date)')
        ->and($lang['billing']['aging']['collectedMtdBasis'])
        ->toContain('by payment date')
        ->toContain('not netted')
        ->toContain('not the same as collections applied to invoices');
});

test('the management report names BOTH bases on screen — applied, and received', function () {
    $fx = cblFixture();

    $this->actingAs($fx['actor'])
        ->get(route('billing.report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Billing/Report'));

    $lang = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);
    $cards = $lang['billing']['report']['cards'];

    // The APPLIED figure says so, in the engine's own terms.
    expect($cards['collectedBasis'])
        ->toContain('applied to invoices')
        ->toContain('by allocation date')
        ->toContain('Reversals net out')
        ->toContain('reduces AR');

    // The RECEIVED figure says so too, and says it may include unapplied money.
    expect($cards['periodCollectedBasis'])
        ->toContain('by payment date')
        ->toContain('not yet applied');
});

test('the caption is actually rendered by each page, not merely present in the language file', function () {
    $fx = cblFixture();

    // Both pages must actually RENDER (a caption on a broken page is worth nothing)…
    $this->actingAs($fx['actor'])->get(route('billing.aging'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Billing/Aging'));
    $this->actingAs($fx['actor'])->get(route('billing.report'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Billing/Report'));

    // …and the templates must BIND the caption keys. A string added to en.json but never bound
    // would satisfy the language-file assertions above while changing nothing on screen.
    $aging = (string) file_get_contents(resource_path('js/pages/Billing/Aging.vue'));
    $report = (string) file_get_contents(resource_path('js/pages/Billing/Report.vue'));

    expect($aging)->toContain("t('billing.aging.collectedMtdBasis')")
        ->and($report)->toContain("t('billing.report.cards.collectedBasis')")
        ->and($report)->toContain("t('billing.report.cards.periodCollectedBasis')");
});

test('the two engine methods keep their DEFINITIONS — received counts payments, applied counts allocations', function () {
    $fx = cblFixture();
    $patient = cblPatient();
    $invoice = cblInvoice($fx, $patient, 30000);
    $metrics = app(MetricsService::class);
    $payments = app(PaymentService::class);

    // A payment received in the period and FULLY applied: both bases see it.
    $payment = $payments->record(30000, Payment::METHOD_BANK_TRANSFER, $fx['actor'], $patient, null, $invoice->currency, '2026-06-10');
    $payments->allocate($payment, $invoice, 30000, $fx['actor']);

    $received = $metrics->paymentsReceivedTotalMinor($fx['actor'], CBL_PERIOD_FROM, CBL_PERIOD_TO);
    $applied = (int) $metrics->arRollForward($fx['actor'], CBL_PERIOD_FROM, CBL_PERIOD_TO)['collections_minor'];

    expect($received)->toBe(30000)
        ->and($applied)->toBe(30000);

    // A mutation that swapped one method for the other would still pass HERE — which is exactly why
    // the next test exists: it drives the two figures APART.
});

test('POSITIVE CONTROL — an UNALLOCATED payment moves the RECEIVED figure and NOT the applied one', function () {
    $fx = cblFixture();
    $patient = cblPatient();
    $invoice = cblInvoice($fx, $patient, 30000);
    $metrics = app(MetricsService::class);
    $payments = app(PaymentService::class);

    $payments->record(30000, Payment::METHOD_BANK_TRANSFER, $fx['actor'], $patient, null, $invoice->currency, '2026-06-10');
    $payments->allocate(
        $payments->record(10000, Payment::METHOD_CASH, $fx['actor'], $patient, null, $invoice->currency, '2026-06-11'),
        $invoice, 10000, $fx['actor'],
    );

    $received = $metrics->paymentsReceivedTotalMinor($fx['actor'], CBL_PERIOD_FROM, CBL_PERIOD_TO);
    $applied = (int) $metrics->arRollForward($fx['actor'], CBL_PERIOD_FROM, CBL_PERIOD_TO)['collections_minor'];

    // CHF 400.00 received, only CHF 100.00 applied. The two figures are genuinely different
    // quantities, so each label is a claim that can be checked — and a mutation swapping the bases
    // makes one of these assertions fail.
    expect($received)->toBe(40000)
        ->and($applied)->toBe(10000)
        ->and($received)->not->toBe($applied);

    // And the difference is exactly the money that was never applied.
    $unallocated = 40000 - (int) PaymentAllocation::query()->sum('amount_minor');
    expect($unallocated)->toBe(30000);
});

test('EACH PAGE RENDERS THE BASIS ITS CAPTION CLAIMS — the label is a checkable claim, not decoration', function () {
    $fx = cblFixture();
    $patient = cblPatient();
    $invoice = cblInvoice($fx, $patient, 30000);
    $payments = app(PaymentService::class);

    // Drive the two bases APART: CHF 400.00 received, only CHF 100.00 applied.
    $payments->record(30000, Payment::METHOD_BANK_TRANSFER, $fx['actor'], $patient, null, $invoice->currency, '2026-06-10');
    $payments->allocate(
        $payments->record(10000, Payment::METHOD_CASH, $fx['actor'], $patient, null, $invoice->currency, '2026-06-11'),
        $invoice, 10000, $fx['actor'],
    );

    // The aging page's caption says "money received, by payment date" — so its figure must be the
    // RECEIVED one (40000) and NOT the applied one (10000). Swapping the controller to the other
    // engine method turns this red; without it the caption would be an unchecked claim.
    $this->actingAs($fx['actor'])
        ->get(route('billing.aging'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Aging')
            ->where('monthToDate.collected_minor', 40000));

    // The report's "Collected (period)" card is the APPLIED basis, and its "Cash received" sub-line
    // (under "Invoiced (period)") is the RECEIVED one. Both appear on the same page and must stay
    // distinct.
    //
    // `collection_rate.collections_minor` is asserted BY NAME because it is the prop the
    // "Collected (period)" card actually renders (Report.vue:227) — the one the new caption sits
    // under. It happens to equal the roll-forward figure (both call netCollectionsMinor), but
    // pinning the sibling instead of the captioned prop would repeat the very gap this test exists
    // to close: a caption is only checked when the number BELOW it is the number asserted.
    $this->actingAs($fx['actor'])
        ->get(route('billing.report', ['period' => 'month']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Report')
            ->where('report.collection_rate.collections_minor', 10000)
            ->where('report.roll_forward.collections_minor', 10000)
            ->where('report.collected_minor', 40000));
});
