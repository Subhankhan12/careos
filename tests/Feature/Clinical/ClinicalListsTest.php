<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Exceptions\AllergyConflictException;
use Modules\Clinical\Models\Allergy;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Medication;
use Modules\Clinical\Models\Problem;
use Modules\Clinical\Models\Vital;
use Modules\Clinical\Services\ClinicalListService;
use Modules\Clinical\Services\EncounterService;
use Modules\Clinical\Services\MedicationService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function d3Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function d3Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function d3Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function d3User(Tenant $tenant, string $role = 'doctor'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    if ($role !== '') {
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => d3Role($role)->id,
        ]);
    }

    return $user;
}

function d3Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function d3Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'List',
        'last_name' => 'Patient',
        'date_of_birth' => '1982-03-04',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function d3Practitioner(Branch $branch, array $overrides = []): StaffProfile
{
    return StaffProfile::query()->create([
        'first_name' => 'Pat',
        'last_name' => 'Practitioner',
        'display_name' => 'Pat Practitioner',
        'profession' => 'doctor',
        'primary_branch_id' => $branch->id,
        ...$overrides,
    ]);
}

function d3Setup(Tenant $tenant, string $role = 'doctor'): array
{
    d3Ctx()->set($tenant);
    $user = d3User($tenant, $role);
    $branch = d3Branch();
    $patient = d3Patient();
    $practitioner = d3Practitioner($branch);
    $encounter = app(EncounterService::class)->open(
        $patient,
        $practitioner,
        $branch,
        null,
        Encounter::TYPE_CONSULTATION,
        $user,
    );

    return [$user, $patient, $practitioner, $encounter];
}

function d3RecordAllergy(Patient $patient, StaffProfile $practitioner, User $user, string $substance, string $status = Allergy::STATUS_ACTIVE): Allergy
{
    return app(ClinicalListService::class)->recordAllergy($patient, $practitioner, $user, [
        'substance' => $substance,
        'reaction' => 'Documented reaction',
        'severity' => Allergy::SEVERITY_UNKNOWN,
        'status' => $status,
    ]);
}

function d3MedicationPayload(array $overrides = []): array
{
    return [
        'name' => 'Penicillin',
        'substance_key' => 'penicillin',
        'dose_text' => 'as documented by clinician',
        'route' => 'oral',
        'frequency_text' => 'as documented',
        'started_on' => '2026-07-09',
        ...$overrides,
    ];
}

function d3AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('recording a medication matching an active allergy is rejected and writes nothing', function () {
    $tenant = d3Tenant('alpha');
    [$user, $patient, $practitioner] = d3Setup($tenant);
    d3RecordAllergy($patient, $practitioner, $user, 'Penicillin');

    expect(fn () => app(MedicationService::class)->record(
        $patient,
        $practitioner,
        $user,
        d3MedicationPayload(['substance_key' => ' Penicillin ']),
    ))->toThrow(AllergyConflictException::class)
        ->and(Medication::query()->count())->toBe(0)
        ->and(d3AuditRows($tenant->id, 'medication.added'))->toHaveCount(0);
});

test('an inactive allergy does not block medication recording', function () {
    $tenant = d3Tenant('alpha');
    [$user, $patient, $practitioner] = d3Setup($tenant);
    d3RecordAllergy($patient, $practitioner, $user, 'Penicillin', Allergy::STATUS_INACTIVE);

    $medication = app(MedicationService::class)->record(
        $patient,
        $practitioner,
        $user,
        d3MedicationPayload(),
    );

    expect($medication->substance_key)->toBe('penicillin')
        ->and(Medication::query()->count())->toBe(1);
});

test('allergy override requires permission and reason and writes an override audit event', function () {
    $tenant = d3Tenant('alpha');
    [$doctor, $patient, $practitioner] = d3Setup($tenant);
    d3RecordAllergy($patient, $practitioner, $doctor, 'Penicillin');
    $nurse = d3User($tenant, 'nurse');

    expect(fn () => app(MedicationService::class)->record(
        $patient,
        $practitioner,
        $doctor,
        d3MedicationPayload(),
        '',
    ))->toThrow(AllergyConflictException::class);

    expect(fn () => app(MedicationService::class)->record(
        $patient,
        $practitioner,
        $nurse,
        d3MedicationPayload(),
        'clinician documented override',
    ))->toThrow(AuthorizationException::class);

    $medication = app(MedicationService::class)->record(
        $patient,
        $practitioner,
        $doctor,
        d3MedicationPayload(),
        'clinician documented override',
    );
    $override = d3AuditRows($tenant->id, 'allergy.override')->first();

    expect($medication->exists)->toBeTrue()
        ->and($override)->not->toBeNull()
        ->and($override->patient_id)->toBe($patient->id)
        ->and($override->reason)->toBe('clinician documented override')
        ->and(json_decode($override->context, true)['override'])->toBeTrue();
});

test('allergy hard stop is exact match only with no fuzzy or class inference', function () {
    $tenant = d3Tenant('alpha');
    [$user, $patient, $practitioner] = d3Setup($tenant);
    d3RecordAllergy($patient, $practitioner, $user, 'Amoxicillin');

    $medication = app(MedicationService::class)->record(
        $patient,
        $practitioner,
        $user,
        d3MedicationPayload([
            'name' => 'Penicillin',
            'substance_key' => 'penicillin',
        ]),
    );

    expect($medication->substance_key)->toBe('penicillin')
        ->and(Medication::query()->count())->toBe(1);
});

test('vitals store raw values with no interpretation flags or derived fields', function () {
    $tenant = d3Tenant('alpha');
    [$user, $patient, $practitioner, $encounter] = d3Setup($tenant);

    $vital = app(ClinicalListService::class)->recordVital(
        $patient,
        $practitioner,
        $user,
        [
            'recorded_at' => '2026-07-09 09:00:00',
            'systolic' => 120,
            'diastolic' => 80,
            'heart_rate' => 72,
            'temperature_c' => '36.8',
            'spo2' => 98,
            'weight_g' => 70000,
            'height_mm' => 1700,
            'extra' => ['source' => 'manual'],
        ],
        $encounter,
    );
    $columns = Schema::getColumnListing('vitals');

    expect($vital->systolic)->toBe(120)
        ->and($vital->temperature_c)->toBe('36.8')
        ->and($vital->extra)->toBe(['source' => 'manual'])
        ->and($columns)->not->toContain('flag')
        ->and($columns)->not->toContain('score')
        ->and($columns)->not->toContain('interpretation')
        ->and($columns)->not->toContain('derived');
});

test('clinical lists are tenant isolated fail closed audited and read logged', function () {
    $alpha = d3Tenant('alpha');
    [$alphaUser, $alphaPatient, $alphaPractitioner, $alphaEncounter] = d3Setup($alpha);
    $lists = app(ClinicalListService::class);
    $lists->recordProblem($alphaPatient, $alphaPractitioner, $alphaUser, ['description' => 'Documented problem'], $alphaEncounter);
    $lists->recordAllergy($alphaPatient, $alphaPractitioner, $alphaUser, ['substance' => 'Latex']);
    $lists->recordVital($alphaPatient, $alphaPractitioner, $alphaUser, ['recorded_at' => now(), 'heart_rate' => 70], $alphaEncounter);
    app(MedicationService::class)->record($alphaPatient, $alphaPractitioner, $alphaUser, d3MedicationPayload(['substance_key' => 'metformin', 'name' => 'Metformin']));

    $beta = d3Tenant('beta');
    [$betaUser, $betaPatient, $betaPractitioner, $betaEncounter] = d3Setup($beta);
    app(ClinicalListService::class)->recordProblem($betaPatient, $betaPractitioner, $betaUser, ['description' => 'Beta problem'], $betaEncounter);

    d3Ctx()->set($alpha);

    expect(Problem::query()->count())->toBe(1)
        ->and(Allergy::query()->count())->toBe(1)
        ->and(Vital::query()->count())->toBe(1)
        ->and(Medication::query()->count())->toBe(1)
        ->and(d3AuditRows($alpha->id, 'problem.added'))->toHaveCount(1)
        ->and(d3AuditRows($alpha->id, 'allergy.added'))->toHaveCount(1)
        ->and(d3AuditRows($alpha->id, 'vital.recorded'))->toHaveCount(1)
        ->and(d3AuditRows($alpha->id, 'medication.added'))->toHaveCount(1);

    $lists->readListsForPatient($alphaPatient);

    expect(d3AuditRows($alpha->id, 'read'))->toHaveCount(4)
        ->and(app(AuditService::class)->verifyChain($alpha->id)['ok'])->toBeTrue();

    d3Ctx()->forget();

    expect(fn () => Problem::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => Allergy::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => Vital::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => Medication::query()->count())->toThrow(TenantContextMissingException::class);
});

test('clinical list writes require clinician write permission', function () {
    $tenant = d3Tenant('alpha');
    [$doctor, $patient, $practitioner, $encounter] = d3Setup($tenant);
    $reception = d3User($tenant, 'reception');
    $lists = app(ClinicalListService::class);

    expect(fn () => $lists->recordProblem(
        $patient,
        $practitioner,
        $reception,
        ['description' => 'No permission'],
        $encounter,
    ))->toThrow(AuthorizationException::class)
        ->and(fn () => $lists->recordAllergy($patient, $practitioner, $reception, ['substance' => 'Latex']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $lists->recordVital($patient, $practitioner, $reception, ['recorded_at' => now()]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(MedicationService::class)->record(
            $patient,
            $practitioner,
            $reception,
            d3MedicationPayload(['substance_key' => 'metformin', 'name' => 'Metformin']),
        ))->toThrow(AuthorizationException::class)
        ->and($doctor->can('note.write'))->toBeTrue();
});
