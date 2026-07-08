<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Models\PatientCoverage;
use Modules\Patients\Models\PatientIdentifier;
use Modules\Patients\Services\PatientAccessReport;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function patientTenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function patientCtx(): TenantContext
{
    return app(TenantContext::class);
}

function patientService(): PatientService
{
    return app(PatientService::class);
}

function basePatient(array $overrides = []): array
{
    return [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'date_of_birth' => '1980-05-10',
        'sex' => 'female',
        ...$overrides,
    ];
}

function createPatient(array $overrides = []): Patient
{
    return patientService()->create(basePatient($overrides));
}

function patientAuditRows(string $tenantId, string $patientId): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? AND patient_id = ? ORDER BY occurred_at ASC',
        [$tenantId, 'read', $patientId],
    ));
}

test('patients and related tables are tenant isolated and fail closed', function () {
    $a = patientTenant('alpha');
    $b = patientTenant('beta');

    patientCtx()->set($a);
    $patientA = patientService()->create(
        basePatient(['last_name' => 'Alpha']),
        [['type' => 'email', 'value' => 'alpha@example.test', 'is_primary' => true]],
        [['system' => 'passport', 'value' => 'A-123']],
        [['payer_name' => 'Self', 'member_id' => 'A-SELF', 'coverage_type' => 'self_pay', 'priority' => 1]],
    );

    patientCtx()->set($b);
    $patientB = patientService()->create(
        basePatient(['last_name' => 'Beta']),
        [['type' => 'phone', 'value' => '+4100000000', 'is_primary' => true]],
        [['system' => 'passport', 'value' => 'B-123']],
        [['payer_name' => 'Private', 'member_id' => 'B-PRIV', 'coverage_type' => 'private_insurance', 'priority' => 1]],
    );
    $contactB = $patientB->contacts->first();

    patientCtx()->set($a);

    expect(Patient::all())->toHaveCount(1)
        ->and(Patient::first()->is($patientA))->toBeTrue()
        ->and(Patient::find($patientB->id))->toBeNull()
        ->and(PatientContact::find($contactB->id))->toBeNull()
        ->and(PatientContact::count())->toBe(1)
        ->and(PatientIdentifier::count())->toBe(1)
        ->and(PatientCoverage::count())->toBe(1);

    patientCtx()->forget();

    expect(fn () => Patient::count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => PatientContact::count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => PatientIdentifier::count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => PatientCoverage::count())->toThrow(TenantContextMissingException::class);
});

test('MRNs are unique per tenant and the generator skips existing collisions', function () {
    $a = patientTenant('alpha');
    $b = patientTenant('beta');

    patientCtx()->set($a);
    $one = createPatient();
    $two = createPatient(['first_name' => 'Janet']);
    $reserved = createPatient(['first_name' => 'Jo', 'mrn' => 'MRN-000003']);
    $reserved->delete();
    $three = createPatient(['first_name' => 'Jules']);

    patientCtx()->set($b);
    $tenantBFirst = createPatient();

    expect($one->mrn)->toBe('MRN-000001')
        ->and($two->mrn)->toBe('MRN-000002')
        ->and($three->mrn)->toBe('MRN-000004')
        ->and($tenantBFirst->mrn)->toBe('MRN-000001');
});

test('PatientService creates contacts identifiers and coverages in one transaction', function () {
    $tenant = patientTenant('alpha');
    patientCtx()->set($tenant);

    $patient = patientService()->create(
        basePatient(),
        [
            ['type' => 'email', 'value' => 'jane@example.test', 'is_primary' => true],
            ['type' => 'address', 'line1' => 'Main Street 1', 'city' => 'Zurich', 'postal' => '8000', 'country' => 'CH'],
        ],
        [['system' => 'insurance_member', 'value' => 'MEM-1']],
        [['payer_name' => 'Self', 'member_id' => 'SELF-1', 'coverage_type' => 'self_pay', 'priority' => 1]],
    );

    expect($patient->contacts)->toHaveCount(2)
        ->and($patient->identifiers)->toHaveCount(1)
        ->and($patient->coverages)->toHaveCount(1)
        ->and($patient->contacts->pluck('tenant_id')->unique()->all())->toBe([$tenant->id]);

    expect(fn () => patientService()->create(
        basePatient(['first_name' => 'Rollback']),
        [],
        [],
        [['member_id' => 'BROKEN', 'coverage_type' => 'self_pay', 'priority' => 1]],
    ))->toThrow(QueryException::class);

    expect(Patient::where('first_name', 'Rollback')->count())->toBe(0);
});

test('identifiers are optional attributes and not a unique dedupe key', function () {
    $tenant = patientTenant('alpha');
    patientCtx()->set($tenant);

    $first = patientService()->create(
        basePatient(['first_name' => 'Ada']),
        [],
        [['system' => 'national_id', 'value' => 'SHARED-ID']],
    );
    $second = patientService()->create(
        basePatient(['first_name' => 'Grace']),
        [],
        [['system' => 'national_id', 'value' => 'SHARED-ID']],
    );

    expect($first->id)->not->toBe($second->id)
        ->and(PatientIdentifier::where('system', 'national_id')->where('value', 'SHARED-ID')->count())->toBe(2);
});

test('reading a patient writes a patient-scoped read audit event and keeps the chain valid', function () {
    $tenant = patientTenant('alpha');
    patientCtx()->set($tenant);
    $patient = createPatient();

    $patient->auditRead(['surface' => 'test']);

    $rows = patientAuditRows($tenant->id, $patient->id);
    $report = app(PatientAccessReport::class)->forPatient($patient);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->resource_type)->toBe('patient')
        ->and($rows[0]->resource_id)->toBe($patient->id)
        ->and($rows[0]->patient_id)->toBe($patient->id)
        ->and($report)->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('soft deleted patients are excluded by default', function () {
    $tenant = patientTenant('alpha');
    patientCtx()->set($tenant);
    $patient = createPatient();

    $patient->delete();

    expect(Patient::count())->toBe(0)
        ->and(Patient::withTrashed()->count())->toBe(1)
        ->and(Patient::withTrashed()->first()->deleted_at)->not->toBeNull();
});
