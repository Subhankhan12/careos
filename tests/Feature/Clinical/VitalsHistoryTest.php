<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Clinical\Models\Vital;
use Modules\Clinical\Services\VitalsHistoryService;
use Modules\Clinical\Support\VitalsSeries;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitVital;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource as BookableResource;

uses(RefreshDatabase::class);

function g13Tenant(string $slug): Tenant
{
    return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
}

function g13User(Tenant $tenant, string $role = 'doctor'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

function g13Staff(User $user): StaffProfile
{
    return StaffProfile::query()->create([
        'user_id' => $user->id,
        'first_name' => 'Vitals',
        'last_name' => 'Recorder',
        'display_name' => 'Vitals Recorder',
        'profession' => 'doctor',
    ]);
}

function g13Patient(string $first = 'Vitals'): Patient
{
    return app(PatientService::class)->create([
        'first_name' => $first,
        'last_name' => 'History',
        'date_of_birth' => '1950-05-05',
        'sex' => 'female',
    ]);
}

function g13ClinicVital(Patient $patient, StaffProfile $recorder, array $data): Vital
{
    return Vital::query()->create([
        'patient_id' => $patient->id,
        'recorded_at' => $data['recorded_at'],
        'systolic' => $data['systolic'] ?? null,
        'diastolic' => $data['diastolic'] ?? null,
        'heart_rate' => $data['heart_rate'] ?? null,
        'temperature_c' => $data['temperature_c'] ?? null,
        'spo2' => $data['spo2'] ?? null,
        'weight_g' => $data['weight_g'] ?? null,
        'height_mm' => $data['height_mm'] ?? null,
        'recorded_by' => $recorder->id,
    ]);
}

function g13VisitVital(Patient $patient, array $data): VisitVital
{
    $branch = Branch::query()->firstOrCreate(['code' => 'VH'], ['name' => 'VH Branch']);
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Visit Nurse',
        'branch_id' => $branch->id,
    ]);
    $visit = Visit::query()->create([
        'patient_id' => $patient->id,
        'resource_id' => $resource->id,
        'branch_id' => $branch->id,
        'scheduled_start_at' => $data['recorded_at'],
        'status' => Visit::STATUS_COMPLETED,
        'client_visit_uuid' => (string) Str::ulid(),
    ]);

    return VisitVital::query()->create([
        'visit_id' => $visit->id,
        'patient_id' => $patient->id,
        'recorded_at' => $data['recorded_at'],
        'systolic' => $data['systolic'] ?? null,
        'diastolic' => $data['diastolic'] ?? null,
        'heart_rate' => $data['heart_rate'] ?? null,
        'temperature_c' => $data['temperature_c'] ?? null,
        'spo2' => $data['spo2'] ?? null,
        'weight_g' => $data['weight_g'] ?? null,
        'height_mm' => $data['height_mm'] ?? null,
    ]);
}

function g13AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('the unified series merges clinic and visit readings, time-ordered and source-tagged', function () {
    $tenant = g13Tenant('alpha');
    app(TenantContext::class)->set($tenant);
    $staff = g13Staff(g13User($tenant));
    $patient = g13Patient();

    // Earlier clinic reading, later visit reading.
    g13ClinicVital($patient, $staff, ['recorded_at' => '2026-07-10 09:00:00', 'systolic' => 120, 'diastolic' => 80]);
    g13VisitVital($patient, ['recorded_at' => '2026-07-12 14:00:00', 'systolic' => 128, 'spo2' => 97]);

    $series = app(VitalsHistoryService::class)->forPatient($patient->id)['metrics'];

    // Systolic appears in BOTH stores → two points, most-recent-first, source-tagged.
    expect($series['systolic'])->toHaveCount(2)
        ->and($series['systolic'][0]['value'])->toBe(128)
        ->and($series['systolic'][0]['source'])->toBe(VitalsSeries::SOURCE_VISIT)
        ->and($series['systolic'][0]['recorded_at'])->toBe('2026-07-12 14:00:00')
        ->and($series['systolic'][1]['value'])->toBe(120)
        ->and($series['systolic'][1]['source'])->toBe(VitalsSeries::SOURCE_CLINIC)
        ->and($series['systolic'][1]['recorded_at'])->toBe('2026-07-10 09:00:00')
        // spo2 only exists in the visit store.
        ->and($series['spo2'])->toHaveCount(1)
        ->and($series['spo2'][0]['source'])->toBe(VitalsSeries::SOURCE_VISIT);
});

test('a metric missing from a reading is absent from that metric series, never zero-filled', function () {
    $tenant = g13Tenant('alpha');
    app(TenantContext::class)->set($tenant);
    $staff = g13Staff(g13User($tenant));
    $patient = g13Patient();

    // Clinic reading HAS diastolic; visit reading does NOT.
    g13ClinicVital($patient, $staff, ['recorded_at' => '2026-07-10 09:00:00', 'systolic' => 120, 'diastolic' => 80]);
    g13VisitVital($patient, ['recorded_at' => '2026-07-12 14:00:00', 'systolic' => 128]);

    $series = app(VitalsHistoryService::class)->forPatient($patient->id)['metrics'];

    // Diastolic has ONLY the clinic reading — the visit's missing diastolic is absent, not 0.
    expect($series['diastolic'])->toHaveCount(1)
        ->and($series['diastolic'][0]['value'])->toBe(80)
        ->and($series['diastolic'][0]['source'])->toBe(VitalsSeries::SOURCE_CLINIC)
        // metrics no reading captured stay empty (never zero-filled).
        ->and($series['weight_g'])->toBe([])
        ->and($series['height_mm'])->toBe([]);

    // No zero value ever appears for diastolic across the series.
    expect(collect($series['diastolic'])->pluck('value')->contains(0))->toBeFalse();
});

test('the unified series carries raw values only — no interpretation fields', function () {
    $tenant = g13Tenant('alpha');
    app(TenantContext::class)->set($tenant);
    $staff = g13Staff(g13User($tenant));
    $patient = g13Patient();

    g13ClinicVital($patient, $staff, ['recorded_at' => '2026-07-10 09:00:00', 'systolic' => 200]);
    g13VisitVital($patient, ['recorded_at' => '2026-07-12 14:00:00', 'systolic' => 40]);

    $series = app(VitalsHistoryService::class)->forPatient($patient->id)['metrics'];

    $forbidden = ['flag', 'band', 'range', 'normal', 'abnormal', 'score', 'delta', 'trend', 'min', 'max', 'status', 'severity'];

    foreach ($series as $points) {
        foreach ($points as $point) {
            expect(array_keys($point))->toBe(['recorded_at', 'value', 'source']);
            foreach ($forbidden as $key) {
                expect($point)->not->toHaveKey($key);
            }
        }
    }
});

test('vitals history is tenant-isolated and patient-scoped', function () {
    $alpha = g13Tenant('alpha');
    app(TenantContext::class)->set($alpha);
    $alphaStaff = g13Staff(g13User($alpha));
    $alphaPatient = g13Patient('Alpha');
    $otherAlphaPatient = g13Patient('Other');
    g13ClinicVital($alphaPatient, $alphaStaff, ['recorded_at' => '2026-07-10 09:00:00', 'systolic' => 120]);
    g13VisitVital($alphaPatient, ['recorded_at' => '2026-07-12 14:00:00', 'systolic' => 128]);
    g13ClinicVital($otherAlphaPatient, $alphaStaff, ['recorded_at' => '2026-07-10 09:00:00', 'systolic' => 111]);

    $beta = g13Tenant('beta');
    app(TenantContext::class)->set($beta);
    $betaStaff = g13Staff(g13User($beta));
    $betaPatient = g13Patient('Beta');
    g13ClinicVital($betaPatient, $betaStaff, ['recorded_at' => '2026-07-11 09:00:00', 'systolic' => 199]);

    // Back in tenant alpha: only alphaPatient's own readings appear.
    app(TenantContext::class)->set($alpha);
    $series = app(VitalsHistoryService::class)->forPatient($alphaPatient->id)['metrics'];

    expect($series['systolic'])->toHaveCount(2)
        ->and(collect($series['systolic'])->pluck('value')->all())->toBe([128, 120]);

    // Neither the other patient's (111) nor the other tenant's (199) reading leaks in.
    expect(collect($series['systolic'])->pluck('value')->contains(111))->toBeFalse()
        ->and(collect($series['systolic'])->pluck('value')->contains(199))->toBeFalse();
});

test('the chart returns the unified vitalsHistory prop with no interpretation and read-logs the patient', function () {
    $tenant = g13Tenant('alpha');
    app(TenantContext::class)->set($tenant);
    $user = g13User($tenant, 'doctor');
    $staff = g13Staff($user);
    $patient = g13Patient();

    g13ClinicVital($patient, $staff, ['recorded_at' => '2026-07-10 09:00:00', 'systolic' => 120, 'diastolic' => 80]);
    g13VisitVital($patient, ['recorded_at' => '2026-07-12 14:00:00', 'systolic' => 128]);

    $this->actingAs($user)
        ->get(route('clinical.chart', $patient->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinical/Chart')
            ->where('vitalsHistory.systolic.0.value', 128)
            ->where('vitalsHistory.systolic.0.source', 'visit')
            ->where('vitalsHistory.systolic.1.value', 120)
            ->where('vitalsHistory.systolic.1.source', 'clinic')
            ->missing('vitalsHistory.systolic.0.flag')
            ->missing('vitalsHistory.systolic.0.band')
            ->missing('vitalsHistory.systolic.0.score')
            ->missing('vitalsHistory.systolic.0.delta')
            // existing flat prop is retained.
            ->has('vitals', 1));

    $readRows = g13AuditRows($tenant->id, 'read')
        ->where('resource_type', 'patient')
        ->where('resource_id', $patient->id);

    expect($readRows)->toHaveCount(1);
});
