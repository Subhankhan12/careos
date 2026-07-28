<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Services\AdmissionService;
use Modules\Hospital\Services\BedBillingService;
use Modules\Hospital\Services\BedService;
use Modules\Hospital\Services\WardService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalCaseCharge;
use Modules\Surgery\Models\SurgicalItem;
use Modules\Surgery\Services\SurgicalBillingService;
use Modules\Surgery\Services\SurgicalCaseService;
use Modules\Surgery\Services\SurgicalStockService;
use Modules\Surgery\Services\SurgicalUsageService;

uses(RefreshDatabase::class);

/*
 * SURGERY.G5 — surgical billing. A surgical case accrues charges (procedure + theatre-time +
 * consumables/implants) through the EXISTING billing engine, and they invoice + RECONCILE-TO-THE-UNIT.
 * STRICTLY ORCHESTRATION — NO new billing/pricing/VAT/line-total math (each billable thing is a
 * tenant-authored TariffItem; the engine prices + snapshots everything; the invoice is the existing
 * validate → createDraftFromCharges → issue flow). The pharmacy G5 / bed-day (HOSPITAL.G6) pattern. The
 * final Phase-5 gate — it completes the OR vertical.
 */

// A fixed month so the reconciliation period ties out deterministically (now() is frozen in beforeEach).
const SB_PERIOD = '2026-06';

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function sbCtx(): TenantContext
{
    return app(TenantContext::class);
}

function sbUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, branch: Branch, surgeon: User, biller: User, reception: User, surgeonProfile: StaffProfile, patient: Patient, case: SurgicalCase} */
function sbFixture(string $slug = 'surgbill'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Surgery', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    sbCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $surgeon = sbUser($tenant, 'surgeon');       // surgery.manage + note.write — the OR team (NOT billing.manage)
    $biller = sbUser($tenant, 'org_admin');      // billing.manage (+ admission/ward/bed.manage) — the billing office
    $reception = sbUser($tenant, 'reception');   // neither
    $surgeonProfile = StaffProfile::query()->create([
        'first_name' => 'Sara', 'last_name' => 'Sharp', 'display_name' => 'Dr Sara Sharp',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Percy', 'last_name' => 'Patient', 'date_of_birth' => '1972-02-02', 'sex' => 'male']);
    $case = app(SurgicalCaseService::class)->schedule($surgeon, $patient, $surgeonProfile, 'Laparoscopic appendectomy', Carbon::parse('2026-06-15 08:00:00'));

    return compact('tenant', 'branch', 'surgeon', 'biller', 'reception', 'surgeonProfile', 'patient', 'case');
}

/**
 * Price the standard bundle (procedure @ 250000, theatre-time @ 500/min, a consumable @ 800), record a
 * consumable usage (×3), complete the case, and capture its charges (procedure ×1 + theatre-time ×60 +
 * consumable ×3) through the engine. Returns the captured charges (250000 + 30000 + 2400 = 282400).
 *
 * @param  array{surgeon: User, biller: User, case: SurgicalCase}  $fx
 * @return Collection<int, Charge>
 */
function sbCompleteAndCharge(array $fx): Collection
{
    $svc = app(SurgicalBillingService::class);
    $svc->priceProcedure($fx['biller'], 'APPEND-01', 'Laparoscopic appendectomy', 250000);
    $svc->priceTheatreTime($fx['biller'], 500);

    $stock = app(SurgicalStockService::class);
    $gauze = $stock->createItem($fx['surgeon'], 'SUT-GAUZE', 'Gauze swab', false);
    $stock->receive($fx['surgeon'], $gauze, 100);
    $svc->priceItem($fx['biller'], $gauze, 800);
    app(SurgicalUsageService::class)->recordUsage($fx['surgeon'], $fx['case'], $gauze, 3);

    $cases = app(SurgicalCaseService::class);
    foreach ([SurgicalCase::STATUS_PRE_OP, SurgicalCase::STATUS_IN_PROGRESS, SurgicalCase::STATUS_COMPLETED] as $to) {
        $cases->transition($fx['surgeon'], $fx['case']->fresh(), $to);
    }

    return $svc->chargeCase($fx['biller'], $fx['case']->fresh(), 'APPEND-01', 60);
}

test('surgical pricing authors tenant TariffItems (procedure, theatre-time, item): integer minor units, own codes, no licensed set', function () {
    $fx = sbFixture();
    $svc = app(SurgicalBillingService::class);

    $procedure = $svc->priceProcedure($fx['biller'], 'APPEND-01', 'Laparoscopic appendectomy', 250000);
    $theatre = $svc->priceTheatreTime($fx['biller'], 500);
    $gauze = app(SurgicalStockService::class)->createItem($fx['surgeon'], 'SUT-GAUZE', 'Gauze swab', false);
    $item = $svc->priceItem($fx['biller'], $gauze, 800);

    // Every price lives in the EXISTING tariff store — tenant-authored items in the 'surgery' catalog.
    $catalog = TariffCatalog::query()->where('key', SurgicalBillingService::CATALOG_KEY)->firstOrFail();
    expect($procedure->tariff_catalog_id)->toBe($catalog->id)
        ->and($procedure->code)->toBe('APPEND-01')            // the tenant's OWN code — NOT a licensed identifier
        ->and($procedure->unit_price_minor)->toBe(250000)     // integer minor units
        ->and($procedure->vat_rate_bp)->toBe(0)
        ->and($procedure->unit)->toBe('procedure')
        ->and($theatre->code)->toBe(SurgicalBillingService::THEATRE_TIME_CODE)
        ->and($theatre->unit_price_minor)->toBe(500)
        ->and($theatre->unit)->toBe('theatre-minute')
        ->and($item->tariff_catalog_id)->toBe($catalog->id)
        ->and($item->unit_price_minor)->toBe(800)
        ->and(is_int($item->unit_price_minor))->toBeTrue()
        ->and($gauze->fresh()->tariff_item_id)->toBe($item->id) // the item is now priced
        ->and($gauze->fresh()->isPriced())->toBeTrue();
});

test('a completed case captures charges through the EXISTING engine (engine prices + snapshots), idempotently', function () {
    $fx = sbFixture();

    $charges = sbCompleteAndCharge($fx);

    // Three charges: procedure (1×250000), theatre-time (60×500), the consumable (3×800) — priced BY THE ENGINE.
    $byCode = $charges->keyBy('code');
    expect($charges)->toHaveCount(3)
        ->and($byCode['APPEND-01']->quantity)->toBe(1)
        ->and($byCode['APPEND-01']->unit_price_minor)->toBe(250000)       // snapshotted BY THE ENGINE
        ->and($byCode['APPEND-01']->line_total_minor)->toBe(250000)        // 1 × 250000, computed BY THE ENGINE
        ->and($byCode[SurgicalBillingService::THEATRE_TIME_CODE]->quantity)->toBe(60)
        ->and($byCode[SurgicalBillingService::THEATRE_TIME_CODE]->line_total_minor)->toBe(30000) // 60 × 500
        ->and($byCode['SUT-GAUZE']->quantity)->toBe(3)
        ->and($byCode['SUT-GAUZE']->line_total_minor)->toBe(2400)           // 3 × 800
        ->and(SurgicalCaseCharge::query()->where('surgical_case_id', $fx['case']->id)->count())->toBe(3);

    // Idempotent: a case is billed once (the surgical_case_charges link). Re-charging returns the SAME charges.
    $again = app(SurgicalBillingService::class)->chargeCase($fx['biller'], $fx['case']->fresh(), 'APPEND-01', 60);
    expect($again->pluck('id')->sort()->values()->all())->toBe($charges->pluck('id')->sort()->values()->all())
        ->and(Charge::query()->count())->toBe(3);
});

test('a case invoice INCLUDING the surgical charges RECONCILES-TO-THE-UNIT (six invariants, delta = 0)', function () {
    Storage::fake('local');
    $fx = sbFixture();

    sbCompleteAndCharge($fx); // 250000 + 30000 + 2400 = 282400

    $invoice = app(SurgicalBillingService::class)->invoiceCase($fx['biller'], $fx['case']->fresh());

    expect($invoice->status)->toBe(Invoice::STATUS_ISSUED)
        ->and($invoice->series)->toBe(Invoice::SERIES_INVOICE)
        ->and($invoice->number)->not->toBeNull()          // gapless number from the existing issuer
        ->and($invoice->total_minor)->toBe(282400);

    // Every surgical charge is INVOICED on exactly this invoice; none left dangling.
    expect(Charge::query()->where('patient_id', $fx['patient']->id)->where('status', Charge::STATUS_INVOICED)->where('invoice_id', $invoice->id)->count())->toBe(3)
        ->and(Charge::query()->where('patient_id', $fx['patient']->id)->whereNull('invoice_id')->count())->toBe(0);

    // THE KEY PROOF: the existing ReconciliationEngine ties the period to the unit WITH surgical charges present —
    // every invariant passes and I4 (N charges → 1 invoice) is exact: delta_minor === 0.
    $report = app(ReconciliationEngine::class)->check(SB_PERIOD);
    $i4 = collect($report['invariants'])->firstWhere('invariant', 'I4');
    expect($report['passed'])->toBeTrue()
        ->and($i4['ok'])->toBeTrue()
        ->and($i4['delta_minor'])->toBe(0);
});

test('an INPATIENT surgery: its charges join the stay discharge invoice (existing invoiceStay) and reconcile-to-the-unit', function () {
    Storage::fake('local');
    $fx = sbFixture();

    // Admit the patient (the org_admin biller holds admission/ward/bed + billing.manage — the hospital actor).
    app(BedBillingService::class)->seedStarter($fx['biller']);
    $ward = app(WardService::class)->create($fx['biller'], $fx['branch']->id, 'Ward 1', 'W1');
    $bed = app(BedService::class)->create($fx['biller'], $ward, '1', Bed::TYPE_GENERAL);
    $stay = app(AdmissionService::class)->admit($fx['biller'], $fx['patient'], $bed, $fx['surgeonProfile'], Stay::TYPE_ELECTIVE);
    $stay->forceFill(['admitted_at' => Carbon::parse('2026-06-10 08:00:00')])->save();

    // A surgical case DURING the stay (linked via stay_id) is priced + charged. Surgical charges are just patient
    // charges with a service_date inside the stay window — Surgery never imports Hospital (the shared engine).
    $svc = app(SurgicalBillingService::class);
    $svc->priceProcedure($fx['biller'], 'APPEND-01', 'Laparoscopic appendectomy', 250000);
    $svc->priceTheatreTime($fx['biller'], 500);
    $case = app(SurgicalCaseService::class)->schedule($fx['surgeon'], $fx['patient'], $fx['surgeonProfile'], 'Inpatient appendectomy', Carbon::parse('2026-06-12 09:00:00'), $stay->id);
    $svc->chargeCase($fx['biller'], $case, 'APPEND-01', 45); // 250000 + 45×500 = 272500 of surgical charges

    // Discharge + invoice the STAY: the existing invoiceStay sweeps the bed-days AND the surgical charges (same
    // gather-by-patient+period) onto ONE invoice.
    app(AdmissionService::class)->discharge($fx['biller'], $stay->fresh(), Stay::DISPOSITION_HOME, 'stable');
    $invoice = app(BedBillingService::class)->invoiceStay($fx['biller'], $stay->fresh());

    // The surgical charges rode onto the stay invoice (INVOICED, this invoice) alongside the bed-days.
    $surgical = Charge::query()->where('invoice_id', $invoice->id)->whereIn('code', ['APPEND-01', SurgicalBillingService::THEATRE_TIME_CODE])->get();
    expect($surgical)->toHaveCount(2)
        ->and($surgical->firstWhere('code', 'APPEND-01')->status)->toBe(Charge::STATUS_INVOICED)
        ->and(Charge::query()->where('patient_id', $fx['patient']->id)->whereNull('invoice_id')->count())->toBe(0); // nothing dangling

    // THE KEY PROOF (inpatient path): reconcile-to-the-unit with the surgical charges on the stay invoice.
    $report = app(ReconciliationEngine::class)->check(SB_PERIOD);
    $i4 = collect($report['invariants'])->firstWhere('invariant', 'I4');
    expect($report['passed'])->toBeTrue()
        ->and($i4['ok'])->toBeTrue()
        ->and($i4['delta_minor'])->toBe(0);
});

test('the snapshotted fee stands: re-pricing a surgical item later never changes a past charge', function () {
    $fx = sbFixture();

    $charges = sbCompleteAndCharge($fx);
    $gauzeCharge = $charges->firstWhere('code', 'SUT-GAUZE');
    expect($gauzeCharge->unit_price_minor)->toBe(800)->and($gauzeCharge->line_total_minor)->toBe(2400);

    // The tenant re-prices the consumable upward — a future concern, never retroactive.
    $gauze = SurgicalItem::query()->where('code', 'SUT-GAUZE')->firstOrFail();
    app(SurgicalBillingService::class)->priceItem($fx['biller'], $gauze, 99999);
    expect(TariffItem::query()->where('code', 'SUT-GAUZE')->firstOrFail()->unit_price_minor)->toBe(99999);

    // The already-captured charge keeps the snapshotted fee + line total.
    expect($gauzeCharge->fresh()->unit_price_minor)->toBe(800)
        ->and($gauzeCharge->fresh()->line_total_minor)->toBe(2400);
});

test('FENCE: Surgery computes NO billing money — pricing/VAT/line-total math lives only in the engine; a price is a rate', function () {
    // A surgical price is a RATE the tenant authors (unit_price_minor / vat_rate_bp = 0 keys are allowed); what
    // must NEVER appear is COMPUTED money: line/VAT/subtotal totals or VAT rounding.
    $forbidden = ['line_total_minor', 'vat_total_minor', 'subtotal_minor', 'vatMinor', 'intdiv('];
    $files = collect(File::allFiles(base_path('Modules/Surgery/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');

    expect($files)->not->toBeEmpty();
    foreach ($files as $file) {
        foreach ($forbidden as $needle) {
            expect(str_contains(File::get($file->getPathname()), $needle))
                ->toBeFalse("Surgery must not compute billing money ({$needle}) — found in {$file->getRelativePathname()}");
        }
    }

    // The billing link table stores NO money — it is a pure idempotency bridge (case ↔ charge).
    $linkCols = Schema::getColumnListing('surgical_case_charges');
    foreach (['unit_price_minor', 'line_total_minor', 'amount_minor', 'price_minor', 'total_minor', 'vat_rate_bp'] as $word) {
        expect($linkCols)->not->toContain($word, "surgical_case_charges must store no money: {$word}");
    }

    // And a price is a rate, not a clinical/appropriateness verdict — no judgment column on the surgical item.
    $itemCols = Schema::getColumnListing('surgical_items');
    foreach (['verdict', 'appropriateness', 'severity', 'score', 'rating', 'medical_necessity'] as $word) {
        expect($itemCols)->not->toContain($word, "surgical_items must not carry a billing/clinical-judgment column: {$word}");
    }
});

test('surgical billing is RBAC-gated (billing.manage — the billing office, not the OR team) and tenant scoped, fail closed', function () {
    $fx = sbFixture();
    $svc = app(SurgicalBillingService::class);
    $gauze = app(SurgicalStockService::class)->createItem($fx['surgeon'], 'SUT-GAUZE', 'Gauze swab', false);

    // reception (no billing.manage) can neither price nor charge.
    expect(fn () => $svc->priceProcedure($fx['reception'], 'P', 'P', 100))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->priceItem($fx['reception'], $gauze, 100))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->chargeCase($fx['reception'], $fx['case'], 'P', 10))->toThrow(AuthorizationException::class);

    // The OR team bills nothing: the SURGEON holds surgery.manage but NOT billing.manage — charging is refused.
    expect(fn () => $svc->chargeCase($fx['surgeon'], $fx['case'], 'P', 10))->toThrow(AuthorizationException::class);

    // The pricing surface is billing.manage-gated through the real stack.
    sbCtx()->forget();
    $this->actingAs($fx['biller'])->get('/surgery/pricing')->assertOk();
    sbCtx()->forget();
    $this->actingAs($fx['reception'])->get('/surgery/pricing')->assertForbidden();

    // Cross-tenant: tenant B's biller cannot charge tenant A's case — fail closed.
    $fxB = sbFixture('beta');
    sbCtx()->set($fxB['tenant']);
    expect(fn () => $svc->chargeCase($fxB['biller'], $fx['case'], 'P', 10))->toThrow(CrossTenantReferenceException::class);
});
