<?php

use Database\Seeders\DemoDentalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Dental\Models\DentalImage;
use Modules\Dental\Models\DentalImageReading;
use Modules\Dental\Models\DentalProcedureCharge;
use Modules\Dental\Models\Diagnosis;
use Modules\Dental\Models\PerformedProcedure;
use Modules\Dental\Models\PerioExam;
use Modules\Dental\Models\ToothRecord;
use Modules\Dental\Models\TreatmentPlan;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/**
 * Whole-schema row counts, so "a second run added nothing" is a claim about the entire
 * database, not the handful of tables we remembered to name.
 *
 * @return array<string, int>
 */
function dentalDemoRowCounts(): array
{
    $counts = [];

    foreach (DB::select('SHOW TABLES') as $row) {
        $table = array_values((array) $row)[0];

        if (in_array($table, ['migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions'], true)) {
            continue;
        }

        $counts[$table] = (int) DB::table($table)->count();
    }

    return $counts;
}

function dentalDemoTenant(): Tenant
{
    return Tenant::query()->where('slug', DemoDentalSeeder::TENANT_SLUG)->firstOrFail();
}

test('demo dental seeder is idempotent: a second run adds nothing', function () {
    Storage::fake('local'); // imaging writes to the private local disk

    (new DemoDentalSeeder)->run();
    $afterFirst = dentalDemoRowCounts();

    // Guard against a vacuous pass: the first run must actually produce something to duplicate.
    expect($afterFirst['patients'])->toBeGreaterThan(0)
        ->and($afterFirst['tooth_records'])->toBeGreaterThan(0)
        ->and($afterFirst['invoices'])->toBeGreaterThan(0)
        ->and($afterFirst['dental_images'])->toBeGreaterThan(0)
        ->and($afterFirst['audit_events'])->toBeGreaterThan(0);

    (new DemoDentalSeeder)->run();

    expect(dentalDemoRowCounts())->toBe($afterFirst)
        ->and(Tenant::query()->where('slug', DemoDentalSeeder::TENANT_SLUG)->count())->toBe(1);
});

test('demo dental produces the dental surface the demo promises', function () {
    Storage::fake('local');

    (new DemoDentalSeeder)->run();

    $tenant = dentalDemoTenant();
    app(TenantContext::class)->set($tenant);

    expect($tenant->name)->toBe('Zahnarztpraxis Morgenstern')
        ->and($tenant->status)->toBe('active');

    // A staffed practice + a patient directory with a live portal account.
    expect(User::query()->where('tenant_id', $tenant->id)->count())->toBe(4)
        ->and(Patient::query()->count())->toBeGreaterThanOrEqual(4)
        ->and(PortalAccount::query()->where('status', PortalAccount::STATUS_ACTIVE)->count())->toBe(1);

    // Odontogram: charted conditions, and an APPEND-ONLY correction on Anna's 16 occlusal
    // (caries superseded by a restoration) — both rows kept, the current one is the latest.
    $anna = Patient::query()->where('first_name', 'Anna')->firstOrFail();
    $sixteen = ToothRecord::query()->where('patient_id', $anna->id)->where('tooth', '16')->where('surface', 'occlusal')
        ->orderBy('charted_at')->get();
    expect(ToothRecord::query()->count())->toBeGreaterThan(0)
        ->and($sixteen)->toHaveCount(2)
        ->and($sixteen->first()->charted_condition)->toBe('caries')
        ->and($sixteen->last()->charted_condition)->toBe('restoration')
        ->and($sixteen->last()->reason)->not->toBeNull();

    // A performed procedure tied to a real charge (no orphan) + its tooth link.
    expect(PerformedProcedure::query()->count())->toBeGreaterThan(0)
        ->and(PerformedProcedure::query()->whereNull('charge_id')->count())->toBe(0)
        ->and(DentalProcedureCharge::query()->count())->toBeGreaterThan(0);

    // Two treatment plans: one accepted (the portal-visible one) + one proposed. Neither
    // posts a charge — estimating is not billing.
    expect(TreatmentPlan::query()->where('status', TreatmentPlan::STATUS_ACCEPTED)->count())->toBe(1)
        ->and(TreatmentPlan::query()->where('status', TreatmentPlan::STATUS_PROPOSED)->count())->toBe(1);

    // Two perio exams (so the per-site trend view is demonstrable) + dentist-authored
    // diagnoses + an uploaded image with a reading.
    expect(PerioExam::query()->count())->toBe(2)
        ->and(Diagnosis::query()->count())->toBe(2)
        ->and(DentalImage::query()->count())->toBe(1)
        ->and(DentalImageReading::query()->count())->toBe(1);

    // FENCE: the dental clinical tables carry raw facts only — never an interpretation column.
    foreach (['tooth_records', 'perio_measurements', 'diagnoses'] as $table) {
        $columns = implode(',', array_keys((array) DB::selectOne("SELECT * FROM {$table} LIMIT 1")));
        foreach (['severity', 'score', 'grade', 'stage', 'flag', 'abnormal', 'risk'] as $forbidden) {
            expect($columns)->not->toContain($forbidden);
        }
    }

    // The demo tenant is the only tenant this seeder created, and its audit chain holds.
    expect(Tenant::query()->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('demo dental: the demo period reconciles to the unit', function () {
    Storage::fake('local');

    (new DemoDentalSeeder)->run();

    $tenant = dentalDemoTenant();
    app(TenantContext::class)->set($tenant);
    $actor = User::query()->where('tenant_id', $tenant->id)->where('email', DemoDentalSeeder::BILLING_EMAIL)->firstOrFail();

    // Three gapless invoices.
    $invoices = Invoice::query()->where('series', Invoice::SERIES_INVOICE)->whereNotNull('number')->get();
    $numbers = $invoices->pluck('number')->map(fn (string $n): int => (int) $n)->sort()->values()->all();
    expect($invoices)->toHaveCount(3)
        ->and($numbers)->toBe([1, 2, 3]);

    // Dental money is real invoiced money, tied to the dental procedure catalog (D-… codes).
    expect(Charge::query()->where('code', 'like', 'D-%')->where('status', Charge::STATUS_INVOICED)->count())->toBeGreaterThan(0);

    // The live performed-procedure charge stays a DRAFT (unbilled) — invisible to the
    // reconciliation, exactly like the demo clinic's dunning-fee draft.
    expect(Charge::query()->where('code', 'D-EXTRACT')->firstOrFail()->status)->toBe(Charge::STATUS_DRAFT);

    // ------------------------------------------------------------------
    // The point of the exercise: the dental billing month is internally consistent.
    // Every invariant ok === true AND delta_minor === 0. Exactly zero.
    // ------------------------------------------------------------------
    $run = app(ReconciliationEngine::class)->run($tenant, DemoDentalSeeder::period(), $actor);

    expect($run->passed)->toBeTrue()
        ->and($run->report['invariants'])->toHaveCount(6);

    foreach ($run->report['invariants'] as $invariant) {
        expect($invariant['ok'] === true)->toBeTrue()
            ->and($invariant['delta_minor'] === 0)->toBeTrue()
            ->and($invariant['rows'])->toBe([]);
    }
});
