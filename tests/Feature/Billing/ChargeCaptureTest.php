<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ChargeCaptureService;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Nursing\Models\Visit;
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
use Modules\Scheduling\Models\Resource;

uses(RefreshDatabase::class);

function f2Tenant(string $slug): Tenant
{
    return Tenant::query()->create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function f2Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function f2Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function f2User(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => f2Role($role)->id,
    ]);

    return $user;
}

function f2Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create([
        'name' => $code.' Branch',
        'code' => $code,
        'timezone' => 'Europe/Zurich',
    ]);
}

function f2Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Billable',
        'last_name' => 'Patient',
        'date_of_birth' => '1980-01-01',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function f2Staff(User $user, Branch $branch): StaffProfile
{
    return StaffProfile::query()->create([
        'user_id' => $user->id,
        'first_name' => 'Billing',
        'last_name' => 'Clinician',
        'display_name' => 'Billing Clinician',
        'profession' => 'doctor',
        'primary_branch_id' => $branch->id,
        'status' => StaffProfile::STATUS_ACTIVE,
    ]);
}

function f2Encounter(Patient $patient, StaffProfile $staff, Branch $branch, string $startedAt = '2026-03-10 10:00:00'): Encounter
{
    return Encounter::query()->create([
        'patient_id' => $patient->id,
        'practitioner_id' => $staff->id,
        'branch_id' => $branch->id,
        'appointment_id' => null,
        'type' => Encounter::TYPE_CONSULTATION,
        'started_at' => $startedAt,
        'status' => Encounter::STATUS_OPEN,
        'reason_for_visit' => 'Billing fixture',
    ]);
}

function f2SignedNote(Encounter $encounter, StaffProfile $staff, User $user): ClinicalNote
{
    return ClinicalNote::query()->create([
        'encounter_id' => $encounter->id,
        'patient_id' => $encounter->patient_id,
        'author_id' => $staff->id,
        'subjective' => 'Documented',
        'objective' => 'Documented',
        'assessment' => 'Documented',
        'plan' => 'Documented',
        'status' => ClinicalNote::STATUS_SIGNED,
        'signed_at' => '2026-03-10 11:00:00',
        'signed_by' => $user->id,
        'version' => 1,
    ]);
}

function f2Resource(StaffProfile $staff, Branch $branch): Resource
{
    return Resource::query()->create([
        'type' => Resource::TYPE_PRACTITIONER,
        'name' => 'Nurse Resource',
        'staff_profile_id' => $staff->id,
        'branch_id' => $branch->id,
        'active' => true,
    ]);
}

function f2Visit(Patient $patient, Branch $branch, Resource $resource, string $status = Visit::STATUS_COMPLETED): Visit
{
    return Visit::query()->create([
        'planned_visit_id' => null,
        'patient_id' => $patient->id,
        'resource_id' => $resource->id,
        'branch_id' => $branch->id,
        'scheduled_start_at' => '2026-03-11 09:00:00',
        'checked_in_at' => $status === Visit::STATUS_COMPLETED ? '2026-03-11 09:05:00' : null,
        'checked_out_at' => $status === Visit::STATUS_COMPLETED ? '2026-03-11 10:05:00' : null,
        'status' => $status,
        'client_visit_uuid' => 'visit-'.strtolower((string) Str::ulid()),
    ]);
}

function f2Catalog(array $overrides = []): TariffCatalog
{
    return TariffCatalog::query()->create([
        'key' => 'eu-generic',
        'name' => 'EU Generic',
        'version' => 1,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'status' => TariffCatalog::STATUS_ACTIVE,
        'rules' => [],
        ...$overrides,
    ]);
}

function f2Item(TariffCatalog $catalog, array $overrides = []): TariffItem
{
    return TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id,
        'code' => 'BILL-001',
        'description' => 'Billable service',
        'unit_price_minor' => 1667,
        'vat_rate_bp' => 810,
        'unit' => 'session',
        'requires_service_documentation' => false,
        'active' => true,
        ...$overrides,
    ]);
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, staff: StaffProfile, catalog: TariffCatalog}
 */
function f2Fixture(string $slug = 'alpha', string $role = 'billing'): array
{
    $tenant = f2Tenant($slug);
    f2Ctx()->set($tenant);
    $actor = f2User($tenant, $role);
    $branch = f2Branch(strtoupper(substr($slug, 0, 4)));
    $patient = f2Patient();
    $staff = f2Staff($actor, $branch);
    $catalog = f2Catalog();

    return compact('tenant', 'actor', 'branch', 'patient', 'staff', 'catalog');
}

function f2AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('captured charge snapshots survive later tariff updates and are not re-resolved', function () {
    $fixture = f2Fixture();
    $item = f2Item($fixture['catalog'], [
        'code' => 'ROUND-810',
        'unit_price_minor' => 1667,
        'vat_rate_bp' => 810,
    ]);

    $charge = app(ChargeCaptureService::class)->captureManual(
        $fixture['patient'],
        $fixture['branch'],
        '2026-03-10',
        'ROUND-810',
        3,
        $fixture['actor'],
    );

    $item->forceFill([
        'unit_price_minor' => 9999,
        'vat_rate_bp' => 1900,
        'description' => 'Changed later',
    ])->save();

    $reRead = Charge::query()->whereKey($charge->id)->firstOrFail();
    $invoiceVatMinorLater = intdiv($reRead->line_total_minor * $reRead->vat_rate_bp + 5000, 10000);

    expect($reRead->code)->toBe('ROUND-810')
        ->and($reRead->description)->toBe('Billable service')
        ->and($reRead->unit_price_minor)->toBe(1667)
        ->and($reRead->vat_rate_bp)->toBe(810)
        ->and($reRead->quantity)->toBe(3)
        ->and($reRead->line_total_minor)->toBe(5001)
        ->and($invoiceVatMinorLater)->toBe(405)
        ->and(app(ChargeCaptureService::class))
        ->not->toBeNull();
});

test('documentation required tariffs need a signed encounter note or completed visit', function () {
    $fixture = f2Fixture();
    f2Item($fixture['catalog'], [
        'code' => 'DOC-REQ',
        'requires_service_documentation' => true,
    ]);

    $encounter = f2Encounter($fixture['patient'], $fixture['staff'], $fixture['branch']);
    $resource = f2Resource($fixture['staff'], $fixture['branch']);
    $scheduledVisit = f2Visit($fixture['patient'], $fixture['branch'], $resource, Visit::STATUS_SCHEDULED);

    expect(fn () => app(ChargeCaptureService::class)->captureFromEncounter($encounter, 'DOC-REQ', 1, $fixture['actor']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ChargeCaptureService::class)->captureFromVisit($scheduledVisit, 'DOC-REQ', 1, $fixture['actor']))
        ->toThrow(InvalidArgumentException::class)
        ->and(Charge::query()->count())->toBe(0);

    f2SignedNote($encounter, $fixture['staff'], $fixture['actor']);
    $completedVisit = f2Visit($fixture['patient'], $fixture['branch'], $resource, Visit::STATUS_COMPLETED);

    $encounterCharge = app(ChargeCaptureService::class)->captureFromEncounter($encounter, 'DOC-REQ', 1, $fixture['actor']);
    $visitCharge = app(ChargeCaptureService::class)->captureFromVisit($completedVisit, 'DOC-REQ', 1, $fixture['actor']);

    expect($encounterCharge->encounter_id)->toBe($encounter->id)
        ->and($visitCharge->visit_id)->toBe($completedVisit->id)
        ->and(Charge::query()->count())->toBe(2);
});

test('charge source is encounter xor visit or manual but never both', function () {
    $fixture = f2Fixture();
    $item = f2Item($fixture['catalog'], ['code' => 'SRC']);
    $encounter = f2Encounter($fixture['patient'], $fixture['staff'], $fixture['branch']);
    $resource = f2Resource($fixture['staff'], $fixture['branch']);
    $visit = f2Visit($fixture['patient'], $fixture['branch'], $resource);

    $manual = app(ChargeCaptureService::class)->captureManual($fixture['patient'], $fixture['branch'], '2026-03-10', 'SRC', 1, $fixture['actor']);
    $encounterCharge = app(ChargeCaptureService::class)->captureFromEncounter($encounter, 'SRC', 1, $fixture['actor']);
    $visitCharge = app(ChargeCaptureService::class)->captureFromVisit($visit, 'SRC', 1, $fixture['actor']);

    expect($manual->isManual())->toBeTrue()
        ->and($encounterCharge->encounter_id)->toBe($encounter->id)
        ->and($encounterCharge->visit_id)->toBeNull()
        ->and($visitCharge->visit_id)->toBe($visit->id)
        ->and($visitCharge->encounter_id)->toBeNull()
        ->and(fn () => Charge::query()->create([
            'patient_id' => $fixture['patient']->id,
            'encounter_id' => $encounter->id,
            'visit_id' => $visit->id,
            'branch_id' => $fixture['branch']->id,
            'service_date' => '2026-03-10',
            'tariff_catalog_id' => $fixture['catalog']->id,
            'tariff_item_id' => $item->id,
            'code' => 'SRC',
            'description' => 'Invalid',
            'unit_price_minor' => 100,
            'vat_rate_bp' => 0,
            'quantity' => 1,
            'line_total_minor' => 100,
            'created_by' => $fixture['actor']->id,
        ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => DB::table('charges')->insert([
        'id' => (string) Str::ulid(),
        'tenant_id' => $fixture['tenant']->id,
        'patient_id' => $fixture['patient']->id,
        'encounter_id' => $encounter->id,
        'visit_id' => $visit->id,
        'branch_id' => $fixture['branch']->id,
        'service_date' => '2026-03-10',
        'tariff_catalog_id' => $fixture['catalog']->id,
        'tariff_item_id' => $item->id,
        'code' => 'SRC',
        'description' => 'Invalid raw',
        'unit_price_minor' => 100,
        'vat_rate_bp' => 0,
        'quantity' => 1,
        'line_total_minor' => 100,
        'status' => Charge::STATUS_DRAFT,
        'created_by' => $fixture['actor']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('draft or validated charges can be cancelled with a reason but invoiced charges cannot', function () {
    $fixture = f2Fixture();
    f2Item($fixture['catalog'], ['code' => 'CANCEL']);
    $service = app(ChargeCaptureService::class);
    $charge = $service->captureManual($fixture['patient'], $fixture['branch'], '2026-03-10', 'CANCEL', 1, $fixture['actor']);

    expect(fn () => $service->cancel($charge, $fixture['actor'], ''))->toThrow(InvalidArgumentException::class);

    $cancelled = $service->cancel($charge->refresh(), $fixture['actor'], 'Entered in error');

    expect($cancelled->status)->toBe(Charge::STATUS_CANCELLED)
        ->and($cancelled->cancelled_reason)->toBe('Entered in error');

    $invoiced = $service->captureManual($fixture['patient'], $fixture['branch'], '2026-03-10', 'CANCEL', 1, $fixture['actor']);
    $invoiced->forceFill(['status' => Charge::STATUS_INVOICED])->save();

    expect(fn () => $service->cancel($invoiced->refresh(), $fixture['actor'], 'No direct invoice cancel'))
        ->toThrow(InvalidArgumentException::class);
});

test('charges are tenant isolated fail closed audited and require billing manage', function () {
    $alpha = f2Fixture('alpha');
    f2Item($alpha['catalog'], ['code' => 'AUDIT']);
    $charge = app(ChargeCaptureService::class)->captureManual(
        $alpha['patient'],
        $alpha['branch'],
        '2026-03-10',
        'AUDIT',
        1,
        $alpha['actor'],
    );
    app(ChargeCaptureService::class)->cancel($charge->refresh(), $alpha['actor'], 'Audit cancel');

    $reception = f2User($alpha['tenant'], 'reception');

    expect(fn () => app(ChargeCaptureService::class)->captureManual(
        $alpha['patient'],
        $alpha['branch'],
        '2026-03-10',
        'AUDIT',
        1,
        $reception,
    ))->toThrow(AuthorizationException::class);

    $beta = f2Fixture('beta');
    f2Item($beta['catalog'], ['code' => 'AUDIT']);

    expect(Charge::query()->whereKey($charge->id)->exists())->toBeFalse();

    f2Ctx()->set($alpha['tenant']);

    expect(Charge::query()->whereKey($charge->id)->exists())->toBeTrue()
        ->and(f2AuditRows($alpha['tenant']->id, 'charge.captured'))->toHaveCount(1)
        ->and(f2AuditRows($alpha['tenant']->id, 'charge.cancelled'))->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($alpha['tenant']->id)['ok'])->toBeTrue();

    f2Ctx()->forget();

    expect(fn () => Charge::query()->count())->toThrow(TenantContextMissingException::class);
});
