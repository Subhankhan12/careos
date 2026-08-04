<?php

use Database\Seeders\DemoHospitalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\ED\Models\EdVisit;
use Modules\Hospital\Models\Stay;
use Modules\Lab\Models\Specimen;
use Modules\Patients\Models\Patient;
use Modules\Pharmacy\Models\MedicationAdministration;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Radiology\Models\ImagingStudy;
use Modules\Surgery\Models\SurgicalCase;

uses(RefreshDatabase::class);

/**
 * Whole-schema row counts, so "a second run added nothing" is a claim about the entire
 * database, not the handful of tables we remembered to name.
 *
 * @return array<string, int>
 */
function hospitalDemoRowCounts(): array
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

function hospitalDemoTenant(): Tenant
{
    return Tenant::query()->where('slug', DemoHospitalSeeder::TENANT_SLUG)->firstOrFail();
}

test('demo hospital seeder is idempotent: a second run adds nothing', function () {
    Storage::fake('local');

    (new DemoHospitalSeeder)->run();
    $afterFirst = hospitalDemoRowCounts();

    // Guard against a vacuous pass: the first run must actually produce something to duplicate.
    expect($afterFirst['patients'])->toBeGreaterThan(0)
        ->and($afterFirst['stays'])->toBeGreaterThan(0)
        ->and($afterFirst['ed_visits'])->toBeGreaterThan(0)
        ->and($afterFirst['invoices'])->toBeGreaterThan(0)
        ->and($afterFirst['audit_events'])->toBeGreaterThan(0);

    (new DemoHospitalSeeder)->run();

    expect(hospitalDemoRowCounts())->toBe($afterFirst)
        ->and(Tenant::query()->where('slug', DemoHospitalSeeder::TENANT_SLUG)->count())->toBe(1);
});

test('demo hospital produces the six-vertical surface the demo promises', function () {
    Storage::fake('local');

    (new DemoHospitalSeeder)->run();

    $tenant = hospitalDemoTenant();
    app(TenantContext::class)->set($tenant);

    expect($tenant->name)->toBe('Klinik Bergblick')
        ->and($tenant->status)->toBe('active');

    // A fully-staffed hospital: a user per hospital role (all can log in via the fixed 2FA secret).
    expect(User::query()->where('tenant_id', $tenant->id)->count())->toBe(20)
        ->and(Patient::query()->count())->toBe(8);

    // Inpatient: three stays — two discharged (invoiced) + one still admitted (live occupancy).
    expect(Stay::query()->count())->toBe(3)
        ->and(Stay::query()->where('status', Stay::STATUS_ADMITTED)->count())->toBe(1)
        ->and(Stay::query()->where('status', Stay::STATUS_DISCHARGED)->count())->toBe(2);

    // ED: visits across the flow — one admitted (the composite), one discharged, two live mid-flow.
    expect(EdVisit::query()->count())->toBe(4)
        ->and(EdVisit::query()->where('status', EdVisit::STATUS_DISPOSITIONED)->count())->toBe(2)
        ->and(EdVisit::query()->where('status', EdVisit::STATUS_ARRIVED)->count())->toBe(1);

    // Surgery, lab, radiology all produced records (incl. pending live states).
    expect(SurgicalCase::query()->count())->toBe(1)
        ->and(Specimen::query()->count())->toBe(4)
        ->and(Specimen::query()->where('status', Specimen::STATUS_RESULTED)->count())->toBeGreaterThan(0)
        ->and(Specimen::query()->where('status', Specimen::STATUS_COLLECTED)->count())->toBe(1) // live pending
        ->and(ImagingStudy::query()->count())->toBe(3)
        ->and(ImagingStudy::query()->where('status', ImagingStudy::STATUS_REPORTED)->count())->toBeGreaterThan(0)
        ->and(ImagingStudy::query()->where('status', ImagingStudy::STATUS_ACQUIRED)->count())->toBe(1); // live pending

    // eMAR: the three factual outcomes (given / held / refused) — no "late/missed" grade exists.
    expect(MedicationAdministration::query()->whereIn('outcome', [
        MedicationAdministration::OUTCOME_GIVEN,
        MedicationAdministration::OUTCOME_HELD,
        MedicationAdministration::OUTCOME_REFUSED,
    ])->count())->toBe(3);

    // FENCE: the hospital clinical tables carry raw facts only — never an interpretation column.
    foreach (['ed_triages', 'medication_administrations', 'order_results', 'imaging_studies'] as $table) {
        $columns = implode(',', array_keys((array) DB::selectOne("SELECT * FROM {$table} LIMIT 1")));
        foreach (['severity', 'score', 'grade', 'stage', 'flag', 'abnormal', 'risk'] as $forbidden) {
            expect($columns)->not->toContain($forbidden);
        }
    }

    // The demo tenant is the only tenant this seeder created, and its audit chain holds.
    expect(Tenant::query()->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('demo hospital: the demo period reconciles to the unit (incl. the composite episode)', function () {
    Storage::fake('local');

    (new DemoHospitalSeeder)->run();

    $tenant = hospitalDemoTenant();
    app(TenantContext::class)->set($tenant);
    $actor = User::query()->where('tenant_id', $tenant->id)->where('email', DemoHospitalSeeder::BILLING_EMAIL)->firstOrFail();

    // Five gapless invoices.
    $invoices = Invoice::query()->where('series', Invoice::SERIES_INVOICE)->whereNotNull('number')->get();
    $numbers = $invoices->pluck('number')->map(fn (string $n): int => (int) $n)->sort()->values()->all();
    expect($invoices)->toHaveCount(5)
        ->and($numbers)->toBe([1, 2, 3, 4, 5]);

    // THE COMPOSITE SHOWPIECE: one patient's whole episode — bed-days + ED + meds + surgery + lab
    // + imaging — bills onto ONE invoice.
    $composite = Patient::query()->where('first_name', 'Karin')->firstOrFail();
    $compositeInvoice = Invoice::query()->where('patient_id', $composite->id)->firstOrFail();
    $codes = Charge::query()->where('invoice_id', $compositeInvoice->id)->pluck('code')->unique();
    foreach (['BED-DAY-GENERAL', 'ED-ATTENDANCE', 'MED-PARA-500', 'SURG-APPEND', 'THEATRE-TIME', 'LAB-CBC', 'RAD-CXR'] as $expected) {
        expect($codes)->toContain($expected);
    }

    // The still-admitted patient's bed-days stay DRAFT (unbilled) — invisible to reconciliation,
    // exactly like the dental demo's unbilled perform charge. (That patient was transferred to the
    // ICU, so the draft accrual is BED-DAY-ICU — any unbilled bed-day proves the live-stay case.)
    expect(Charge::query()->where('code', 'like', 'BED-DAY-%')->where('status', Charge::STATUS_DRAFT)->count())->toBeGreaterThan(0);

    // ------------------------------------------------------------------
    // The point of the exercise: the hospital billing period is internally consistent.
    // Every invariant ok === true AND delta_minor === 0. Exactly zero.
    // ------------------------------------------------------------------
    $run = app(ReconciliationEngine::class)->run($tenant, DemoHospitalSeeder::period(), $actor);

    expect($run->passed)->toBeTrue()
        ->and($run->report['invariants'])->toHaveCount(6);

    foreach ($run->report['invariants'] as $invariant) {
        expect($invariant['ok'] === true)->toBeTrue()
            ->and($invariant['delta_minor'] === 0)->toBeTrue()
            ->and($invariant['rows'])->toBe([]);
    }
});
