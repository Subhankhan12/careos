<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\AdjustmentService;
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
 * BILLAR.P2 — the AR roll-forward: a reconcile-to-the-unit bridge computed IN THE ENGINE
 * (MetricsService::arRollForward). opening + charges − collections − adjustments − write-offs = closing,
 * tying out EXACTLY (δ=0) against the reconciled projection, over the P1 movements. No page-side math.
 * These tests ADD coverage; no existing behaviour test is modified.
 */

function rfCtx(): TenantContext
{
    return app(TenantContext::class);
}

function rfUser(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, catalog: TariffCatalog}
 */
function rfFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    rfCtx()->set($tenant);

    $actor = rfUser($tenant); // 'billing' role holds billing.view + billing.manage
    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $patient = app(PatientService::class)->create(['first_name' => 'Roll', 'last_name' => 'Forward', 'date_of_birth' => '1988-01-01', 'sex' => 'female']);
    $catalog = TariffCatalog::query()->create(['key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1, 'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => []]);

    return compact('tenant', 'actor', 'branch', 'patient', 'catalog');
}

/** Issue an invoice of `$priceMinor` (VAT-exempt) with a given issue date. */
function rfIssuedInvoice(array $fx, int $priceMinor, string $issueDate): Invoice
{
    static $codeSeq = 44000; // deterministic-unique tariff code (random_int(10,99) collides across a test)
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
    $due = Carbon::parse($issueDate)->addDays(14)->toDateString();

    return $service->issue(
        $service->createDraftFromCharges($fx['patient'], [$charge], $fx['actor'], Invoice::PAYER_SELF_PAY, null, Carbon::parse($issueDate), Carbon::parse($due)),
        $fx['actor'],
    );
}

/** The roll-forward for the period from `$from` to today (the current management view). */
function rfRollForward(array $fx, string $from = '2026-06-01'): array
{
    return app(MetricsService::class)->arRollForward($fx['actor'], $from, now()->toDateString());
}

// ── The roll-forward returns the six figures and ties (base: a fresh charge) ─────────────────────

test('the roll-forward returns the six integer-minor figures and ties out (a fresh charge)', function () {
    $fx = rfFixture();
    rfIssuedInvoice($fx, 20000, '2026-06-05');

    $rf = rfRollForward($fx);

    expect($rf['opening_minor'])->toBe(0)
        ->and($rf['charges_minor'])->toBe(20000)
        ->and($rf['collections_minor'])->toBe(0)
        ->and($rf['adjustments_minor'])->toBe(0)
        ->and($rf['write_offs_minor'])->toBe(0)
        ->and($rf['closing_minor'])->toBe(20000)
        // THE TIE-OUT: opening + charges − collections − adjustments − write-offs === closing.
        ->and($rf['bridge_closing_minor'])->toBe(20000)
        ->and($rf['discrepancy_minor'])->toBe(0)
        ->and($rf['ties'])->toBeTrue();
});

// ── THE TIE-OUT with a P1 write-off + contractual adjustment + a payment ─────────────────────────

test('THE TIE-OUT: a period with a payment, a contractual adjustment and a write-off still ties (delta=0)', function () {
    $fx = rfFixture();
    $invoice = rfIssuedInvoice($fx, 20000, '2026-06-05'); // charges = 20000

    $payments = app(PaymentService::class);
    $payment = $payments->record(4000, 'bank_transfer', $fx['actor'], $fx['patient'], null, 'EUR', now());
    $payments->allocate($payment, $invoice, 4000, $fx['actor']); // collections = 4000

    $adjustments = app(AdjustmentService::class);
    $adjustments->contractualAdjustment($invoice, 3000, 'Insurer-agreed rate.', 'AGR-1', $fx['actor']); // adjustments = 3000
    $adjustments->writeOff($invoice, 5000, 'Residual uncollectible.', $fx['actor']); // write-offs = 5000

    $rf = rfRollForward($fx);

    // 0 + 20000 − 4000 − 3000 − 5000 = 8000, and the independent (projection) closing is also 8000.
    expect($rf['opening_minor'])->toBe(0)
        ->and($rf['charges_minor'])->toBe(20000)
        ->and($rf['collections_minor'])->toBe(4000)
        ->and($rf['adjustments_minor'])->toBe(3000)
        ->and($rf['write_offs_minor'])->toBe(5000)
        ->and($rf['bridge_closing_minor'])->toBe(8000)
        ->and($rf['closing_minor'])->toBe(8000)
        ->and($rf['discrepancy_minor'])->toBe(0)
        ->and($rf['ties'])->toBeTrue();

    // Closing is computed independently as the reconciled projection — it equals the engine's outstanding.
    expect($rf['closing_minor'])->toBe(app(MetricsService::class)->outstandingBalanceMinor($fx['actor']));
});

// ── Base case: payments only, ties to zero ───────────────────────────────────────────────────────

test('a period with only payments ties out (base case)', function () {
    $fx = rfFixture();
    $invoice = rfIssuedInvoice($fx, 20000, '2026-06-05');
    $payments = app(PaymentService::class);
    $payment = $payments->record(20000, 'card', $fx['actor'], $fx['patient'], null, 'EUR', now());
    $payments->allocate($payment, $invoice, 20000, $fx['actor']);

    $rf = rfRollForward($fx);
    expect($rf['charges_minor'])->toBe(20000)
        ->and($rf['collections_minor'])->toBe(20000)
        ->and($rf['closing_minor'])->toBe(0)
        ->and($rf['ties'])->toBeTrue();
});

// ── Opening AR (as of the day before `from`) carries a prior invoice; the period ties ────────────

test('opening AR carries a prior-period invoice and the period bridge ties', function () {
    $fx = rfFixture();
    $prior = rfIssuedInvoice($fx, 15000, '2026-05-10'); // issued BEFORE the period → opening AR
    $payments = app(PaymentService::class);
    $payment = $payments->record(5000, 'cash', $fx['actor'], $fx['patient'], null, 'EUR', now());
    $payments->allocate($payment, $prior, 5000, $fx['actor']); // a collection in the period

    $rf = rfRollForward($fx, '2026-06-01');

    expect($rf['opening_minor'])->toBe(15000) // outstanding as of 2026-05-31
        ->and($rf['charges_minor'])->toBe(0)  // nothing issued in the period
        ->and($rf['collections_minor'])->toBe(5000)
        ->and($rf['closing_minor'])->toBe(10000) // 15000 − 5000
        ->and($rf['bridge_closing_minor'])->toBe(10000)
        ->and($rf['ties'])->toBeTrue();
});

// ── A credit note in the period folds into charges (net) and still ties ──────────────────────────

test('a credit note issued in the period nets into charges and the bridge still ties', function () {
    $fx = rfFixture();
    $invoice = rfIssuedInvoice($fx, 10000, '2026-06-05'); // unpaid, issued
    app(IssueService::class)->creditNote($invoice, null, 'Full credit — billed in error.', $fx['actor']);

    $rf = rfRollForward($fx);

    // gross charge 10000 − credit note 10000 = 0 net charges; the invoice is cancelled → closing 0.
    expect($rf['charges_minor'])->toBe(0)
        ->and($rf['closing_minor'])->toBe(0)
        ->and($rf['discrepancy_minor'])->toBe(0)
        ->and($rf['ties'])->toBeTrue();
});

// ── A NON-TIE is SURFACED, never hidden (an unmodeled movement drifts the projection) ────────────

test('an unmodeled movement is SURFACED as a non-tie, never papered over', function () {
    $fx = rfFixture();
    $invoice = rfIssuedInvoice($fx, 20000, '2026-06-05');

    expect(rfRollForward($fx)['ties'])->toBeTrue(); // clean → ties

    // Inject an AR change that is NOT one of the five modeled movements — a raw projection mutation
    // (no payment, no adjustment, no credit note). The bridge (built from real movements) can't see it.
    DB::table('invoice_balances')->where('invoice_id', $invoice->id)->update(['open_balance_minor' => 15000]);

    $rf = rfRollForward($fx);
    // The reconcile SURFACES the drift: bridge says 20000, the projection says 15000 → δ=5000, ties=false.
    expect($rf['bridge_closing_minor'])->toBe(20000)
        ->and($rf['closing_minor'])->toBe(15000)
        ->and($rf['discrepancy_minor'])->toBe(5000)
        ->and($rf['ties'])->toBeFalse();
});

// ── billing.view + tenant-scoped ─────────────────────────────────────────────────────────────────

test('the roll-forward is gated on billing.view and is tenant-scoped', function () {
    $fx = rfFixture('alpha');
    rfIssuedInvoice($fx, 20000, '2026-06-05');

    // A non-financial user (reception has no billing.view) is refused.
    $reception = rfUser($fx['tenant'], 'reception');
    expect(fn () => app(MetricsService::class)->arRollForward($reception, '2026-06-01', now()->toDateString()))
        ->toThrow(AuthorizationException::class);

    // A second tenant sees none of alpha's AR — its roll-forward is all zeros.
    $beta = rfFixture('beta');
    $rf = rfRollForward($beta);
    expect($rf['opening_minor'])->toBe(0)
        ->and($rf['charges_minor'])->toBe(0)
        ->and($rf['closing_minor'])->toBe(0)
        ->and($rf['ties'])->toBeTrue();
});
