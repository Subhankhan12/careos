<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\DunningEvent;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAllocation;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\DunningService;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PaymentService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Permission;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Modules\Reporting\Services\ReportingService;

uses(RefreshDatabase::class);

/*
 * CLINIC.W7 — presentation-layer tests for billing UI part 2 (payments, dunning,
 * reporting). NEW pages/controllers over the frozen, tested engines; the domain
 * invariants (append-only allocations, cannot over-allocate, dunning idempotency,
 * facts-only summary) stay covered by the untouched Payment/Dunning/Metrics suites.
 * Every mutation still flows through PaymentService / IssueService / DunningService.
 */

function w7Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function w7User(Tenant $tenant, string $role = 'billing'): User
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

/** billing.view but NOT billing.manage — no starter role fits, so mint one. */
function w7Viewer(Tenant $tenant): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $role = Role::query()->create(['key' => 'billing_view_only', 'name' => 'Billing Viewer', 'is_system' => false]);
    $role->permissions()->sync(Permission::query()->where('key', 'billing.view')->pluck('id'));
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id]);

    return $user;
}

function w7Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Pia',
        'last_name' => 'Payment',
        'date_of_birth' => '1980-02-02',
        'sex' => 'female',
        ...$overrides,
    ]);
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, catalog: TariffCatalog}
 */
function w7Fixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    w7Ctx()->set($tenant);

    $actor = w7User($tenant, 'billing');
    $branch = Branch::query()->create(['name' => strtoupper(substr($slug, 0, 4)).' Branch', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $patient = w7Patient();
    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1,
        'valid_from' => '2026-01-01', 'valid_to' => null,
        'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => [],
    ]);

    return compact('tenant', 'actor', 'branch', 'patient', 'catalog');
}

function w7Charge(array $fx, int $unitMinor = 10000, string $code = 'W7-ITEM'): Charge
{
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $fx['catalog']->id,
        'code' => $code, 'description' => 'Service '.$code,
        'unit_price_minor' => $unitMinor, 'vat_rate_bp' => 0,
        'unit' => 'session', 'requires_service_documentation' => false, 'active' => true,
    ]);

    return Charge::query()->create([
        'patient_id' => $fx['patient']->id,
        'branch_id' => $fx['branch']->id,
        'service_date' => '2026-05-10',
        'tariff_catalog_id' => $fx['catalog']->id,
        'tariff_item_id' => $item->id,
        'code' => $item->code, 'description' => $item->description,
        'unit_price_minor' => $item->unit_price_minor, 'vat_rate_bp' => 0,
        'quantity' => 1, 'line_total_minor' => $item->unit_price_minor,
        'status' => Charge::STATUS_VALIDATED, 'created_by' => $fx['actor']->id,
    ]);
}

function w7Issue(array $fx, ?Charge $charge = null, ?string $dueDate = null): Invoice
{
    $charge ??= w7Charge($fx);
    $service = app(IssueService::class);

    return $service->issue(
        $service->createDraftFromCharges(
            $fx['patient'],
            [$charge],
            $fx['actor'],
            Invoice::PAYER_SELF_PAY,
            null,
            now(),
            $dueDate !== null ? Carbon::parse($dueDate) : now()->addDays(14),
        ),
        $fx['actor'],
    );
}

/**
 * Recursively assert a reporting bundle carries no judgment/interpretation keys.
 *
 * @param  array<mixed>  $data
 */
function w7AssertNoJudgment(array $data): void
{
    $forbidden = ['good', 'bad', 'high', 'low', 'status', 'grade', 'score', 'label', 'verdict', 'rating', 'risk', 'flag', 'severity', 'target', 'trend', 'delta'];
    foreach ($data as $key => $value) {
        expect(in_array((string) $key, $forbidden, true))->toBeFalse("judgment key '{$key}' leaked into the reporting bundle");
        if (is_array($value)) {
            w7AssertNoJudgment($value);
        }
    }
}

test('payments worklist is RBAC gated and renders the Inertia component with the derived remainder', function () {
    Storage::fake('local');
    $fx = w7Fixture();
    app(PaymentService::class)->record(10000, Payment::METHOD_BANK_TRANSFER, $fx['actor'], $fx['patient'], null, 'EUR', now(), 'REF-1');

    $this->actingAs($fx['actor'])
        ->get(route('billing.payments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Payments/Index')
            ->has('payments', 1)
            ->where('payments.0.amount_minor', 10000)
            ->where('payments.0.unallocated_minor', 10000)
            ->where('payments.0.method', 'bank_transfer'));

    $this->actingAs(w7User($fx['tenant'], 'reception'))->get(route('billing.payments.index'))->assertForbidden();
});

test('recording a payment and allocating it appends an allocation through the service and leaves the invoice frozen', function () {
    Storage::fake('local');
    $fx = w7Fixture();
    $invoice = w7Issue($fx);
    $snapshot = $invoice->only(['status', 'number', 'total_minor']);

    $this->actingAs($fx['actor'])
        ->post(route('billing.payments.store'), [
            'amount_minor' => 10000,
            'method' => 'bank_transfer',
            'received_on' => now()->toDateString(),
            'patient_id' => $fx['patient']->id,
            'invoice_id' => $invoice->id,
            'allocate_amount_minor' => 10000,
        ])
        ->assertRedirect();

    expect(PaymentAllocation::query()->where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and(app(PaymentService::class)->openBalance($invoice))->toBe(0)
        ->and($invoice->refresh()->only(['status', 'number', 'total_minor']))->toBe($snapshot);

    $this->actingAs(w7User($fx['tenant'], 'reception'))
        ->post(route('billing.payments.store'), ['amount_minor' => 100, 'method' => 'cash', 'received_on' => now()->toDateString()])
        ->assertForbidden();
});

test('an allocation greater than the payment remainder is refused by the service and surfaced, not applied', function () {
    Storage::fake('local');
    $fx = w7Fixture();
    $invoice = w7Issue($fx);
    $payment = app(PaymentService::class)->record(5000, Payment::METHOD_CASH, $fx['actor'], $fx['patient'], null, 'EUR', now(), null);

    $this->actingAs($fx['actor'])
        ->post(route('billing.payments.allocate', $payment->id), ['invoice_id' => $invoice->id, 'amount_minor' => 8000])
        ->assertSessionHasErrors('allocate');

    expect(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(0)
        ->and(app(PaymentService::class)->unallocated($payment->refresh()))->toBe(5000)
        ->and(app(PaymentService::class)->openBalance($invoice))->toBe(10000);
});

test('a record-then-allocate whose allocation the service rejects still records the payment and flashes the error', function () {
    Storage::fake('local');
    $fx = w7Fixture();
    $invoice = w7Issue($fx); // open balance 10000

    $this->actingAs($fx['actor'])
        ->post(route('billing.payments.store'), [
            'amount_minor' => 5000,
            'method' => 'cash',
            'received_on' => now()->toDateString(),
            'patient_id' => $fx['patient']->id,
            'invoice_id' => $invoice->id,
            'allocate_amount_minor' => 8000, // exceeds the 5000 payment remainder
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('allocate');

    // Money is safe: the payment IS recorded; only the allocation was refused.
    expect(Payment::query()->count())->toBe(1)
        ->and(Payment::query()->firstOrFail()->amount_minor)->toBe(5000)
        ->and(PaymentAllocation::query()->count())->toBe(0)
        ->and(app(PaymentService::class)->openBalance($invoice))->toBe(10000);
});

test('reversing an allocation appends a negative reversal, needs a reason, and is manager-only', function () {
    Storage::fake('local');
    $fx = w7Fixture();
    $invoice = w7Issue($fx);
    $payment = app(PaymentService::class)->record(10000, Payment::METHOD_BANK_TRANSFER, $fx['actor'], $fx['patient'], null, 'EUR', now(), null);
    $allocation = app(PaymentService::class)->allocate($payment, $invoice, 10000, $fx['actor']);

    // Blank reason → rejected.
    $this->actingAs($fx['actor'])
        ->post(route('billing.payments.reverse', $payment->id), ['allocation_id' => $allocation->id, 'reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->actingAs($fx['actor'])
        ->post(route('billing.payments.reverse', $payment->id), ['allocation_id' => $allocation->id, 'reason' => 'Applied to the wrong invoice'])
        ->assertRedirect();

    $reversal = PaymentAllocation::query()->where('reverses_allocation_id', $allocation->id)->first();
    expect($reversal)->not->toBeNull()
        ->and($reversal->amount_minor)->toBe(-10000)
        ->and(app(PaymentService::class)->openBalance($invoice))->toBe(10000);

    $this->actingAs(w7Viewer($fx['tenant']))
        ->post(route('billing.payments.reverse', $payment->id), ['allocation_id' => $allocation->id, 'reason' => 'nope'])
        ->assertForbidden();
});

test('payment detail is RBAC gated, tenant scoped, and hides manage actions from a viewer', function () {
    Storage::fake('local');
    $fx = w7Fixture();
    $payment = app(PaymentService::class)->record(4200, Payment::METHOD_CARD, $fx['actor'], $fx['patient'], null, 'EUR', now(), null);

    $this->actingAs($fx['actor'])
        ->get(route('billing.payments.show', $payment->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Payments/Show')
            ->where('payment.amount_minor', 4200)
            ->where('payment.unallocated_minor', 4200)
            ->where('actions.can_manage', true));

    $this->actingAs(w7Viewer($fx['tenant']))
        ->get(route('billing.payments.show', $payment->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('actions.can_manage', false));

    $this->actingAs(w7User($fx['tenant'], 'reception'))->get(route('billing.payments.show', $payment->id))->assertForbidden();

    $beta = w7Fixture('beta');
    $this->actingAs($beta['actor'])->get(route('billing.payments.show', $payment->id))->assertNotFound();
});

test('new invoice from charges issues through IssueService with a gapless number and is manager-only', function () {
    Storage::fake('local');
    $fx = w7Fixture();
    $charge = w7Charge($fx);

    $this->actingAs($fx['actor'])
        ->get(route('billing.invoices.create', ['patient' => $fx['patient']->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Invoices/New')
            ->where('patient.id', $fx['patient']->id)
            ->has('charges', 1)
            ->where('charges.0.line_total_minor', 10000));

    $this->actingAs($fx['actor'])
        ->post(route('billing.invoices.store'), [
            'patient_id' => $fx['patient']->id,
            'charge_ids' => [$charge->id],
            'payer_type' => Invoice::PAYER_SELF_PAY,
            'due_in_days' => 30,
        ])
        ->assertRedirect();

    $invoice = Invoice::query()->where('series', Invoice::SERIES_INVOICE)->firstOrFail();
    expect($invoice->status)->toBe(Invoice::STATUS_ISSUED)
        ->and($invoice->number)->toBe('1')
        ->and($invoice->pdf_path)->not->toBeNull()
        ->and($charge->refresh()->status)->toBe(Charge::STATUS_INVOICED);

    // A viewer cannot issue; a fresh charge is left un-invoiced.
    $charge2 = w7Charge($fx, 5000, 'W7-ITEM2');
    $this->actingAs(w7Viewer($fx['tenant']))
        ->post(route('billing.invoices.store'), ['patient_id' => $fx['patient']->id, 'charge_ids' => [$charge2->id], 'payer_type' => Invoice::PAYER_SELF_PAY])
        ->assertForbidden();
    expect($charge2->refresh()->status)->toBe(Charge::STATUS_VALIDATED)
        ->and($charge2->invoice_id)->toBeNull();
});

test('dunning worklist lists overdue invoices and the run action is idempotent and manager-only', function () {
    Notification::fake();
    Storage::fake('local');
    $fx = w7Fixture();
    app(SettingsService::class)->set(DunningService::SETTINGS_KEY, ['channel' => 'email', 'levels' => [['level' => 1, 'days_past_due' => 14]]], 'array');
    $invoice = w7Issue($fx, null, now()->subDays(30)->toDateString());

    $this->actingAs($fx['actor'])
        ->get(route('billing.dunning.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Dunning/Index')
            ->has('rows', 1)
            ->where('rows.0.current_level', 0)
            ->where('counters.overdue', 1));

    $this->actingAs($fx['actor'])->post(route('billing.dunning.run'))->assertRedirect();
    expect(DunningEvent::query()->where('invoice_id', $invoice->id)->count())->toBe(1);

    // Idempotent: a second run the same day creates no further events.
    $this->actingAs($fx['actor'])->post(route('billing.dunning.run'))->assertRedirect();
    expect(DunningEvent::query()->where('invoice_id', $invoice->id)->count())->toBe(1);

    $this->actingAs(w7Viewer($fx['tenant']))->post(route('billing.dunning.run'))->assertForbidden();
    $this->actingAs(w7User($fx['tenant'], 'reception'))->get(route('billing.dunning.index'))->assertForbidden();
});

test('reporting dashboard is reporting.view gated, omits financials without billing.view, and carries no judgment fields', function () {
    Storage::fake('local');
    $fx = w7Fixture();
    w7Issue($fx);

    // Coordinator holds reporting.view but NOT billing.view → operational only.
    $this->actingAs(w7User($fx['tenant'], 'coordinator'))
        ->get(route('reporting.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reporting/Dashboard')
            ->has('summary.operational')
            ->has('summary.throughput')
            ->where('hasFinancial', false)
            ->missing('summary.financial'));

    // org_admin holds both → the financial section is present.
    $this->actingAs(w7User($fx['tenant'], 'org_admin'))
        ->get(route('reporting.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasFinancial', true)
            ->has('summary.financial.aging'));

    // The billing role has billing.view but NOT reporting.view → fail closed.
    $this->actingAs($fx['actor'])->get(route('reporting.dashboard'))->assertForbidden();
    $this->actingAs(w7User($fx['tenant'], 'reception'))->get(route('reporting.dashboard'))->assertForbidden();

    // The rendered bundle is facts-only — no judgment/interpretation keys anywhere.
    $summary = app(ReportingService::class)->summary(w7User($fx['tenant'], 'org_admin'), now()->startOfMonth()->toDateString(), now()->toDateString());
    w7AssertNoJudgment($summary);
});
