<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
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
 * ARDETAIL.P3 — pure visual parity (hero + status pills + Swiss format + chart link + invoice PDF).
 * These light tests prove the presentational additions target EXISTING routes and reuse the EXISTING
 * P1/P2 figures (no new figure, no new mechanism): the patient-chart link is the existing patients.show
 * route, the per-invoice PDF link is the existing billing.invoices.download generator. The Swiss format
 * itself is a display-only formatter covered by resources/js/lib/money.test.ts. No existing behaviour
 * test is touched.
 */

function adxUser(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, catalog: TariffCatalog, patient: Patient, invoice: Invoice}
 */
function adxFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $actor = adxUser($tenant, 'org_admin'); // holds billing.view + patient.view (to follow the chart link)
    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $catalog = TariffCatalog::query()->create(['key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1, 'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => []]);
    $patient = app(PatientService::class)->create(['first_name' => 'Visual', 'last_name' => 'Parity', 'date_of_birth' => '1980-01-01', 'sex' => 'female']);

    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id, 'code' => '72001', 'description' => 'Consultation',
        'unit_price_minor' => 482000, 'vat_rate_bp' => 0, 'unit' => 'session', 'requires_service_documentation' => false, 'active' => true,
    ]);
    $charge = Charge::query()->create([
        'patient_id' => $patient->id, 'branch_id' => $branch->id, 'service_date' => '2026-04-01',
        'tariff_catalog_id' => $catalog->id, 'tariff_item_id' => $item->id, 'code' => $item->code,
        'description' => $item->description, 'unit_price_minor' => 482000, 'vat_rate_bp' => 0,
        'quantity' => 1, 'line_total_minor' => 482000, 'status' => Charge::STATUS_VALIDATED, 'created_by' => $actor->id,
    ]);
    $issue = app(IssueService::class);
    $invoice = $issue->issue(
        $issue->createDraftFromCharges($patient, [$charge], $actor, Invoice::PAYER_SELF_PAY, null, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-15')),
        $actor,
    );

    return compact('tenant', 'actor', 'branch', 'catalog', 'patient', 'invoice');
}

beforeEach(fn () => Carbon::setTestNow('2026-06-15 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

// ── The hero/pills reuse the existing P1/P2 figures; the links target existing routes ────────────

test('the page surfaces the chart link and the per-invoice PDF link over the existing figures', function () {
    $fx = adxFixture();

    // The links point at routes that ALREADY exist (no new mechanism this gate).
    expect(Route::has('patients.show'))->toBeTrue()
        ->and(Route::has('billing.invoices.download'))->toBeTrue();

    $this->actingAs($fx['actor'])->get(route('billing.accounts.show', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/AccountDetail')
            // The hero reuses the EXISTING P1 figure (account outstanding) — not a new one.
            ->where('ledger.account_outstanding_minor', 482000)
            ->has('dunning.current_stage')  // the P2 stage drives the hero pill
            // The patient-chart link is the EXISTING patient 360 route.
            ->where('links.chart', route('patients.show', $fx['patient']->id))
            // Each ledger row links to the EXISTING invoice-PDF generator (not a new payment path).
            ->where('ledger.rows.0.pdf_url', route('billing.invoices.download', $fx['invoice']->id)));
});

// ── Still read-only + tenant-scoped (P3 adds no mutation route) ──────────────────────────────────

test('the visual gate adds no mutation route and stays billing.view + tenant-scoped', function () {
    $fx = adxFixture('alpha');

    // billing.view gate.
    $reception = adxUser($fx['tenant'], 'reception');
    $this->actingAs($reception)->get(route('billing.accounts.show', $fx['patient']->id))->assertForbidden();

    // Tenant scope — a second tenant 404s on the cross-tenant account.
    $beta = adxFixture('beta');
    $this->actingAs($beta['actor'])->get(route('billing.accounts.show', $fx['patient']->id))->assertNotFound();

    // Every /billing/accounts route is still GET-only (no write added by the visual gate).
    $accountRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'billing/accounts'));
    expect($accountRoutes->every(fn ($r) => in_array('GET', $r->methods(), true) && ! in_array('POST', $r->methods(), true)))->toBeTrue();
});
