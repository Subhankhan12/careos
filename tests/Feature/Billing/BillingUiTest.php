<?php

use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog as BillingTariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Permission;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * CLINIC.W6 — presentation-layer tests for the staff billing UI. These are NEW
 * tests over NEW controllers/pages; the domain (numbering, immutability,
 * reconciliation) stays covered by the untouched InvoiceTest/Reconciliation suite.
 * Every mutating assertion still flows through IssueService — no billing math here.
 */

function w6Tenant(string $slug): Tenant
{
    return Tenant::query()->create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function w6Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function w6User(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    if ($role !== '') {
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
        ]);
    }

    return $user;
}

/** A user holding billing.view but NOT billing.manage — there is no such starter role. */
function w6Viewer(Tenant $tenant): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    $role = Role::query()->create([
        'key' => 'billing_view_only',
        'name' => 'Billing Viewer',
        'is_system' => false,
    ]);
    $role->permissions()->sync(Permission::query()->where('key', 'billing.view')->pluck('id'));

    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id]);

    return $user;
}

function w6Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create([
        'name' => $code.' Branch',
        'code' => $code,
        'timezone' => 'Europe/Zurich',
    ]);
}

function w6Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Iris',
        'last_name' => 'Invoice',
        'date_of_birth' => '1985-03-04',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function w6Catalog(): BillingTariffCatalog
{
    return BillingTariffCatalog::query()->create([
        'key' => 'eu-generic',
        'name' => 'EU Generic',
        'version' => 1,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'status' => BillingTariffCatalog::STATUS_ACTIVE,
        'rules' => [],
    ]);
}

function w6Item(BillingTariffCatalog $catalog, array $overrides = []): TariffItem
{
    return TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id,
        'code' => 'CONSULT',
        'description' => 'Consultation',
        'unit_price_minor' => 12000,
        'vat_rate_bp' => 810,
        'unit' => 'session',
        'requires_service_documentation' => false,
        'active' => true,
        ...$overrides,
    ]);
}

function w6Charge(array $fx, TariffItem $item, User $actor, int $quantity = 1): Charge
{
    return Charge::query()->create([
        'patient_id' => $fx['patient']->id,
        'branch_id' => $fx['branch']->id,
        'service_date' => '2026-05-10',
        'tariff_catalog_id' => $fx['catalog']->id,
        'tariff_item_id' => $item->id,
        'code' => $item->code,
        'description' => $item->description,
        'unit_price_minor' => $item->unit_price_minor,
        'vat_rate_bp' => $item->vat_rate_bp,
        'quantity' => $quantity,
        'line_total_minor' => $quantity * $item->unit_price_minor,
        'status' => Charge::STATUS_VALIDATED,
        'created_by' => $actor->id,
    ]);
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, catalog: BillingTariffCatalog}
 */
function w6Fixture(string $slug = 'alpha'): array
{
    $tenant = w6Tenant($slug);
    w6Ctx()->set($tenant);
    $actor = w6User($tenant, 'billing');
    $branch = w6Branch(strtoupper(substr($slug, 0, 4)));
    $patient = w6Patient();
    $catalog = w6Catalog();

    return compact('tenant', 'actor', 'branch', 'patient', 'catalog');
}

/** Draft-through-IssueService: the ONLY path that assigns numbers / renders PDFs. */
function w6Draft(array $fx, Charge $charge, ?CarbonInterface $due = null): Invoice
{
    return app(IssueService::class)->createDraftFromCharges(
        $fx['patient'],
        [$charge],
        $fx['actor'],
        Invoice::PAYER_SELF_PAY,
        null,
        now(),
        $due ?? now()->addDays(14),
    );
}

function w6Issue(array $fx, Charge $charge, ?CarbonInterface $due = null): Invoice
{
    return app(IssueService::class)->issue(w6Draft($fx, $charge, $due), $fx['actor']);
}

function w6ReadRows(string $tenantId): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, 'read'],
    ));
}

test('invoice worklist is RBAC gated, renders the Inertia component and factual counters', function () {
    Storage::fake('local');
    $fx = w6Fixture();
    w6Issue($fx, w6Charge($fx, w6Item($fx['catalog']), $fx['actor']));

    $this->actingAs($fx['actor'])
        ->get(route('billing.invoices.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Invoices/Index')
            ->has('invoices', 1)
            ->where('invoices.0.number', 'INV-1')
            ->where('invoices.0.status', 'issued')
            ->where('invoices.0.total_minor', 12972)
            ->where('counters.outstanding_minor', 12972)
            // Due in the future → the reporting service reports zero overdue; the
            // counter is a real service figure, not an echo of the outstanding total.
            ->where('counters.overdue_minor', 0)
            ->where('counters.currency', 'EUR')
            ->has('agingUrl'));

    // No billing.view (reception) → fail closed.
    $this->actingAs(w6User($fx['tenant'], 'reception'))
        ->get(route('billing.invoices.index'))
        ->assertForbidden();
});

test('the overdue counter reflects the tested past-due total from the reporting service', function () {
    Storage::fake('local');
    $fx = w6Fixture();
    // Due 40 days ago → wholly past due; overdue must equal the outstanding balance.
    w6Issue($fx, w6Charge($fx, w6Item($fx['catalog']), $fx['actor']), now()->subDays(40));

    $this->actingAs($fx['actor'])
        ->get(route('billing.invoices.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('counters.outstanding_minor', 12972)
            ->where('counters.overdue_minor', 12972));
});

test('invoice detail is read-audited, tenant scoped, and exposes manage actions only to managers', function () {
    Storage::fake('local');
    $fx = w6Fixture();
    $invoice = w6Issue($fx, w6Charge($fx, w6Item($fx['catalog']), $fx['actor']));

    $this->actingAs($fx['actor'])
        ->get(route('billing.invoices.show', $invoice->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Invoices/Show')
            ->where('invoice.number', 'INV-1')
            ->where('invoice.status', 'issued')
            ->where('invoice.total_minor', 12972)
            ->has('invoice.lines', 1)
            ->where('invoice.lines.0.line_vat_minor', 972)
            ->where('actions.can_manage', true));

    // Reading a patient's invoice is a disclosure → a read-audit row on a valid chain.
    expect(w6ReadRows($fx['tenant']->id))->not->toBeEmpty()
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();

    // billing.view without billing.manage → visible, but no write affordances.
    $this->actingAs(w6Viewer($fx['tenant']))
        ->get(route('billing.invoices.show', $invoice->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Invoices/Show')
            ->where('actions.can_manage', false));

    $this->actingAs(w6User($fx['tenant'], 'reception'))
        ->get(route('billing.invoices.show', $invoice->id))
        ->assertForbidden();

    // Cross-tenant route-model binding resolves to 404, never a leak.
    $beta = w6Fixture('beta');
    $this->actingAs($beta['actor'])
        ->get(route('billing.invoices.show', $invoice->id))
        ->assertNotFound();
});

test('issuing a draft through the controller assigns a gapless number via IssueService', function () {
    Storage::fake('local');
    $fx = w6Fixture();
    $draft = w6Draft($fx, w6Charge($fx, w6Item($fx['catalog']), $fx['actor']));

    expect($draft->status)->toBe(Invoice::STATUS_DRAFT)
        ->and($draft->number)->toBeNull();

    $this->actingAs($fx['actor'])
        ->post(route('billing.invoices.issue', $draft->id))
        ->assertRedirect(route('billing.invoices.show', $draft->id));

    expect($draft->refresh()->status)->toBe(Invoice::STATUS_ISSUED)
        ->and($draft->number)->toBe('1')
        ->and($draft->pdf_path)->not->toBeNull();

    // A viewer (no billing.manage) cannot issue — and the draft is left untouched.
    $otherDraft = w6Draft($fx, w6Charge($fx, w6Item($fx['catalog'], ['code' => 'CONSULT2']), $fx['actor']));
    $this->actingAs(w6Viewer($fx['tenant']))
        ->post(route('billing.invoices.issue', $otherDraft->id))
        ->assertForbidden();
    expect($otherDraft->refresh()->status)->toBe(Invoice::STATUS_DRAFT);
});

test('credit note requires a reason, uses the CN series, and leaves the original untouched', function () {
    Storage::fake('local');
    $fx = w6Fixture();
    $invoice = w6Issue($fx, w6Charge($fx, w6Item($fx['catalog']), $fx['actor']));
    $snapshot = $invoice->only(['status', 'number', 'total_minor']);

    // Reason is mandatory (validation, not billing logic).
    $this->actingAs($fx['actor'])
        ->post(route('billing.invoices.credit-note', $invoice->id), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->actingAs($fx['actor'])
        ->post(route('billing.invoices.credit-note', $invoice->id), ['reason' => 'Billed in error'])
        ->assertRedirect();

    $creditNote = Invoice::query()->where('series', Invoice::SERIES_CREDIT_NOTE)->firstOrFail();

    expect($creditNote->credit_note_for_invoice_id)->toBe($invoice->id)
        ->and($creditNote->number)->toBe('1')
        ->and($invoice->refresh()->only(['status', 'number', 'total_minor']))->toBe($snapshot);

    // The invoice-download route serves invoice PDFs only — a credit note 404s there.
    $this->actingAs($fx['actor'])
        ->get(route('billing.invoices.download', $creditNote->id))
        ->assertNotFound();

    $this->actingAs($fx['actor'])
        ->get(route('billing.credit-notes.show', $creditNote->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/CreditNotes/Show')
            ->where('creditNote.number', 'CN-1')
            ->where('creditNote.against_invoice.number', 'INV-1'));

    $this->actingAs($fx['actor'])
        ->get(route('billing.credit-notes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/CreditNotes/Index')
            ->has('creditNotes', 1)
            ->where('creditNotes.0.number', 'CN-1')
            ->where('creditNotes.0.against_invoice', 'INV-1'));

    // A viewer cannot raise a credit note.
    $this->actingAs(w6Viewer($fx['tenant']))
        ->post(route('billing.invoices.credit-note', $invoice->id), ['reason' => 'Nope'])
        ->assertForbidden();
});

test('AR aging renders factual buckets from the reporting service and is RBAC gated', function () {
    Storage::fake('local');
    $fx = w6Fixture();
    w6Issue($fx, w6Charge($fx, w6Item($fx['catalog']), $fx['actor']));

    $this->actingAs($fx['actor'])
        ->get(route('billing.aging'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Aging')
            ->where('outstanding_minor', 12972)
            ->has('buckets.current')
            ->has('buckets.days_90_plus')
            ->has('monthToDate.invoiced_minor')
            ->has('monthToDate.collected_minor')
            ->has('asOf'));

    $this->actingAs(w6User($fx['tenant'], 'reception'))
        ->get(route('billing.aging'))
        ->assertForbidden();
});

test('invoice PDF downloads privately with nosniff and drafts have no PDF', function () {
    Storage::fake('local');
    $fx = w6Fixture();
    $invoice = w6Issue($fx, w6Charge($fx, w6Item($fx['catalog']), $fx['actor']));

    $this->actingAs($fx['actor'])
        ->get(route('billing.invoices.download', $invoice->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // A draft (no number / no PDF) cannot be downloaded.
    $draft = w6Draft($fx, w6Charge($fx, w6Item($fx['catalog'], ['code' => 'DRAFTONLY']), $fx['actor']));
    $this->actingAs($fx['actor'])
        ->get(route('billing.invoices.download', $draft->id))
        ->assertNotFound();

    $this->actingAs(w6User($fx['tenant'], 'reception'))
        ->get(route('billing.invoices.download', $invoice->id))
        ->assertForbidden();
});
