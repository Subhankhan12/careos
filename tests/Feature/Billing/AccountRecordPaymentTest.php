<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolDefinition;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAllocation;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\ReconciliationEngine;
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
 * ARDETAIL.P4 — record a payment from the AR Account Detail page. These tests prove the FENCE:
 * the page path writes NO money itself — it goes through the EXISTING PaymentService, so the
 * OVER-ALLOCATION guard holds through the page (a forged over-allocation is refused server-side),
 * every movement is an append-only, audited row, the account still RECONCILES-TO-THE-UNIT
 * (I1–I6, δ=0) afterwards, and the P1 ledger / P7 rollup / outstanding all reflect the payment.
 * The write is OPERATOR-GATED (billing.manage) and the agent has no path to it. These tests ADD
 * coverage; no existing behaviour test is modified.
 */

const ARP_PERIOD = '2026-06';

function arpUser(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, catalog: TariffCatalog}
 */
function arpFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $actor = arpUser($tenant);
    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $catalog = TariffCatalog::query()->create(['key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1, 'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => []]);

    return compact('tenant', 'actor', 'branch', 'catalog');
}

/**
 * @param  array{tenant: Tenant, actor: User, branch: Branch, catalog: TariffCatalog}  $fx
 */
function arpInvoice(array $fx, Patient $patient, int $priceMinor, string $issueDate = '2026-06-01', string $dueDate = '2026-06-20'): Invoice
{
    static $codeSeq = 81000; // deterministic-unique tariff code
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $fx['catalog']->id, 'code' => (string) (++$codeSeq), 'description' => 'Consultation',
        'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);
    $charge = Charge::query()->create([
        'patient_id' => $patient->id, 'branch_id' => $fx['branch']->id, 'service_date' => $issueDate,
        'tariff_catalog_id' => $fx['catalog']->id, 'tariff_item_id' => $item->id, 'code' => $item->code,
        'description' => $item->description, 'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0,
        'quantity' => 1, 'line_total_minor' => $priceMinor, 'status' => Charge::STATUS_VALIDATED, 'created_by' => $fx['actor']->id,
    ]);
    $service = app(IssueService::class);

    return $service->issue(
        $service->createDraftFromCharges($patient, [$charge], $fx['actor'], Invoice::PAYER_SELF_PAY, null, Carbon::parse($issueDate), Carbon::parse($dueDate)),
        $fx['actor'],
    );
}

function arpPatient(): Patient
{
    return app(PatientService::class)->create(['first_name' => 'Erika', 'last_name' => 'Payer', 'date_of_birth' => '1979-05-04', 'sex' => 'female']);
}

/** The projected open balance of an invoice (the reconciled projection, not a recomputation). */
function arpOpen(Invoice $invoice): int
{
    return (int) InvoiceBalance::query()->where('invoice_id', $invoice->id)->value('open_balance_minor');
}

/** The reconciliation report for the period (pure; no auth). */
function arpReport(): array
{
    return app(ReconciliationEngine::class)->check(ARP_PERIOD);
}

/** Recursively scan a source dir; true if any .php file CONTAINS the needle (the agent-exclusion proof). */
function arpSourceContains(string $absDir, string $needle): bool
{
    /*
     * POSITIVE CONTROL (FENCE-AUDIT / D-174). A missing directory used to return false, which
     * made the caller's `->toBeFalse()` pass having scanned NOTHING — the guard would go
     * quiet the moment its target moved. Fail loudly instead.
     */
    if (! is_dir($absDir)) {
        throw new RuntimeException("fence scan target does not exist: {$absDir} — the guard would otherwise pass having scanned nothing");
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), $needle)) {
            return true;
        }
    }

    return false;
}

beforeEach(fn () => Carbon::setTestNow('2026-06-25 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

// ── The write goes through PaymentService: recorded + allocated, append-only, audited ────────────

test('recording a payment from the account page posts through PaymentService — allocated, append-only, audited', function () {
    $fx = arpFixture();
    $patient = arpPatient();
    $invoice = arpInvoice($fx, $patient, 30000);

    expect(arpOpen($invoice))->toBe(30000);

    $this->actingAs($fx['actor'])
        ->post(route('billing.accounts.payments.store', $patient->id), [
            'amount_minor' => 12000,
            'method' => 'bank_transfer',
            'received_on' => '2026-06-22',
            'reference' => 'TWINT 4417',
            'allocations' => [['invoice_id' => $invoice->id, 'amount_minor' => 12000]],
        ])
        ->assertRedirect(route('billing.accounts.show', $patient->id))
        ->assertSessionHasNoErrors();

    // The receipt is a real Payment row attributed to the operator + the account.
    $payment = Payment::query()->where('patient_id', $patient->id)->firstOrFail();
    expect($payment->amount_minor)->toBe(12000)
        ->and($payment->method)->toBe('bank_transfer')
        ->and($payment->reference)->toBe('TWINT 4417')
        ->and($payment->recorded_by)->toBe($fx['actor']->id)
        ->and($payment->received_on->toDateString())->toBe('2026-06-22')
        // The service stamped the target invoice's currency.
        ->and($payment->currency)->toBe($invoice->currency);

    // The allocation is a real append-only movement, and the reconciled projection moved with it.
    $allocation = PaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail();
    expect($allocation->invoice_id)->toBe($invoice->id)
        ->and($allocation->amount_minor)->toBe(12000)
        ->and($allocation->allocated_by)->toBe($fx['actor']->id)
        ->and(arpOpen($invoice))->toBe(18000)
        ->and((string) InvoiceBalance::query()->where('invoice_id', $invoice->id)->value('status'))->toBe(Invoice::STATUS_PARTIALLY_PAID);

    // Both service audit actions were written (the service's own audit, not a page-side one).
    foreach (['payment.recorded', 'payment.allocated'] as $action) {
        $count = DB::selectOne('SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?', [$fx['tenant']->id, $action])->c;
        expect((int) $count)->toBe(1);
    }

    // Append-only: the allocation row cannot be mutated or deleted (the ledger discipline holds).
    expect(fn () => $allocation->update(['amount_minor' => 1]))->toThrow(LogicException::class);
});

// ── THE GUARD (the crux): a forged over-allocation is refused BY THE SERVICE ─────────────────────

test('the over-allocation guard holds through the page path — a forged over-allocation is refused server-side', function () {
    $fx = arpFixture();
    $patient = arpPatient();
    $invoice = arpInvoice($fx, $patient, 30000);

    // A forged POST allocating MORE than the invoice open balance (the page would never offer it).
    $this->actingAs($fx['actor'])
        ->post(route('billing.accounts.payments.store', $patient->id), [
            'amount_minor' => 50000,
            'method' => 'bank_transfer',
            'received_on' => '2026-06-22',
            'allocations' => [['invoice_id' => $invoice->id, 'amount_minor' => 30001]],
        ])
        ->assertRedirect(route('billing.accounts.show', $patient->id))
        ->assertSessionHasErrors('record_payment');

    // NOTHING was allocated and the balance is untouched — the guard, not the page, refused it.
    expect(PaymentAllocation::query()->count())->toBe(0)
        ->and(arpOpen($invoice))->toBe(30000);

    // The receipt itself stands (money WAS received) as an unallocated remainder — the existing
    // PaymentService semantics; and the books still tie with it.
    $payment = Payment::query()->where('patient_id', $patient->id)->firstOrFail();
    expect($payment->amount_minor)->toBe(50000);
    foreach (arpReport()['invariants'] as $inv) {
        expect($inv['ok'])->toBeTrue()->and($inv['delta_minor'])->toBe(0);
    }
});

test('the guard also refuses allocating more than the payment remainder across several invoices', function () {
    $fx = arpFixture();
    $patient = arpPatient();
    $first = arpInvoice($fx, $patient, 10000);
    $second = arpInvoice($fx, $patient, 10000);

    // A 10'000 payment forged to cover BOTH invoices in full: the first allocation fits, the
    // second exceeds the payment's remaining money.
    $this->actingAs($fx['actor'])
        ->post(route('billing.accounts.payments.store', $patient->id), [
            'amount_minor' => 10000,
            'method' => 'cash',
            'received_on' => '2026-06-22',
            'allocations' => [
                ['invoice_id' => $first->id, 'amount_minor' => 10000],
                ['invoice_id' => $second->id, 'amount_minor' => 10000],
            ],
        ])
        ->assertSessionHasErrors('record_payment');

    // Only the movement the guard allowed was posted; the second invoice is untouched.
    expect(PaymentAllocation::query()->count())->toBe(1)
        ->and(arpOpen($first))->toBe(0)
        ->and(arpOpen($second))->toBe(10000);

    foreach (arpReport()['invariants'] as $inv) {
        expect($inv['delta_minor'])->toBe(0);
    }
});

// ── RECONCILE (the launch blocker): I1–I6 δ=0 after the payment; ledger/rollup/outstanding move ──

test('after the payment the account reconciles to the unit (I1-I6, delta=0) and the ledger, rollup and outstanding reflect it', function () {
    $fx = arpFixture();
    $patient = arpPatient();
    $invoice = arpInvoice($fx, $patient, 30000);
    $metrics = app(MetricsService::class);

    $before = $metrics->accountLedger($fx['actor'], (string) $patient->id, now());
    expect($before['account_outstanding_minor'])->toBe(30000);

    $this->actingAs($fx['actor'])
        ->post(route('billing.accounts.payments.store', $patient->id), [
            'amount_minor' => 12000,
            'method' => 'bank_transfer',
            'received_on' => '2026-06-22',
            'allocations' => [['invoice_id' => $invoice->id, 'amount_minor' => 12000]],
        ])
        ->assertSessionHasNoErrors();

    // Six invariants, every delta 0 — the payment did not break the books.
    $report = arpReport();
    expect($report['passed'])->toBeTrue()
        ->and($report['invariants'])->toHaveCount(6);
    foreach ($report['invariants'] as $inv) {
        expect($inv['ok'])->toBeTrue()->and($inv['delta_minor'])->toBe(0);
    }

    // The P1 ledger moved by exactly the payment and still TIES (δ=0)...
    $after = $metrics->accountLedger($fx['actor'], (string) $patient->id, now());
    expect($after['account_outstanding_minor'])->toBe(18000)
        ->and($after['rows'][0]['paid_minor'])->toBe(12000)
        ->and($after['rows'][0]['balance_minor'])->toBe(18000)
        ->and(end($after['rows'])['running_balance_minor'])->toBe(18000)
        ->and($after['ties'])->toBeTrue()
        // ...and it still equals the engine's own outstanding figure.
        ->and($after['account_outstanding_minor'])->toBe($metrics->outstandingBalanceMinor($fx['actor']));

    // The P7 top-overdue rollup for this account reflects the payment and still ties.
    $rollup = $metrics->topOverdueAccounts($fx['actor'], Carbon::parse('2026-06-25'), 10);
    $entry = collect($rollup['accounts'])->firstWhere('patient_id', (string) $patient->id);
    expect($entry['total_overdue_minor'])->toBe(18000)
        ->and($entry['ties'])->toBeTrue();
});

// ── OPERATOR-GATED + no agent path (the agent never commits money) ───────────────────────────────

test('recording a payment is operator-gated — reception is refused and no money is written', function () {
    $fx = arpFixture();
    $patient = arpPatient();
    $invoice = arpInvoice($fx, $patient, 30000);

    $reception = arpUser($fx['tenant'], 'reception'); // no billing.manage

    $this->actingAs($reception)
        ->post(route('billing.accounts.payments.store', $patient->id), [
            'amount_minor' => 12000,
            'method' => 'cash',
            'received_on' => '2026-06-22',
            'allocations' => [['invoice_id' => $invoice->id, 'amount_minor' => 12000]],
        ])
        ->assertForbidden();

    // Nothing was posted at all.
    expect(Payment::query()->count())->toBe(0)
        ->and(PaymentAllocation::query()->count())->toBe(0)
        ->and(arpOpen($invoice))->toBe(30000);
});

test('there is NO agent path to recording a payment — the agent never commits money', function () {
    // The governed tool set is enumerable, and NONE of it is a money-committing capability. The
    // only financial tools are advisory drafts (suggest charge codes / preflight an invoice) —
    // no tool records a payment, allocates one, refunds or collects.
    $tools = app(ToolRegistry::class)->all();
    expect($tools)->not->toBeEmpty();

    foreach ($tools as $key => $tool) {
        $definition = $tool->definition();
        $haystack = strtolower($key.' '.$definition->name);
        foreach (['payment', 'allocat', 'refund', 'collect'] as $needle) {
            expect(str_contains($haystack, $needle))->toBeFalse();
        }

        // And every FINANCIAL tool is hard-capped at "approve" — never auto, so even the advisory
        // financial drafts cannot act unattended (the standing AutonomyPolicy invariant).
        if ($definition->category === ToolDefinition::CATEGORY_FINANCIAL) {
            expect($definition->autonomyCeiling)->toBe(AutonomyPolicy::APPROVE);
        }
    }

    // Adversarial grep: neither the AI module nor the app-layer agent/tool code references the
    // money-writing service, the allocation ledger, or this page's write route — there is no code
    // path, governed or forged, by which an agent records or allocates a payment.
    foreach ([base_path('Modules/AiCore/src'), base_path('app/AiCore')] as $dir) {
        foreach (['PaymentService', 'PaymentAllocation', 'billing.accounts.payments'] as $needle) {
            expect(arpSourceContains($dir, $needle))->toBeFalse();
        }
    }
});

// ── Tenant + account scoping (FIX.1 string-id resolution) ────────────────────────────────────────

test('the record-payment action is tenant-scoped and cannot allocate to another account invoice', function () {
    $fx = arpFixture('alpha');
    $patient = arpPatient();
    $invoice = arpInvoice($fx, $patient, 30000);

    // Another account in the SAME tenant — its invoice is not an allocation target from this page.
    $otherPatient = app(PatientService::class)->create(['first_name' => 'Hans', 'last_name' => 'Other', 'date_of_birth' => '1970-01-01', 'sex' => 'male']);
    $otherInvoice = arpInvoice($fx, $otherPatient, 5000);

    $this->actingAs($fx['actor'])
        ->post(route('billing.accounts.payments.store', $patient->id), [
            'amount_minor' => 5000,
            'method' => 'cash',
            'received_on' => '2026-06-22',
            'allocations' => [['invoice_id' => $otherInvoice->id, 'amount_minor' => 5000]],
        ])
        ->assertNotFound();

    // The forged target was rejected BEFORE any money was written.
    expect(Payment::query()->count())->toBe(0)
        ->and(arpOpen($otherInvoice))->toBe(5000);

    // A second tenant cannot post to the first tenant's account (fail-closed → 404).
    $beta = arpFixture('beta');
    $this->actingAs($beta['actor'])
        ->post(route('billing.accounts.payments.store', $patient->id), [
            'amount_minor' => 1000,
            'method' => 'cash',
            'received_on' => '2026-06-22',
        ])
        ->assertNotFound();

    // Back in the first tenant's context: its invoice is untouched and no money was written.
    app(TenantContext::class)->set($fx['tenant']);
    expect(arpOpen($invoice))->toBe(30000)
        ->and(Payment::query()->count())->toBe(0);
});

// ── The page offers the action over ENGINE figures (reflect-only control) ────────────────────────

test('the account page exposes the record-payment action with the engine open invoices', function () {
    $fx = arpFixture();
    $patient = arpPatient();
    $invoice = arpInvoice($fx, $patient, 30000);

    $this->actingAs($fx['actor'])->get(route('billing.accounts.show', $patient->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/AccountDetail')
            ->where('payment.can_record', true)
            ->where('payment.store_url', route('billing.accounts.payments.store', $patient->id))
            ->where('payment.open_invoices.0.invoice_id', $invoice->id)
            // The offered figure IS the engine's projected open balance, not a page computation.
            ->where('payment.open_invoices.0.open_balance_minor', arpOpen($invoice)));

    // Reflect-only: a view-only biller sees no action control, and the server still refuses.
    $reception = arpUser($fx['tenant'], 'reception');
    $this->actingAs($reception)->get(route('billing.accounts.show', $patient->id))->assertForbidden();
});
