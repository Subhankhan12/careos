<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
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
 * BILLAR.P6 — the Billing & AR management-report grid. These tests prove the FENCE: the
 * page DISPLAYS the P1–P5 MetricsService figures verbatim (never recomputed in the page),
 * the period switcher RE-PARAMETERIZES the engine (a different period yields the engine's
 * figures for that period), the CSV export streams the same engine figures, RBAC is
 * billing.view + tenant-scoped, and the consolidated page does NOT break the live
 * aging/reporting/dunning surfaces. No existing behaviour test is touched.
 */

function brpUser(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, catalog: TariffCatalog}
 */
function brpFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $actor = brpUser($tenant);
    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $patient = app(PatientService::class)->create(['first_name' => 'Report', 'last_name' => 'Grid', 'date_of_birth' => '1985-01-01', 'sex' => 'male']);
    $catalog = TariffCatalog::query()->create(['key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1, 'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => []]);

    return compact('tenant', 'actor', 'branch', 'patient', 'catalog');
}

function brpInvoice(array $fx, int $priceMinor, string $issueDate, string $payerType = Invoice::PAYER_SELF_PAY): Invoice
{
    static $codeSeq = 45000; // deterministic-unique tariff code (random_int(10,99) collides across a test)
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $fx['catalog']->id, 'code' => (string) (++$codeSeq), 'description' => 'Consultation',
        'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);
    $charge = Charge::query()->create([
        'patient_id' => $fx['patient']->id, 'branch_id' => $fx['branch']->id, 'service_date' => $issueDate,
        'tariff_catalog_id' => $fx['catalog']->id, 'tariff_item_id' => $item->id, 'code' => $item->code,
        'description' => $item->description, 'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0,
        'quantity' => 1, 'line_total_minor' => $priceMinor, 'status' => Charge::STATUS_VALIDATED, 'created_by' => $fx['actor']->id,
    ]);
    $service = app(IssueService::class);

    return $service->issue(
        $service->createDraftFromCharges($fx['patient'], [$charge], $fx['actor'], $payerType, null, Carbon::parse($issueDate), Carbon::parse($issueDate)->addDays(14)),
        $fx['actor'],
    );
}

function brpCollect(array $fx, Invoice $invoice, int $amount): void
{
    $payments = app(PaymentService::class);
    $payment = $payments->record($amount, 'bank_transfer', $fx['actor'], $fx['patient'], null, 'EUR', now());
    $payments->allocate($payment, $invoice, $amount, $fx['actor']);
}

/** Seed a mix of AR across two payer types + one earlier-in-year invoice (so month ≠ YTD). */
function brpSeedAr(array $fx): void
{
    $a = brpInvoice($fx, 20000, '2026-06-05', Invoice::PAYER_SELF_PAY);
    brpCollect($fx, $a, 5000);
    $b = brpInvoice($fx, 30000, '2026-06-08', Invoice::PAYER_PRIVATE_INSURANCE);
    brpCollect($fx, $b, 10000);
    brpInvoice($fx, 15000, '2026-02-10', Invoice::PAYER_SELF_PAY); // earlier in the year, uncollected
}

beforeEach(fn () => Carbon::setTestNow('2026-06-15 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

// ── The grid displays the P1–P5 engine figures verbatim (displayed === MetricsService) ───────────

test('the report renders the P1-P5 engine figures exactly (displayed === MetricsService)', function () {
    $fx = brpFixture();
    brpSeedAr($fx);

    $svc = app(MetricsService::class);
    $from = now()->startOfMonth();
    $to = now();

    // The engine values for the default (month) period.
    $rollForward = $svc->arRollForward($fx['actor'], $from, $to);
    $byPayer = $svc->arByPayer($fx['actor'], $from, $to);
    $trend = $svc->chargedVsCollectedTrend($fx['actor'], $from, $to, 'week');
    $rate = $svc->netCollectionRate($fx['actor'], $from, $to);
    $dso = $svc->daysSalesOutstanding($fx['actor'], $from, $to);
    $totalAr = $svc->outstandingBalanceMinor($fx['actor']);
    $aging = $svc->agingBuckets($fx['actor'], $to);

    $this->actingAs($fx['actor'])
        ->get(route('billing.report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Report')
            ->where('period', 'month')
            // P1/P2 roll-forward — the whole tying bridge, displayed as the engine returns it.
            ->where('report.roll_forward', $rollForward)
            ->where('report.roll_forward.ties', true)
            // headline / aging (point-in-time engine figures)
            ->where('report.total_ar_minor', $totalAr)
            ->where('report.aging', $aging)
            ->where('report.aging.days_90_plus', $aging['days_90_plus'])
            // P3 DSO + collection rate (assert DSO via its integer inputs — the ratio is a
            // float the engine rounds, and JSON collapses e.g. 15.0 → 15 on the round-trip).
            ->where('report.dso.ar_minor', $dso['ar_minor'])
            ->where('report.dso.credit_sales_minor', $dso['credit_sales_minor'])
            ->where('report.dso.days', $dso['days'])
            ->where('report.collection_rate.rate', $rate['rate'])
            ->where('report.collection_rate.collectible_minor', $rate['collectible_minor'])
            // P4 by-payer (real groups tie to total)
            ->where('report.by_payer', $byPayer)
            ->where('report.by_payer.ties', true)
            // P5 trend (buckets partition the range)
            ->where('report.trend', $trend)
            ->where('report.trend.partitions', true));
});

// ── The period switcher re-parameterizes the engine (server recomputes) ──────────────────────────

test('switching the period re-parameterizes the engine (a different period yields the engine figures for that period)', function () {
    $fx = brpFixture();
    brpSeedAr($fx);

    $svc = app(MetricsService::class);
    $ytdFrom = now()->startOfYear();
    $to = now();
    $ytdRoll = $svc->arRollForward($fx['actor'], $ytdFrom, $to);

    // month period: charges = the two June invoices only (50000).
    $this->actingAs($fx['actor'])->get(route('billing.report', ['period' => 'month']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.period.from', now()->startOfMonth()->toDateString())
            ->where('report.roll_forward.charges_minor', 50000));

    // YTD period: the range widened server-side to the year start, so the February invoice
    // is now included (charges = 65000) — the figures are the ENGINE's for the new range,
    // not a client re-slice of the month payload.
    $this->actingAs($fx['actor'])->get(route('billing.report', ['period' => 'ytd']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period', 'ytd')
            ->where('report.period.from', $ytdFrom->toDateString())
            ->where('report.roll_forward', $ytdRoll)
            ->where('report.roll_forward.charges_minor', 65000));
});

// ── Compare fetches two periods from the engine and displays both ─────────────────────────────────

test('compare fetches the previous period from the engine and displays both', function () {
    $fx = brpFixture();
    brpSeedAr($fx);

    $svc = app(MetricsService::class);
    $prevRoll = $svc->arRollForward($fx['actor'], now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth());

    $this->actingAs($fx['actor'])->get(route('billing.report', ['period' => 'month', 'compare' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('compareOn', true)
            ->where('compare.roll_forward', $prevRoll)
            ->where('compare.period.from', now()->subMonthNoOverflow()->startOfMonth()->toDateString()));
});

// ── CSV export streams the engine figures ────────────────────────────────────────────────────────

test('the CSV export contains the engine figures', function () {
    $fx = brpFixture();
    brpSeedAr($fx);

    $svc = app(MetricsService::class);
    $from = now()->startOfMonth();
    $to = now();
    $roll = $svc->arRollForward($fx['actor'], $from, $to);
    $totalAr = $svc->outstandingBalanceMinor($fx['actor']);

    $response = $this->actingAs($fx['actor'])->get(route('billing.report.export', ['period' => 'month']));
    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();
    expect($csv)->toContain('total_ar_minor')
        ->and($csv)->toContain((string) $totalAr)
        ->and($csv)->toContain((string) $roll['closing_minor'])
        ->and($csv)->toContain('by_payer:self_pay')
        ->and($csv)->toContain('roll_forward');
});

// ── RBAC billing.view + tenant-scoped ────────────────────────────────────────────────────────────

test('the report is gated on billing.view and is tenant-scoped', function () {
    $fx = brpFixture('alpha');
    brpSeedAr($fx);

    // A non-billing role is refused (both the page and the export).
    $reception = brpUser($fx['tenant'], 'reception');
    $this->actingAs($reception)->get(route('billing.report'))->assertForbidden();
    $this->actingAs($reception)->get(route('billing.report.export'))->assertForbidden();

    // A second tenant sees an all-zero report that still ties (fail-closed tenant scope).
    $beta = brpFixture('beta');
    $this->actingAs($beta['actor'])->get(route('billing.report'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.total_ar_minor', 0)
            ->where('report.roll_forward.closing_minor', 0)
            ->where('report.roll_forward.ties', true)
            ->where('report.by_payer.groups', [])
            ->where('report.by_payer.ties', true));
});

// ── The consolidated page does NOT break the live aging/reporting/dunning surfaces ───────────────

test('the consolidated report does not break the existing aging, reporting and dunning routes', function () {
    $fx = brpFixture();
    brpSeedAr($fx);

    // org_admin holds billing.view + reporting.view, so all three live surfaces still render.
    $admin = brpUser($fx['tenant'], 'org_admin');

    $this->actingAs($admin)->get(route('billing.aging'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Billing/Aging'));
    $this->actingAs($admin)->get(route('billing.dunning.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Billing/Dunning/Index'));
    $this->actingAs($admin)->get(route('reporting.dashboard'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Reporting/Dashboard'));
});
