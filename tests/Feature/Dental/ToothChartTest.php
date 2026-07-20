<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\ToothRecord;
use Modules\Dental\Services\ToothChartService;
use Modules\Dental\Support\ToothNotation;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * DENTAL.G1 — the tooth/odontogram data model (foundation). These tests prove: charting
 * records a fact (tenant + patient scoped, audited, read-logged); a state CHANGE
 * preserves prior state (append-only history at model + DB-trigger level); FDI notation
 * (permanent + primary) is valid; the schema + output carry NO interpretation field
 * (the electric-fence proof); tenant isolation; and RBAC (only dental.chart holders can
 * chart). Record-not-judge throughout.
 */

function dCtx(): TenantContext
{
    return app(TenantContext::class);
}

function dSvc(): ToothChartService
{
    return app(ToothChartService::class);
}

function dUser(Tenant $tenant, string $role): User
{
    dCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

function dPatient(string $first = 'Tom'): Patient
{
    return app(PatientService::class)->create(['first_name' => $first, 'last_name' => 'Tooth', 'date_of_birth' => '1990-03-03', 'sex' => 'male']);
}

/**
 * @return array{tenant: Tenant, doctor: User, patient: Patient}
 */
function dFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Dental', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    dCtx()->set($tenant);
    $doctor = dUser($tenant, 'doctor'); // the treating clinician / general dentist — holds dental.chart
    $patient = dPatient();

    return compact('tenant', 'doctor', 'patient');
}

/**
 * Recursively assert no interpretation/judgment key leaked into charting output.
 *
 * @param  array<mixed>  $data
 */
function dAssertNoJudgment(array $data): void
{
    $forbidden = ['severity', 'score', 'risk', 'grade', 'abnormal', 'flag', 'priority', 'recommendation', 'rating', 'interpretation', 'diagnosis', 'verdict', 'alert', 'normal'];
    foreach ($data as $key => $value) {
        expect(in_array((string) $key, $forbidden, true))->toBeFalse("interpretation key '{$key}' leaked into dental charting output");
        if (is_array($value)) {
            dAssertNoJudgment($value);
        }
    }
}

test('charting records a fact — tenant + patient scoped, audited, and read-logged', function () {
    $fx = dFixture();
    $record = dSvc()->chart($fx['doctor'], $fx['patient'], '11', 'occlusal', 'caries', 'distal pit');

    expect($record->tenant_id)->toBe($fx['tenant']->id)
        ->and($record->patient_id)->toBe($fx['patient']->id)
        ->and($record->tooth)->toBe('11')
        ->and($record->surface)->toBe('occlusal')
        ->and($record->charted_condition)->toBe('caries')
        ->and($record->charted_by)->toBe((int) $fx['doctor']->id);

    // Charting is audited patient-scoped.
    expect(AuditEvent::query()->where('tenant_id', $fx['tenant']->id)->where('action', 'dental.tooth_charted')->where('patient_id', $fx['patient']->id)->exists())->toBeTrue();

    // Reading the odontogram writes a patient-scoped read row (clinical data).
    $chart = dSvc()->currentChart($fx['doctor'], $fx['patient']);
    expect($chart)->toHaveCount(1);
    expect(AuditEvent::query()->where('tenant_id', $fx['tenant']->id)->where('action', 'read')->where('resource_type', 'tooth_records')->where('patient_id', $fx['patient']->id)->exists())->toBeTrue();
});

test('a state change preserves the prior state — append-only history at model + DB-trigger level', function () {
    $fx = dFixture();
    $svc = dSvc();

    $svc->chart($fx['doctor'], $fx['patient'], '11', null, 'present');
    $svc->chart($fx['doctor'], $fx['patient'], '11', 'occlusal', 'caries');
    $svc->chart($fx['doctor'], $fx['patient'], '11', 'occlusal', 'restoration', 'amalgam', 'correction: filling placed');

    // Full history is preserved (all three records for tooth 11, oldest first).
    $history = $svc->history($fx['doctor'], $fx['patient'], '11');
    expect($history)->toHaveCount(3);

    // The prior 'caries' record is NOT destroyed by the later 'restoration'.
    expect(ToothRecord::query()->where('patient_id', $fx['patient']->id)->where('charted_condition', 'caries')->exists())->toBeTrue();

    // Current odontogram = latest per (tooth, surface): occlusal is now 'restoration',
    // the whole-tooth record is still 'present'.
    $current = $svc->currentChart($fx['doctor'], $fx['patient'])->keyBy(fn (ToothRecord $r): string => $r->tooth.'|'.($r->surface ?? ''));
    expect($current->get('11|occlusal')->charted_condition)->toBe('restoration')
        ->and($current->get('11|')->charted_condition)->toBe('present');

    // Append-only: an Eloquent edit is blocked by the model guard...
    $first = ToothRecord::query()->orderBy('charted_at')->firstOrFail();
    expect(fn () => $first->update(['charted_condition' => 'sound']))->toThrow(DentalException::class);

    // ...and a raw DB UPDATE/DELETE is blocked by the DB trigger (defence in depth).
    expect(fn () => DB::table('tooth_records')->where('id', $first->id)->update(['charted_condition' => 'sound']))->toThrow(QueryException::class);
    expect(fn () => DB::table('tooth_records')->where('id', $first->id)->delete())->toThrow(QueryException::class);
});

test('FDI notation is valid for permanent and primary, and invalid ids/conditions are rejected', function () {
    expect(ToothNotation::permanent())->toHaveCount(32)->toContain('11', '18', '28', '48')
        ->and(ToothNotation::primary())->toHaveCount(20)->toContain('51', '55', '85')
        ->and(ToothNotation::dentitionOf('11'))->toBe('permanent')
        ->and(ToothNotation::dentitionOf('54'))->toBe('primary')
        ->and(ToothNotation::dentitionOf('19'))->toBeNull()  // tooth 9 does not exist
        ->and(ToothNotation::isValid('99'))->toBeFalse();

    $fx = dFixture();
    $svc = dSvc();

    // A primary tooth is chartable (family dentist sees children / mixed dentition).
    expect($svc->chart($fx['doctor'], $fx['patient'], '54', 'occlusal', 'caries')->tooth)->toBe('54');

    // Invalid FDI id → rejected.
    expect(fn () => $svc->chart($fx['doctor'], $fx['patient'], '99', null, 'present'))->toThrow(DentalException::class);
    // A surface-only condition on a whole-tooth record → rejected (deterministic, not interpretive).
    expect(fn () => $svc->chart($fx['doctor'], $fx['patient'], '11', null, 'caries'))->toThrow(DentalException::class);
    // An invalid surface → rejected.
    expect(fn () => $svc->chart($fx['doctor'], $fx['patient'], '11', 'sideways', 'caries'))->toThrow(DentalException::class);
});

test('record-not-judge: the schema and charting output carry NO interpretation field (electric fence)', function () {
    $forbidden = ['severity', 'score', 'risk', 'grade', 'abnormal', 'flag', 'priority', 'recommendation', 'rating', 'interpretation', 'diagnosis', 'verdict', 'alert', 'normal', 'status'];

    // The stored schema has no interpretation column.
    foreach (Schema::getColumnListing('tooth_records') as $column) {
        expect(in_array($column, $forbidden, true))->toBeFalse("interpretation column '{$column}' exists on tooth_records");
    }

    // The service output is facts only — recursively no judgment key.
    $fx = dFixture();
    dSvc()->chart($fx['doctor'], $fx['patient'], '11', 'occlusal', 'caries');
    $output = dSvc()->currentChart($fx['doctor'], $fx['patient'])->map->toArray()->all();
    dAssertNoJudgment($output);
});

test('tooth records are tenant-isolated and fail closed across tenants', function () {
    $alpha = dFixture('alpha');
    dSvc()->chart($alpha['doctor'], $alpha['patient'], '11', null, 'present');

    // A second tenant: its doctor cannot chart the first tenant's patient.
    $beta = dFixture('beta');
    dCtx()->set($beta['tenant']);
    expect(fn () => dSvc()->chart($beta['doctor'], $alpha['patient'], '11', null, 'present'))->toThrow(CrossTenantReferenceException::class);

    // Under the beta tenant context, alpha's tooth records are invisible (BelongsToTenant).
    dCtx()->set($beta['tenant']);
    expect(ToothRecord::query()->where('patient_id', $alpha['patient']->id)->count())->toBe(0);

    // Under alpha context they are present.
    dCtx()->set($alpha['tenant']);
    expect(ToothRecord::query()->where('patient_id', $alpha['patient']->id)->count())->toBe(1);
});

test('RBAC: only a dental.chart holder can chart — reception and nurse are refused', function () {
    $fx = dFixture();

    foreach (['reception', 'nurse'] as $role) {
        $user = dUser($fx['tenant'], $role);
        dCtx()->set($fx['tenant']);
        expect(fn () => dSvc()->chart($user, $fx['patient'], '11', null, 'present'))
            ->toThrow(AuthorizationException::class);
    }

    // org_admin holds dental.chart (all permissions).
    $admin = dUser($fx['tenant'], 'org_admin');
    dCtx()->set($fx['tenant']);
    expect(dSvc()->chart($admin, $fx['patient'], '21', null, 'present')->tooth)->toBe('21');
});
