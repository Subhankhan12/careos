<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccessReport;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * QA-FIX.10a — `P9-C1`: the AR management-report CSV took three patients' identifiers, overdue balances,
 * days overdue and dunning stage out of the system and wrote NO audit row at all. Driven in Phase 9 by
 * snapshotting `audit_events` before and after a download: zero new rows. It was missing from the tenant's
 * ledger and — having no `patient_id` — unreachable by any patient's access log.
 *
 * THE FIXTURE USES THREE PATIENTS ON PURPOSE (D-189). A one-patient fixture would pass whether the export
 * wrote one row per patient or a single row carrying one id, so it would not test the multi-patient
 * resolution at all.
 *
 * TWO SHAPES ARE ASSERTED SEPARATELY, because two different facts are recorded and the product already
 * had a shape for each: ONE `billing.report_exported` row with no patient (the file left the building —
 * the shape `governance.ledger_exported` uses) and ONE `action = 'read'` row per named patient (this
 * patient's data was disclosed — the shape every audited download in the product already uses). The last
 * test drives the consequence that matters: the export REACHES the patient's own access log.
 */

function arxTenant(): Tenant
{
    $tenant = Tenant::query()->create(['name' => 'AR Export Care', 'slug' => 'ar-export', 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

/** @return array{tenant: Tenant, actor: User, branch: Branch, catalog: TariffCatalog} */
function arxFixture(): array
{
    $tenant = arxTenant();
    $actor = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $actor->id, 'role_id' => Role::query()->where('key', 'billing')->firstOrFail()->id]);
    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    $catalog = TariffCatalog::query()->create(['key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1, 'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => []]);

    return compact('tenant', 'actor', 'branch', 'catalog');
}

function arxCode(): string
{
    static $n = 7000;

    return (string) (++$n);
}

/** An overdue, issued, unpaid invoice — enough to put the patient in topOverdueAccounts. */
function arxOverduePatient(array $fx, string $first, string $last, int $priceMinor): Patient
{
    $patient = app(PatientService::class)->create(['first_name' => $first, 'last_name' => $last, 'date_of_birth' => '1980-01-01', 'sex' => 'female']);

    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $fx['catalog']->id, 'code' => arxCode(), 'description' => 'Consultation',
        'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);
    $charge = Charge::query()->create([
        'patient_id' => $patient->id, 'branch_id' => $fx['branch']->id, 'service_date' => '2026-01-10',
        'tariff_catalog_id' => $fx['catalog']->id, 'tariff_item_id' => $item->id, 'code' => $item->code,
        'description' => $item->description, 'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0,
        'quantity' => 1, 'line_total_minor' => $priceMinor, 'status' => Charge::STATUS_VALIDATED, 'created_by' => $fx['actor']->id,
    ]);
    $issuer = app(IssueService::class);
    $issuer->issue(
        $issuer->createDraftFromCharges($patient, [$charge], $fx['actor'], Invoice::PAYER_SELF_PAY, null, Carbon::parse('2026-01-10'), Carbon::parse('2026-01-20')),
        $fx['actor'],
    );

    return $patient;
}

/** @return array{fx: array, patients: list<Patient>} */
function arxThreePatients(): array
{
    $fx = arxFixture();
    $patients = [
        arxOverduePatient($fx, 'Anna', 'Alpha', 31300),
        arxOverduePatient($fx, 'Bruno', 'Beta', 21200),
        arxOverduePatient($fx, 'Clara', 'Gamma', 11100),
    ];

    return ['fx' => $fx, 'patients' => $patients];
}

/** The tenant-ledger row: the file itself leaving. It carries no patient, by design. */
function arxLedgerRows(): Collection
{
    return collect(DB::select("select action, patient_id, context from audit_events where action = 'billing.report_exported'"));
}

/** The disclosure rows: one read per patient the file names, carrying their id. */
function arxDisclosureRows(): Collection
{
    return collect(DB::select("select action, patient_id, resource_id, context from audit_events where action = 'read' and resource_type = 'billing_ar_report'"));
}

it('writes an audit row for the export, where before it wrote none at all', function () {
    ['fx' => $fx] = arxThreePatients();

    // BEFORE THE FIX this count stayed at zero across the request.
    $before = DB::table('audit_events')->count();

    $this->actingAs($fx['actor'])->get(route('billing.report.export', ['period' => 'ytd']))->assertOk();

    expect(DB::table('audit_events')->count())->toBeGreaterThan($before)
        ->and(arxLedgerRows())->not->toBeEmpty()
        ->and(arxDisclosureRows())->not->toBeEmpty();
});

it('records the actor, the window and the row count on the export', function () {
    ['fx' => $fx] = arxThreePatients();

    $this->actingAs($fx['actor'])->get(route('billing.report.export', ['period' => 'ytd']))->assertOk();

    $report = arxLedgerRows()->firstWhere(fn (object $row): bool => $row->patient_id === null);
    $context = json_decode((string) $report->context, true);

    expect($report)->not->toBeNull()
        ->and(DB::table('audit_events')->where('action', 'billing.report_exported')->value('actor_id'))->toBe((string) $fx['actor']->id)
        ->and($context['period'])->toBe('ytd')
        ->and($context['from'])->not->toBeEmpty()
        ->and($context['to'])->not->toBeEmpty()
        ->and($context['row_count'])->toBeGreaterThan(0)
        ->and($context['patients_named'])->toBe(3);
});

it('addresses the export to every patient the file names, not just one', function () {
    ['fx' => $fx, 'patients' => $patients] = arxThreePatients();

    $this->actingAs($fx['actor'])->get(route('billing.report.export', ['period' => 'ytd']))->assertOk();

    // THE MULTI-PATIENT RESOLUTION, ASSERTED PER PATIENT. `PatientAccessReport` reaches a patient's log
    // by `patient_id = ?`, so a single row listing three ids in its context would be invisible to all
    // three. Each named patient must therefore have a row addressed to THEM.
    foreach ($patients as $patient) {
        expect(DB::table('audit_events')
            ->where('action', 'read')
            ->where('resource_type', 'billing_ar_report')
            ->where('patient_id', $patient->id)
            ->count())->toBe(1);
    }
});

it('does not address the export to a patient the file does not name', function () {
    ['fx' => $fx] = arxThreePatients();
    $stranger = app(PatientService::class)->create(['first_name' => 'Dora', 'last_name' => 'Delta', 'date_of_birth' => '1990-02-02', 'sex' => 'female']);

    $this->actingAs($fx['actor'])->get(route('billing.report.export', ['period' => 'ytd']))->assertOk();

    // THE SCOPING CONTROL: a patient with no overdue invoice is not named in the file and gets no row.
    expect(DB::table('audit_events')
        ->where('resource_type', 'billing_ar_report')
        ->where('patient_id', $stranger->id)
        ->exists())->toBeFalse();
});

it('writes one ledger row for the file and one disclosure row per named patient, and no second audit path', function () {
    ['fx' => $fx, 'patients' => $patients] = arxThreePatients();

    $this->actingAs($fx['actor'])->get(route('billing.report.export', ['period' => 'ytd']))->assertOk();

    $ledger = arxLedgerRows();
    $disclosures = arxDisclosureRows();

    expect($ledger)->toHaveCount(1)                       // the file left the building, once
        ->and($ledger->first()->patient_id)->toBeNull()   // and that fact belongs to no one patient
        ->and($disclosures)->toHaveCount(3)               // one per named patient, no more
        ->and($disclosures->pluck('patient_id')->sort()->values()->all())
        ->toBe(collect($patients)->pluck('id')->sort()->values()->all());
});

it('does not change what the export contains — only what it records', function () {
    ['fx' => $fx, 'patients' => $patients] = arxThreePatients();

    $csv = $this->actingAs($fx['actor'])->get(route('billing.report.export', ['period' => 'ytd']))->streamedContent();

    // THE POSITIVE CONTROL (D-174): this part must alter the RECORD, never the FILE. The engine figures,
    // the section keys and the per-patient lines are all still there.
    expect($csv)->toContain('section,metric,value_minor_or_ratio')
        ->and($csv)->toContain('headline,total_ar_minor')
        ->and($csv)->toContain('top_overdue,account_count');

    foreach ($patients as $patient) {
        expect($csv)->toContain('top_overdue:'.$patient->id);
    }
});

it('reaches the access log of every patient the file names', function () {
    ['fx' => $fx, 'patients' => $patients] = arxThreePatients();

    $this->actingAs($fx['actor'])->get(route('billing.report.export', ['period' => 'ytd']))->assertOk();

    /*
     * THE CONSEQUENCE, not merely the row. `P9-C1` is a disclosure the product could not account for in
     * EITHER view: absent from the tenant's ledger, and unreachable by the patient. The tests above cover
     * the ledger; this one covers the patient, through the REAL report class that the access-log screen
     * and the nDSG/GDPR subject-access export both use — so it fails if the recorded shape is anything
     * `PatientAccessReport` cannot see. A bespoke per-patient action would have been exactly that.
     */
    $report = app(PatientAccessReport::class);

    foreach ($patients as $patient) {
        $exports = $report->forPatientNewestFirst($patient)
            ->filter(fn (object $row): bool => $row->resource_type === 'billing_ar_report');

        $this->assertCount(1, $exports, 'the AR export must appear in the access log of '.$patient->first_name);
        $this->assertSame(
            'billing_ar_report_export',
            json_decode((string) $exports->first()->context, true)['surface'],
            'and it must say which surface disclosed them',
        );
    }

    // The figure the screen prints moves with it — the row is counted, not merely stored.
    expect($report->distinctActorCountFor($patients[0]))->toBeGreaterThan(0);
});
