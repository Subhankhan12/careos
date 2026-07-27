<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ChargeCaptureService;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\BedDayAccrual;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\Ward;
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

uses(RefreshDatabase::class);

/*
 * HOSPITAL.G6 — bed-to-billing. An inpatient stay accrues bed-day + service charges through the
 * EXISTING billing engine, and discharge assembles them into an invoice that RECONCILES-TO-THE-UNIT.
 * The gate is STRICTLY ORCHESTRATION: the engine prices everything (a bed-day is a tenant-authored
 * TariffItem; accrual is ChargeCaptureService::captureManual; the discharge invoice is the existing
 * validate -> createDraftFromCharges -> issue flow). These tests assert BEHAVIOUR (charges captured
 * by the engine, an idempotent sweep, δ=0 reconciliation, snapshotted fees, no money math in Hospital).
 */

// A fixed month so the reconciliation period ties out deterministically (now() is frozen in beforeEach).
const BB_PERIOD = '2026-06';

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-15 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function bbTenant(string $slug = 'hosp'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Hospital', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

function bbUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

function bbPatient(string $first = 'Ivy'): Patient
{
    return app(PatientService::class)->create(['first_name' => $first, 'last_name' => 'Inpatient', 'date_of_birth' => '1975-03-03', 'sex' => 'female']);
}

/** @return array{tenant: Tenant, branch: Branch, admin: User, ward: Ward, bed: Bed, clinician: StaffProfile, patient: Patient} */
function bbFixture(string $slug = 'hosp'): array
{
    $tenant = bbTenant($slug);
    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    // org_admin holds billing.manage AND admission.manage AND ward/bed.manage — the accrual command
    // resolves exactly this role as the automated billing actor, so it doubles as the sweep's actor.
    $admin = bbUser($tenant, 'org_admin');
    $ward = app(WardService::class)->create($admin, $branch->id, 'Ward 1', 'W1');
    $bed = app(BedService::class)->create($admin, $ward, '1', Bed::TYPE_GENERAL);
    $clinician = StaffProfile::query()->create([
        'first_name' => 'Hana', 'last_name' => 'Hospitalist', 'display_name' => 'Dr Hana Hospitalist',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id,
    ]);
    $patient = bbPatient();

    return compact('tenant', 'branch', 'admin', 'ward', 'bed', 'clinician', 'patient');
}

test('the per-diem bed-day tariff is tenant-authored: generic codes, integer minor units, zero VAT, no licensed code set', function () {
    $fx = bbFixture();

    $created = app(BedBillingService::class)->seedStarter($fx['admin']);
    expect($created)->toBe(3);

    // The catalog is the tenant's own (key 'hospital'), not a licensed/national code set.
    $catalog = TariffCatalog::query()->where('key', BedBillingService::CATALOG_KEY)->firstOrFail();
    $codes = TariffItem::query()->where('tariff_catalog_id', $catalog->id)->pluck('code')->all();
    expect($codes)->toHaveCount(3)
        ->toContain('BED-DAY-GENERAL')->toContain('BED-DAY-ICU')->toContain('BED-DAY-ISOLATION');

    $general = TariffItem::query()->where('tariff_catalog_id', $catalog->id)->where('code', 'BED-DAY-GENERAL')->firstOrFail();
    expect($general->unit_price_minor)->toBe(50000)       // integer minor units — a placeholder rate the tenant edits
        ->and($general->vat_rate_bp)->toBe(0)
        ->and($general->unit)->toBe('bed-day')
        ->and($general->requires_service_documentation)->toBeFalse()
        ->and($general->active)->toBeTrue();

    // Idempotent by code: re-seeding creates nothing new.
    expect(app(BedBillingService::class)->seedStarter($fx['admin']))->toBe(0);
});

test('accrual captures one bed-day charge per occupied day through the existing engine (engine prices + snapshots)', function () {
    $fx = bbFixture();
    app(BedBillingService::class)->seedStarter($fx['admin']);

    $stay = app(AdmissionService::class)->admit($fx['admin'], $fx['patient'], $fx['bed'], $fx['clinician'], Stay::TYPE_ELECTIVE);
    $stay->forceFill(['admitted_at' => Carbon::parse('2026-06-10 08:00:00')])->save(); // 10th..15th inclusive = 6 days

    $accrued = app(BedBillingService::class)->accrueBedDays($fx['admin'], $stay->fresh());

    expect($accrued)->toBe(6)
        ->and(BedDayAccrual::query()->where('stay_id', $stay->id)->count())->toBe(6)
        ->and(Charge::query()->where('patient_id', $fx['patient']->id)->where('code', 'BED-DAY-GENERAL')->count())->toBe(6);

    // The engine resolved + snapshotted the fee and computed the line total (Hospital did no math).
    $charge = Charge::query()->where('code', 'BED-DAY-GENERAL')->firstOrFail();
    expect($charge->quantity)->toBe(1)
        ->and($charge->unit_price_minor)->toBe(50000)
        ->and($charge->line_total_minor)->toBe(50000)      // 1 × 50000, computed BY THE ENGINE
        ->and($charge->status)->toBe(Charge::STATUS_DRAFT); // not yet validated/invoiced
});

test('hospital:accrue-bed-days is idempotent: a second run never double-charges', function () {
    $fx = bbFixture(); // admin is org_admin — the command resolves it as the billing actor
    app(BedBillingService::class)->seedStarter($fx['admin']);

    $stay = app(AdmissionService::class)->admit($fx['admin'], $fx['patient'], $fx['bed'], $fx['clinician'], Stay::TYPE_EMERGENCY);
    $stay->forceFill(['admitted_at' => Carbon::parse('2026-06-13 08:00:00')])->save(); // 13th..15th = 3 days

    expect(Artisan::call('hospital:accrue-bed-days'))->toBe(0);
    $afterFirst = Charge::query()->where('code', 'BED-DAY-GENERAL')->count();

    expect(Artisan::call('hospital:accrue-bed-days'))->toBe(0);
    $afterSecond = Charge::query()->where('code', 'BED-DAY-GENERAL')->count();

    expect($afterFirst)->toBe(3)
        ->and($afterSecond)->toBe(3) // running twice ≠ double-charge
        ->and(BedDayAccrual::query()->where('stay_id', $stay->id)->count())->toBe(3);
});

test('a discharged stay assembles bed-day + service charges into a gapless invoice via the existing flow that reconciles-to-the-unit (δ = 0)', function () {
    Storage::fake('local');
    $fx = bbFixture();
    app(BedBillingService::class)->seedStarter($fx['admin']);

    // A service tariff item on the tenant's catalog — proves services ride alongside bed-days.
    $catalog = app(BedBillingService::class)->catalog();
    TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id, 'code' => 'WARD-CONSULT', 'description' => 'Ward consult',
        'unit_price_minor' => 12000, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);

    $stay = app(AdmissionService::class)->admit($fx['admin'], $fx['patient'], $fx['bed'], $fx['clinician'], Stay::TYPE_ELECTIVE);
    $stay->forceFill(['admitted_at' => Carbon::parse('2026-06-10 08:00:00')])->save();

    // A mid-stay service charge through the existing engine.
    app(ChargeCaptureService::class)->captureManual($fx['patient'], $fx['branch'], '2026-06-12', 'WARD-CONSULT', 1, $fx['admin']);

    // Discharge on the 15th, then invoice the whole stay (accrues the final bed-days first).
    app(AdmissionService::class)->discharge($fx['admin'], $stay->fresh(), Stay::DISPOSITION_HOME, 'stable');
    $invoice = app(BedBillingService::class)->invoiceStay($fx['admin'], $stay->fresh());

    // 6 bed-days (10th..15th) × 50000 + 1 consult × 12000 = 312000, priced entirely by the engine.
    expect($invoice->status)->toBe(Invoice::STATUS_ISSUED)
        ->and($invoice->series)->toBe(Invoice::SERIES_INVOICE)
        ->and($invoice->number)->not->toBeNull()               // gapless number from the existing issuer
        ->and($invoice->total_minor)->toBe(6 * 50000 + 12000);

    // Every stay charge is now INVOICED on exactly this invoice; none left dangling.
    expect(Charge::query()->where('patient_id', $fx['patient']->id)->where('status', Charge::STATUS_INVOICED)->where('invoice_id', $invoice->id)->count())->toBe(7)
        ->and(Charge::query()->where('patient_id', $fx['patient']->id)->whereNull('invoice_id')->count())->toBe(0);

    // THE KEY PROOF: the existing ReconciliationEngine ties the period to the unit — every invariant
    // passes and I4 (N charges → 1 invoice) is exact: delta_minor === 0.
    $report = app(ReconciliationEngine::class)->check(BB_PERIOD);
    $i4 = collect($report['invariants'])->firstWhere('invariant', 'I4');
    expect($report['passed'])->toBeTrue()
        ->and($i4['ok'])->toBeTrue()
        ->and($i4['delta_minor'])->toBe(0);
});

test('the per-diem fee is snapshotted at capture: editing the tariff later never changes a past bed-day charge', function () {
    $fx = bbFixture();
    app(BedBillingService::class)->seedStarter($fx['admin']);

    $stay = app(AdmissionService::class)->admit($fx['admin'], $fx['patient'], $fx['bed'], $fx['clinician'], Stay::TYPE_ELECTIVE);
    $stay->forceFill(['admitted_at' => Carbon::parse('2026-06-14 08:00:00')])->save();
    app(BedBillingService::class)->accrueBedDays($fx['admin'], $stay->fresh());

    $charge = Charge::query()->where('code', 'BED-DAY-GENERAL')->firstOrFail();
    expect($charge->unit_price_minor)->toBe(50000);

    // The tenant edits the per-diem RATE upward — a future concern, not a retroactive one.
    TariffItem::query()->where('code', 'BED-DAY-GENERAL')->firstOrFail()->forceFill(['unit_price_minor' => 99999])->save();

    expect($charge->fresh()->unit_price_minor)->toBe(50000)   // the snapshot stands
        ->and($charge->fresh()->line_total_minor)->toBe(50000);
});

test('FENCE: the Hospital module computes no billing money — pricing/VAT/line-total math lives only in the engine', function () {
    // A per-diem is a RATE the tenant authors (unit_price_minor / vat_rate_bp = 0 keys are allowed);
    // what must NEVER appear here is COMPUTED money: line/VAT/subtotal totals or VAT rounding.
    $forbidden = ['line_total_minor', 'vat_total_minor', 'subtotal_minor', 'vatMinor', 'intdiv('];

    $files = collect(File::allFiles(base_path('Modules/Hospital/src')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php');

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = File::get($file->getPathname());
        foreach ($forbidden as $needle) {
            expect(str_contains($contents, $needle))
                ->toBeFalse("Hospital must not compute billing money ({$needle}) — found in {$file->getRelativePathname()}");
        }
    }
});

test('bed-billing is gated on billing.manage and is tenant-scoped (server authoritative, fail closed)', function () {
    $fxA = bbFixture('alpha');
    app(BedBillingService::class)->seedStarter($fxA['admin']);
    $stayA = app(AdmissionService::class)->admit($fxA['admin'], $fxA['patient'], $fxA['bed'], $fxA['clinician'], Stay::TYPE_ELECTIVE);

    // A ward nurse (no billing.manage) can neither seed per-diems nor invoice a stay.
    $nurse = bbUser($fxA['tenant'], 'ward_nurse');
    expect(fn () => app(BedBillingService::class)->seedStarter($nurse))->toThrow(AuthorizationException::class);
    expect(fn () => app(BedBillingService::class)->invoiceStay($nurse, $stayA))->toThrow(AuthorizationException::class);

    // Cross-tenant: tenant B's admin cannot invoice tenant A's stay — fail closed.
    $fxB = bbFixture('beta');
    app(TenantContext::class)->set($fxB['tenant']);
    expect(fn () => app(BedBillingService::class)->invoiceStay($fxB['admin'], $stayA))
        ->toThrow(CrossTenantReferenceException::class);
});
