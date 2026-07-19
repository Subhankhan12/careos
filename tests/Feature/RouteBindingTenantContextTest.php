<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PaymentService;
use Modules\Import\Models\ImportBatch;
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
 * REGRESSION for C-1 (QA audit): billing + import DETAIL and WRITE routes used implicit
 * route-model binding, which Laravel resolves in SubstituteBindings — BEFORE
 * IdentifyTenantFromUser sets the tenant context — so the tenant-scoped query threw
 * TenantContextMissingException → 500 in the real app. The existing feature tests never
 * caught it because their fixtures pre-set the TenantContext singleton before the request.
 *
 * These tests deliberately DO NOT leave the context set for the request: after seeding they
 * call TenantContext::forget(), so the HTTP request must (re)establish context the way a
 * real browser request does — via the IdentifyTenantFromUser middleware. Against the old
 * (implicit-binding) controllers these assertions 500; against the string-id + in-controller
 * resolution they pass, and a missing / cross-tenant id still 404s (fail-closed preserved).
 */

function rbCtx(): TenantContext
{
    return app(TenantContext::class);
}

/**
 * @return array{tenant: Tenant, user: User, invoice: Invoice, creditNote: Invoice, payment: Payment, batch: ImportBatch}
 */
function rbFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    rbCtx()->set($tenant);

    // org_admin holds billing.view/manage AND data.import — reaches every route under test.
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    $branch = Branch::query()->create(['name' => strtoupper(substr($slug, 0, 4)).' Branch', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $patient = app(PatientService::class)->create(['first_name' => 'Rita', 'last_name' => 'Binding', 'date_of_birth' => '1970-01-01', 'sex' => 'female']);
    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1,
        'valid_from' => '2026-01-01', 'valid_to' => null, 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => [],
    ]);

    // $invoice stays OPEN (for show + allocate); a SEPARATE invoice is credit-noted so
    // there is a real credit-note document to open on the CN detail route.
    $invoice = rbIssuedInvoice($tenant, $user, $branch, $patient, $catalog, 'RB-INV');
    $credited = rbIssuedInvoice($tenant, $user, $branch, $patient, $catalog, 'RB-CRED');
    $creditNote = app(IssueService::class)->creditNote($credited, null, 'Billed in error', $user);
    $payment = app(PaymentService::class)->record(5000, Payment::METHOD_BANK_TRANSFER, $user, $patient, null, 'EUR', now(), null);

    $batch = ImportBatch::query()->create([
        'type' => 'patients',
        'original_filename' => 'qa.csv',
        'storage_path' => 'tenants/'.$tenant->id.'/imports/qa.csv',
        'status' => ImportBatch::STATUS_UPLOADED,
        'row_count' => 2,
        'created_by' => (string) $user->id,
    ]);

    return compact('tenant', 'user', 'invoice', 'creditNote', 'payment', 'batch');
}

function rbIssuedInvoice(Tenant $tenant, User $user, Branch $branch, Patient $patient, TariffCatalog $catalog, string $code = 'RB-ITEM'): Invoice
{
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id, 'code' => $code, 'description' => 'Service',
        'unit_price_minor' => 10000, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);
    $charge = Charge::query()->create([
        'patient_id' => $patient->id, 'branch_id' => $branch->id, 'service_date' => '2026-05-10',
        'tariff_catalog_id' => $catalog->id, 'tariff_item_id' => $item->id, 'code' => $item->code,
        'description' => $item->description, 'unit_price_minor' => 10000, 'vat_rate_bp' => 0,
        'quantity' => 1, 'line_total_minor' => 10000, 'status' => Charge::STATUS_VALIDATED, 'created_by' => $user->id,
    ]);
    $service = app(IssueService::class);

    return $service->issue(
        $service->createDraftFromCharges($patient, [$charge], $user, Invoice::PAYER_SELF_PAY, null, now(), now()->addDays(14)),
        $user,
    );
}

function rbDraftInvoice(array $fx): Invoice
{
    rbCtx()->set($fx['tenant']);
    $branch = Branch::query()->first();
    $catalog = TariffCatalog::query()->first();
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id, 'code' => 'RB-DRAFT', 'description' => 'Draft service',
        'unit_price_minor' => 2500, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);
    $charge = Charge::query()->create([
        'patient_id' => Patient::query()->first()->id, 'branch_id' => $branch->id, 'service_date' => '2026-05-11',
        'tariff_catalog_id' => $catalog->id, 'tariff_item_id' => $item->id, 'code' => $item->code,
        'description' => $item->description, 'unit_price_minor' => 2500, 'vat_rate_bp' => 0,
        'quantity' => 1, 'line_total_minor' => 2500, 'status' => Charge::STATUS_VALIDATED, 'created_by' => $fx['user']->id,
    ]);

    return app(IssueService::class)->createDraftFromCharges(Patient::query()->first(), [$charge], $fx['user'], Invoice::PAYER_SELF_PAY, null, now(), now()->addDays(14));
}

test('billing + import DETAIL routes resolve the tenant model after middleware sets context (no 500)', function () {
    Storage::fake('local');
    $fx = rbFixture();

    // No ambient tenant context — the request must set it via IdentifyTenantFromUser,
    // exactly like a real browser request. Old implicit-binding controllers 500 here.
    rbCtx()->forget();
    $this->actingAs($fx['user'])->get(route('billing.invoices.show', $fx['invoice']->id))->assertOk();

    rbCtx()->forget();
    $this->actingAs($fx['user'])->get(route('billing.credit-notes.show', $fx['creditNote']->id))->assertOk();

    rbCtx()->forget();
    $this->actingAs($fx['user'])->get(route('billing.payments.show', $fx['payment']->id))->assertOk();

    rbCtx()->forget();
    $this->actingAs($fx['user'])->get(route('import.show', $fx['batch']->id))->assertOk();
});

test('billing WRITE actions resolve the tenant model after context is set (issue, credit-note, allocate)', function () {
    Storage::fake('local');
    $fx = rbFixture();
    $draft = rbDraftInvoice($fx);

    rbCtx()->forget();
    $this->actingAs($fx['user'])
        ->post(route('billing.invoices.issue', $draft->id))
        ->assertRedirect(route('billing.invoices.show', $draft->id));
    expect($draft->refresh()->status)->toBe(Invoice::STATUS_ISSUED);

    // Credit-note the just-issued draft (not $fx['invoice'], which the allocate below needs open).
    rbCtx()->forget();
    $this->actingAs($fx['user'])
        ->post(route('billing.invoices.credit-note', $draft->id), ['reason' => 'Adjustment'])
        ->assertRedirect();
    rbCtx()->set($fx['tenant']);
    expect(Invoice::query()->where('series', Invoice::SERIES_CREDIT_NOTE)->where('credit_note_for_invoice_id', $draft->id)->exists())->toBeTrue();

    // Allocate the 5000 payment against the still-open 10000 invoice → remainder 0.
    rbCtx()->forget();
    $this->actingAs($fx['user'])
        ->post(route('billing.payments.allocate', $fx['payment']->id), ['invoice_id' => $fx['invoice']->id, 'amount_minor' => 5000])
        ->assertRedirect(route('billing.payments.show', $fx['payment']->id));
    rbCtx()->set($fx['tenant']);
    expect(app(PaymentService::class)->unallocated($fx['payment']->refresh()))->toBe(0);
});

test('a missing or cross-tenant id is 404, not 500 — fail-closed preserved', function () {
    Storage::fake('local');
    $alpha = rbFixture('alpha');
    $beta = rbFixture('beta');

    // Alpha's user requesting Beta's invoice → the BelongsToTenant scope hides it → 404.
    rbCtx()->forget();
    $this->actingAs($alpha['user'])->get(route('billing.invoices.show', $beta['invoice']->id))->assertNotFound();

    rbCtx()->forget();
    $this->actingAs($alpha['user'])->get(route('billing.payments.show', $beta['payment']->id))->assertNotFound();

    rbCtx()->forget();
    $this->actingAs($alpha['user'])->get(route('import.show', $beta['batch']->id))->assertNotFound();

    // A wholly unknown id → 404 (not 500).
    rbCtx()->forget();
    $this->actingAs($alpha['user'])->get(route('billing.invoices.show', 'nonexistent01234567890123'))->assertNotFound();
});
