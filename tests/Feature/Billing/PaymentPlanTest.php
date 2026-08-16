<?php

use Illuminate\Auth\Access\AuthorizationException;
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
use Modules\Billing\Models\PaymentPlan;
use Modules\Billing\Models\PaymentPlanInstallment;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PaymentPlanService;
use Modules\Billing\Services\PaymentService;
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
 * ARDETAIL.P5 — the installment payment plan. These tests prove the FENCE: a plan can only schedule
 * money that is REALLY owed (total <= the account's engine outstanding; no second active plan), its
 * installments are an EXACT PARTITION of that total (Σ === total, δ=0, the last absorbing the integer
 * remainder — no phantom or lost minor unit), the plan itself writes NO money (settling an installment
 * goes through the guarded PaymentService of ARDETAIL.P4, so the over-allocation guard, the append-only
 * ledger and the reconcile invariants all still bind), it is OPERATOR-created (billing.manage; the agent
 * has no path), and every lifecycle step is audited. These tests ADD coverage; no existing behaviour
 * test is modified.
 */

const PPL_PERIOD = '2026-06';

function pplUser(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, catalog: TariffCatalog}
 */
function pplFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $actor = pplUser($tenant);
    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $catalog = TariffCatalog::query()->create(['key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1, 'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => []]);

    return compact('tenant', 'actor', 'branch', 'catalog');
}

/**
 * @param  array{tenant: Tenant, actor: User, branch: Branch, catalog: TariffCatalog}  $fx
 */
function pplInvoice(array $fx, Patient $patient, int $priceMinor, string $issueDate = '2026-06-01', string $dueDate = '2026-06-20'): Invoice
{
    static $codeSeq = 91000; // deterministic-unique tariff code
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

function pplPatient(string $last = 'Planner'): Patient
{
    return app(PatientService::class)->create(['first_name' => 'Petra', 'last_name' => $last, 'date_of_birth' => '1981-03-09', 'sex' => 'female']);
}

function pplOpen(Invoice $invoice): int
{
    return (int) InvoiceBalance::query()->where('invoice_id', $invoice->id)->value('open_balance_minor');
}

function pplReport(): array
{
    return app(ReconciliationEngine::class)->check(PPL_PERIOD);
}

/** Recursively scan a source dir; true if any .php file CONTAINS the needle (the agent-exclusion proof). */
function pplSourceContains(string $absDir, string $needle): bool
{
    if (! is_dir($absDir)) {
        return false;
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

// ── THE TIE + THE PARTITION (the crux): Σ installments === total === real outstanding, δ=0 ───────

test('a plan partitions its total exactly — the last installment absorbs the remainder (no phantom or lost minor unit)', function () {
    $fx = pplFixture();
    $patient = pplPatient();
    // 31'300 minor / 3 does NOT divide evenly (10433.33) — the classic rounding trap.
    $invoice = pplInvoice($fx, $patient, 31300);

    $plan = app(PaymentPlanService::class)->create($patient, 31300, 3, '2026-07-01', $fx['actor']);

    $amounts = PaymentPlanInstallment::query()->where('payment_plan_id', $plan->id)->orderBy('sequence')->pluck('amount_minor')->all();

    expect($amounts)->toBe([10433, 10433, 10434])                 // the LAST absorbs the remainder
        ->and(array_sum($amounts))->toBe(31300)                    // Σ === total EXACTLY (δ=0)
        ->and(array_sum($amounts))->toBe($plan->total_minor)
        // ...and the total is the account's REAL outstanding — nothing invented.
        ->and($plan->total_minor)->toBe(app(PaymentPlanService::class)->accountOutstandingMinor((string) $patient->id))
        ->and($plan->outstanding_at_creation_minor)->toBe(31300)
        ->and(pplOpen($invoice))->toBe(31300);                     // scheduling moved NO money

    // The engine's own presentation reports the tie rather than the page computing one.
    $presented = app(PaymentPlanService::class)->present($plan, now());
    expect($presented['ties'])->toBeTrue()
        ->and($presented['remaining_minor'])->toBe(31300)
        ->and($presented['paid_minor'])->toBe(0);
});

test('the plan total ties to the ARDETAIL.P1 ledger outstanding for the account', function () {
    $fx = pplFixture();
    $patient = pplPatient();
    pplInvoice($fx, $patient, 20000);
    pplInvoice($fx, $patient, 13000, '2026-06-05', '2026-06-25');

    $ledger = app(MetricsService::class)->accountLedger($fx['actor'], (string) $patient->id, now());
    $planned = app(PaymentPlanService::class)->accountOutstandingMinor((string) $patient->id);

    // The plan measures the SAME outstanding the P1 ledger ties to (δ=0).
    expect($planned)->toBe($ledger['account_outstanding_minor'])
        ->and($planned)->toBe(33000);

    $plan = app(PaymentPlanService::class)->create($patient, $planned, 6, '2026-07-01', $fx['actor']);
    expect((int) PaymentPlanInstallment::query()->where('payment_plan_id', $plan->id)->sum('amount_minor'))->toBe(33000);
});

test('a plan cannot schedule more than the real outstanding, and a second active plan is refused', function () {
    $fx = pplFixture();
    $patient = pplPatient();
    pplInvoice($fx, $patient, 20000);
    $svc = app(PaymentPlanService::class);

    // One minor unit beyond the real balance is phantom money — refused.
    expect(fn () => $svc->create($patient, 20001, 4, '2026-07-01', $fx['actor']))
        ->toThrow(InvalidArgumentException::class, 'A payment plan cannot schedule more than the account outstanding balance.');
    expect(PaymentPlan::query()->count())->toBe(0);

    // A partial plan is fine...
    $svc->create($patient, 12000, 3, '2026-07-01', $fx['actor']);
    // ...but a second active plan could together exceed the balance, so it is refused.
    expect(fn () => $svc->create($patient, 8000, 2, '2026-07-01', $fx['actor']))
        ->toThrow(InvalidArgumentException::class, 'This account already has an active payment plan.');

    expect(PaymentPlan::query()->count())->toBe(1);

    // An account with nothing owed cannot have a plan at all.
    $empty = pplPatient('Nodebt');
    expect(fn () => $svc->create($empty, 5000, 2, '2026-07-01', $fx['actor']))
        ->toThrow(InvalidArgumentException::class, 'This account has no outstanding balance to schedule.');
});

// ── PAYING an installment goes through the P4 guarded PaymentService + reconciles ────────────────

test('paying an installment records through the guarded PaymentService and the account reconciles (I1-I6, delta=0)', function () {
    $fx = pplFixture();
    $patient = pplPatient();
    $invoice = pplInvoice($fx, $patient, 30000);
    $svc = app(PaymentPlanService::class);
    $metrics = app(MetricsService::class);

    $plan = $svc->create($patient, 30000, 3, '2026-07-01', $fx['actor']);
    $first = PaymentPlanInstallment::query()->where('payment_plan_id', $plan->id)->where('sequence', 1)->firstOrFail();

    $payment = $svc->payInstallment($first, 'bank_transfer', '2026-06-22', $fx['actor'], 'PLAN 1/3');

    // A REAL Payment through the engine (not a plan-side write), allocated to the open invoice.
    expect($payment->amount_minor)->toBe(10000)
        ->and($payment->method)->toBe('bank_transfer')
        ->and($payment->recorded_by)->toBe($fx['actor']->id);
    $allocation = PaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail();
    expect($allocation->invoice_id)->toBe($invoice->id)
        ->and($allocation->amount_minor)->toBe(10000)
        ->and(pplOpen($invoice))->toBe(20000);

    // The installment records WHICH payment settled it; the plan is still running.
    $first->refresh();
    expect($first->status)->toBe(PaymentPlanInstallment::STATUS_PAID)
        ->and($first->payment_id)->toBe($payment->id)
        ->and($first->paid_at)->not->toBeNull()
        ->and(PaymentPlan::query()->whereKey($plan->id)->value('status'))->toBe(PaymentPlan::STATUS_ACTIVE);

    // RECONCILE: six invariants, every delta 0.
    $report = pplReport();
    expect($report['passed'])->toBeTrue()->and($report['invariants'])->toHaveCount(6);
    foreach ($report['invariants'] as $inv) {
        expect($inv['ok'])->toBeTrue()->and($inv['delta_minor'])->toBe(0);
    }

    // The P1 ledger + outstanding moved by exactly the installment and still tie.
    $ledger = $metrics->accountLedger($fx['actor'], (string) $patient->id, now());
    expect($ledger['account_outstanding_minor'])->toBe(20000)
        ->and($ledger['ties'])->toBeTrue()
        ->and($ledger['account_outstanding_minor'])->toBe($metrics->outstandingBalanceMinor($fx['actor']));

    // Audited through the service (append-only audit ledger).
    $audited = DB::selectOne('SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?', [$fx['tenant']->id, 'billing.payment_plan_installment_paid'])->c;
    expect((int) $audited)->toBe(1);
});

test('paying every installment completes the plan, settles the balance and still reconciles', function () {
    $fx = pplFixture();
    $patient = pplPatient();
    $invoice = pplInvoice($fx, $patient, 31300);
    $svc = app(PaymentPlanService::class);

    $plan = $svc->create($patient, 31300, 3, '2026-07-01', $fx['actor']);
    foreach (PaymentPlanInstallment::query()->where('payment_plan_id', $plan->id)->orderBy('sequence')->get() as $installment) {
        $svc->payInstallment($installment, 'bank_transfer', '2026-06-22', $fx['actor']);
    }

    // The three installments (10433 + 10433 + 10434) settle the balance to the unit.
    expect(pplOpen($invoice))->toBe(0)
        ->and((string) InvoiceBalance::query()->where('invoice_id', $invoice->id)->value('status'))->toBe(Invoice::STATUS_PAID)
        ->and(PaymentPlan::query()->whereKey($plan->id)->value('status'))->toBe(PaymentPlan::STATUS_COMPLETED)
        ->and((int) Payment::query()->sum('amount_minor'))->toBe(31300);

    foreach (pplReport()['invariants'] as $inv) {
        expect($inv['ok'])->toBeTrue()->and($inv['delta_minor'])->toBe(0);
    }

    // An installment cannot be settled twice, and a completed plan takes no more payments.
    $paid = PaymentPlanInstallment::query()->where('payment_plan_id', $plan->id)->where('sequence', 1)->firstOrFail();
    expect(fn () => $svc->payInstallment($paid, 'cash', '2026-06-23', $fx['actor']))
        ->toThrow(InvalidArgumentException::class, 'Only an active payment plan can take an installment payment.');
});

test('the over-allocation guard still binds: an installment never allocates past the open balance', function () {
    $fx = pplFixture();
    $patient = pplPatient();
    // Plan the full balance, then WRITE THE BALANCE DOWN behind the plan's back with a direct
    // payment — the remaining installments now exceed what the account can absorb.
    $invoice = pplInvoice($fx, $patient, 30000);
    $svc = app(PaymentPlanService::class);
    $plan = $svc->create($patient, 30000, 2, '2026-07-01', $fx['actor']);

    $direct = app(PaymentService::class);
    $sidePayment = $direct->record(25000, 'cash', $fx['actor'], $patient, null, 'EUR', '2026-06-21');
    $direct->allocate($sidePayment, $invoice, 25000, $fx['actor']);
    expect(pplOpen($invoice))->toBe(5000);

    // The 15'000 installment can only be absorbed up to the 5'000 that is still open.
    $first = PaymentPlanInstallment::query()->where('payment_plan_id', $plan->id)->where('sequence', 1)->firstOrFail();
    $payment = $svc->payInstallment($first, 'bank_transfer', '2026-06-22', $fx['actor']);

    $allocated = (int) PaymentAllocation::query()->where('payment_id', $payment->id)->sum('amount_minor');
    expect($allocated)->toBe(5000)                                  // capped by the open balance
        ->and(pplOpen($invoice))->toBe(0)                           // never driven negative
        ->and($direct->unallocated($payment))->toBe(10000);         // the rest stays an honest remainder

    // No allocation anywhere exceeds its invoice's total — and the books still tie.
    foreach (pplReport()['invariants'] as $inv) {
        expect($inv['ok'])->toBeTrue()->and($inv['delta_minor'])->toBe(0);
    }
});

// ── OPERATOR-GATED + no agent path ───────────────────────────────────────────────────────────────

test('plans are operator-gated — reception cannot create one, pay an installment or cancel one', function () {
    $fx = pplFixture();
    $patient = pplPatient();
    $invoice = pplInvoice($fx, $patient, 30000);
    $svc = app(PaymentPlanService::class);
    $plan = $svc->create($patient, 30000, 2, '2026-07-01', $fx['actor']);
    $first = PaymentPlanInstallment::query()->where('payment_plan_id', $plan->id)->where('sequence', 1)->firstOrFail();

    $reception = pplUser($fx['tenant'], 'reception'); // no billing.manage

    expect(fn () => $svc->create($patient, 1000, 1, '2026-07-01', $reception))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->payInstallment($first, 'cash', '2026-06-22', $reception))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->cancel($plan, 'nope', $reception))->toThrow(AuthorizationException::class);

    // Through the real stack, every plan route refuses reception at the gate.
    $this->actingAs($reception)->post(route('billing.accounts.plans.store', $patient->id), ['total_minor' => 1000, 'installment_count' => 1, 'start_date' => '2026-07-01'])->assertForbidden();
    $this->actingAs($reception)->post(route('billing.accounts.plans.pay', [$patient->id, $first->id]), ['method' => 'cash', 'received_on' => '2026-06-22'])->assertForbidden();
    $this->actingAs($reception)->post(route('billing.accounts.plans.cancel', [$patient->id, $plan->id]), ['reason' => 'nope'])->assertForbidden();

    // Nothing was created or moved.
    expect(PaymentPlan::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(0)
        ->and(pplOpen($invoice))->toBe(30000);
});

test('there is NO agent path to creating a plan or committing an installment', function () {
    $tools = app(ToolRegistry::class)->all();
    expect($tools)->not->toBeEmpty();

    foreach ($tools as $key => $tool) {
        $definition = $tool->definition();
        $haystack = strtolower($key.' '.$definition->name);
        // NB: a bare "plan" is deliberately NOT a needle — it collides with the unrelated nursing
        // scheduling tool "Replan nursing day", which moves visits, not money.
        foreach (['payment', 'installment', 'allocat', 'payment_plan'] as $needle) {
            expect(str_contains($haystack, $needle))->toBeFalse();
        }
        if ($definition->category === ToolDefinition::CATEGORY_FINANCIAL) {
            expect($definition->autonomyCeiling)->toBe(AutonomyPolicy::APPROVE);
        }
    }

    // Adversarial grep: no AI code references the plan service/models or their routes, so there is
    // no path — governed or forged — by which an agent schedules or commits money.
    foreach ([base_path('Modules/AiCore/src'), base_path('app/AiCore')] as $dir) {
        foreach (['PaymentPlan', 'PaymentService', 'billing.accounts.plans'] as $needle) {
            expect(pplSourceContains($dir, $needle))->toBeFalse();
        }
    }
});

// ── Lifecycle: cancel / default are audited; tenant scope ────────────────────────────────────────

test('cancelling and defaulting a plan require a reason and are audited', function () {
    $fx = pplFixture();
    $patient = pplPatient();
    pplInvoice($fx, $patient, 30000);
    $svc = app(PaymentPlanService::class);

    $plan = $svc->create($patient, 30000, 3, '2026-07-01', $fx['actor']);
    expect(fn () => $svc->cancel($plan, '   ', $fx['actor']))->toThrow(InvalidArgumentException::class);

    $cancelled = $svc->cancel($plan, 'Patient settled privately.', $fx['actor']);
    expect($cancelled->status)->toBe(PaymentPlan::STATUS_CANCELLED)
        ->and($cancelled->closed_reason)->toBe('Patient settled privately.')
        ->and($cancelled->closed_at)->not->toBeNull();

    // A closed plan cannot be closed again, and frees the account for a new arrangement.
    expect(fn () => $svc->cancel($cancelled, 'again', $fx['actor']))->toThrow(InvalidArgumentException::class);
    $second = $svc->create($patient, 15000, 2, '2026-08-01', $fx['actor']);
    expect(fn () => $svc->markDefaulted($second, 'Stopped paying.', $fx['actor']))->not->toThrow(InvalidArgumentException::class);

    foreach (['billing.payment_plan_created', 'billing.payment_plan_cancelled', 'billing.payment_plan_defaulted'] as $action) {
        $count = DB::selectOne('SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?', [$fx['tenant']->id, $action])->c;
        expect((int) $count)->toBeGreaterThanOrEqual(1);
    }
});

test('the plan surface is tenant-scoped and account-scoped', function () {
    $fx = pplFixture('alpha');
    $patient = pplPatient();
    pplInvoice($fx, $patient, 30000);
    $plan = app(PaymentPlanService::class)->create($patient, 30000, 2, '2026-07-01', $fx['actor']);
    $first = PaymentPlanInstallment::query()->where('payment_plan_id', $plan->id)->where('sequence', 1)->firstOrFail();

    // Another account in the same tenant cannot settle this plan's installment from its own page.
    $other = pplPatient('Other');
    $this->actingAs($fx['actor'])
        ->post(route('billing.accounts.plans.pay', [$other->id, $first->id]), ['method' => 'cash', 'received_on' => '2026-06-22'])
        ->assertNotFound();
    expect(Payment::query()->count())->toBe(0);

    // A second tenant cannot create a plan on the first tenant's account (fail-closed → 404).
    $beta = pplFixture('beta');
    $this->actingAs($beta['actor'])
        ->post(route('billing.accounts.plans.store', $patient->id), ['total_minor' => 1000, 'installment_count' => 1, 'start_date' => '2026-07-01'])
        ->assertNotFound();

    app(TenantContext::class)->set($fx['tenant']);
    expect(PaymentPlan::query()->count())->toBe(1);
});

// ── The page displays the engine plan (no Vue money math) ────────────────────────────────────────

test('the account page renders the engine plan and its schedule', function () {
    $fx = pplFixture();
    $patient = pplPatient();
    pplInvoice($fx, $patient, 31300);
    $plan = app(PaymentPlanService::class)->create($patient, 31300, 3, '2026-07-01', $fx['actor']);

    $this->actingAs($fx['actor'])->get(route('billing.accounts.show', $patient->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/AccountDetail')
            ->where('plan.can_manage', true)
            ->where('plan.current.id', $plan->id)
            ->where('plan.current.status', PaymentPlan::STATUS_ACTIVE)
            ->where('plan.current.total_minor', 31300)
            ->where('plan.current.ties', true)
            ->where('plan.current.installments.2.amount_minor', 10434)   // the remainder-absorbing line
            ->where('plan.current.installments.0.due_date', '2026-07-01')
            ->where('plan.current.installments.1.due_date', '2026-08-01'));
});
