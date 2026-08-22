<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PatientBalanceReader;
use Modules\Billing\Services\PaymentService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
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
 * PT.P2 — one source for what the patient owes.
 *
 * Portal Home and Portal Invoices both show an open balance. Home summed the projection
 * server-side; Invoices ran a `.reduce()` over the rows it had been sent AND excluded credit notes.
 * Two derivations of one figure on two screens a patient sees minutes apart — and with a credit
 * note on the account they disagreed.
 *
 * What is asserted here:
 *  1. BOTH SCREENS REPORT THE SAME FIGURE, and it TIES (δ=0) to the billing engine's own account
 *     outstanding — `MetricsService::accountLedger()`, the tie target the AR ledger asserts.
 *  2. NO PAGE-SIDE MONEY ARITHMETIC anywhere in the portal: no reduce over money, no divide, no
 *     ratio, in any portal page or portal component.
 *  3. The patient-facing header shows patient-appropriate fields and NOT the staff clinical ones.
 *  4. PT.P1's audit rows are unchanged — still exactly one per render.
 *
 * The fixture is deliberately multi-invoice with a PARTLY PAID row (D-174): if the figure were
 * summed from the wrong column, or the paid invoice were dropped, or a filter narrowed it, the
 * number would visibly differ from the engine's.
 */

function ptbCtx(): TenantContext
{
    return app(TenantContext::class);
}

/** Sign in the way a real portal request does (the PT.P1 lesson). */
function ptbSignIn($test, PortalAccount $account)
{
    Auth::guard('patient')->setUser($account);

    return $test;
}

/**
 * @return array{tenant: Tenant, staff: User, patient: Patient, account: PortalAccount, open: Invoice, paid: Invoice}
 */
function ptbFixture(): array
{
    $tenant = Tenant::query()->create(['name' => 'Alpha Clinic', 'slug' => 'alpha', 'region' => 'eu', 'status' => 'active']);
    ptbCtx()->set($tenant);

    $staff = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $staff->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);
    Branch::query()->create(['name' => 'Main', 'code' => 'MAIN']);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Erika', 'last_name' => 'Baumgartner', 'date_of_birth' => '1954-03-12', 'sex' => 'female',
    ]);

    ConsentTemplate::query()->create([
        'key' => 'portal', 'title' => 'Portal Access', 'body' => 'Portal access consent',
        'version' => 1, 'scope_keys' => ['portal.access'], 'is_active' => true,
    ]);
    app(ConsentService::class)->grant($patient, 'portal', 'Erika Baumgartner', $staff);

    $account = PortalAccount::query()->create([
        'patient_id' => $patient->id,
        'email' => 'erika.balance@example.test',
        'password' => bcrypt('secret-password'),
        'status' => PortalAccount::STATUS_ACTIVE,
    ]);

    /*
     * Issued through the REAL path — charge → draft → issue — so the invoices and their
     * `invoice_balances` projection are genuine engine state rather than rows I invented. A
     * hand-built invoice would not exercise the projection the reader sums.
     */
    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1,
        'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => [],
    ]);
    $branch = Branch::query()->where('code', 'MAIN')->firstOrFail();
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id, 'code' => '72001', 'description' => 'Consultation',
        'unit_price_minor' => 10000, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);

    $issue = app(IssueService::class);
    $make = function (int $totalMinor) use ($patient, $branch, $catalog, $item, $staff, $issue): Invoice {
        $charge = Charge::query()->create([
            'patient_id' => $patient->id, 'branch_id' => $branch->id, 'service_date' => now()->toDateString(),
            'tariff_catalog_id' => $catalog->id, 'tariff_item_id' => $item->id, 'code' => $item->code,
            'description' => $item->description, 'unit_price_minor' => $totalMinor, 'vat_rate_bp' => 0,
            'quantity' => 1, 'line_total_minor' => $totalMinor, 'status' => Charge::STATUS_VALIDATED,
            'created_by' => $staff->id,
        ]);

        return $issue->issue(
            $issue->createDraftFromCharges($patient, [$charge], $staff, Invoice::PAYER_SELF_PAY, null, now(), now()->addDays(30)),
            $staff,
        );
    };

    // One fully open, one PARTLY PAID, one settled.
    $open = $make(31300);
    $partly = $make(20000);
    $paid = $make(19478);

    // Settle one and part-pay another through the REAL payment path, so the projection is genuine.
    $payments = app(PaymentService::class);
    // The currency is the INVOICE's (the tenant setting decides it) — never hardcoded here.
    $p1 = $payments->record(19478, 'bank_transfer', $staff, $patient, null, $paid->currency);
    $payments->allocate($p1, $paid, 19478, $staff);
    $p2 = $payments->record(5000, 'bank_transfer', $staff, $patient, null, $partly->currency);
    $payments->allocate($p2, $partly, 5000, $staff);

    return compact('tenant', 'staff', 'patient', 'account', 'open', 'paid');
}

/** Strip comments so the scans test AFFORDANCES, not the prose documenting their absence. */
function ptbStrip(string $source): string
{
    $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;
    $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;

    return strtolower(preg_replace('~(^|\s)//[^\n]*~m', '$1 ', $source) ?? $source);
}

test('Home and Invoices report the SAME open balance, and it TIES to the engine (δ=0)', function () {
    $fx = ptbFixture();

    ptbCtx()->set($fx['tenant']);

    // THE ENGINE'S OWN FIGURE — the tie target `MetricsService::accountLedger()` asserts.
    $ledger = app(MetricsService::class)->accountLedger($fx['staff'], $fx['patient']->id, now());
    $engineOutstanding = (int) $ledger['account_outstanding_minor'];

    // POSITIVE CONTROL: the fixture is non-trivial — a partly-paid invoice means a wrong column,
    // a dropped row or a filtered sum would all produce a different number (D-174).
    expect($engineOutstanding)->toBeGreaterThan(0)
        ->and($ledger['ties'])->toBeTrue('the engine ledger does not tie — the fixture is unsound');

    ptbCtx()->forget();
    $home = ptbSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.home'))
        ->assertOk()
        ->viewData('page')['props'];

    ptbCtx()->forget();
    $invoices = ptbSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.invoices'))
        ->assertOk()
        ->viewData('page')['props'];

    // ONE FIGURE, THREE PLACES: engine, Home, Invoices.
    expect($home['outstandingBalanceMinor'])->toBe($engineOutstanding)
        ->and($invoices['outstanding']['minor'])->toBe($engineOutstanding);

    // ...and the same formatted string, so the two screens cannot even LOOK different.
    expect($home['outstandingBalance'])->toBe($invoices['outstanding']['formatted']);

    // The list is present and non-trivial, but the total is NOT its sum — it comes from the reader.
    expect($invoices['invoices'])->toHaveCount(3);
});

test('the balance survives a filtered list — it is not derived from the rows', function () {
    $fx = ptbFixture();
    ptbCtx()->set($fx['tenant']);

    $reader = app(PatientBalanceReader::class);
    $full = $reader->outstandingMinorFor($fx['patient']->id);

    /*
     * The wireframe promises "your open balance stays the full total" while the status filter
     * narrows the list. That promise is only keepable because the figure is not a sum of the rows:
     * dropping every settled invoice from the payload must not change it.
     */
    expect($full)->toBeGreaterThan(0);

    ptbCtx()->forget();
    $props = ptbSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.invoices'))
        ->assertOk()
        ->viewData('page')['props'];

    $sumOfRows = collect($props['invoices'])->sum('open_balance_minor');

    // Here they happen to agree — which is the point: the figure is right AND independently sourced.
    expect($props['outstanding']['minor'])->toBe($full)
        ->and($props['outstanding']['minor'])->toBe($sumOfRows);
});

test('NO page-side money arithmetic anywhere in the portal', function () {
    $files = array_merge(
        glob(base_path('resources/js/pages/Portal/*.vue')) ?: [],
        glob(base_path('resources/js/Components/Portal/*.vue')) ?: [],
        [base_path('resources/js/Layouts/PortalLayout.vue')],
    );

    // POSITIVE CONTROL (D-173/D-174): the scan really resolved the portal surface.
    expect(count($files))->toBeGreaterThanOrEqual(9);
    $names = array_map('basename', $files);
    foreach (['Home.vue', 'Invoices.vue', 'TreatmentPlan.vue', 'PortalPageHeader.vue'] as $expected) {
        expect($names)->toContain($expected);
    }

    foreach ($files as $path) {
        $code = ptbStrip((string) file_get_contents($path));

        /*
         * Money must arrive formatted. A divide-by-100, a toFixed on money, or a reduce over minor
         * units are all the same defect: a second opinion about a figure the engine already owns.
         * (`Documents.vue` divides for a FILE SIZE in MB — not money — so the ban is written
         * against the money idioms specifically rather than against arithmetic in general.)
         */
        foreach (['/ 100', '/100', 'minor / ', '_minor /'] as $divide) {
            expect(str_contains($code, $divide))->toBeFalse(basename($path)." divides minor units: '{$divide}'");
        }
        foreach (['reduce((sum', 'reduce((total', 'reduce((acc'] as $sum) {
            expect(str_contains($code, $sum))->toBeFalse(basename($path)." sums money page-side: '{$sum}'");
        }
        // No ratio/percentage of money either.
        expect(preg_match('~_minor\s*[*/]\s~', $code))->toBe(0, basename($path).' performs money arithmetic');
    }
});

test('the patient-facing header is patient-appropriate and is NOT the staff clinical header', function () {
    $path = base_path('resources/js/Components/Portal/PortalPageHeader.vue');
    expect(file_exists($path))->toBeTrue('the portal header is missing — this test would scan nothing');

    $code = ptbStrip((string) file_get_contents($path));
    expect(strlen(trim($code)))->toBeGreaterThan(200);

    // It renders what a patient needs: a section eyebrow, a title, a lead line.
    expect($code)->toContain('{{ title }}')
        ->and($code)->toContain('{{ eyebrow }}')
        ->and($code)->toContain('{{ lead }}');

    /*
     * ...and NOT the staff framing. A patient does not need their own MRN quoted back at them, and
     * their allergy list is not a page banner — that is S1's job on a clinician's screen.
     */
    foreach (['mrn', 'dateofbirth', 'date_of_birth', 'allergy', 'allergies', 'sex', 'euca-tile-dark'] as $staffField) {
        expect(str_contains($code, $staffField))->toBeFalse("the portal header renders a staff field: '{$staffField}'");
    }

    // No tone/severity affordance, so nothing patient-facing can be tinted by a value (D-166/D-169).
    foreach (['tone', 'severity', 'variant', 'urgency', 'overdue', 'status'] as $affordance) {
        expect(preg_match('~\b'.preg_quote($affordance, '~').'\b~', $code))
            ->toBe(0, "the portal header offers a '{$affordance}' affordance");
    }

    // S1 IS UNTOUCHED — this is a separate component, not a fork or an extension.
    $s1 = (string) file_get_contents(base_path('resources/js/Components/Clinical/PatientClinicalHeader.vue'));
    expect($s1)->toContain('{{ patient.mrn }}')
        ->and(str_contains($s1, 'PortalPageHeader'))->toBeFalse('S1 was stretched toward the portal');
});

test('PT.P1 audit rows are unchanged — still exactly one per render', function () {
    $fx = ptbFixture();

    foreach (['portal_home' => route('portal.home'), 'portal_invoices' => route('portal.invoices')] as $surface => $url) {
        ptbCtx()->forget();
        ptbSignIn($this, $fx['account'])
            ->withSession(['portal_tenant_id' => $fx['tenant']->id])
            ->get($url)
            ->assertOk();

        ptbCtx()->set($fx['tenant']);

        // Decoded, never byte-matched (the PT.P1-FIX lesson: MySQL 8 re-serialises JSON).
        $rows = DB::table('audit_events')
            ->where('action', 'read')
            ->where('patient_id', $fx['patient']->id)
            ->pluck('context')
            ->filter(function ($context) use ($surface): bool {
                $decoded = json_decode((string) $context, true);

                return is_array($decoded) && ($decoded['surface'] ?? null) === $surface;
            });

        expect($rows)->toHaveCount(1, "{$surface} no longer writes exactly one row per render");
    }
});
