<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\ChargeViolation;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ChargeValidator;
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

function f3Tenant(string $slug): Tenant
{
    return Tenant::query()->create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function f3Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function f3Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function f3User(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => f3Role($role)->id,
    ]);

    return $user;
}

function f3Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create([
        'name' => $code.' Branch',
        'code' => $code,
        'timezone' => 'Europe/Zurich',
    ]);
}

function f3Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Validate',
        'last_name' => 'Patient',
        'date_of_birth' => '1975-01-01',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function f3Staff(User $user, Branch $branch): StaffProfile
{
    return StaffProfile::query()->create([
        'user_id' => $user->id,
        'first_name' => 'Billing',
        'last_name' => 'Validator',
        'display_name' => 'Billing Validator',
        'profession' => 'doctor',
        'primary_branch_id' => $branch->id,
        'status' => StaffProfile::STATUS_ACTIVE,
    ]);
}

function f3Resource(StaffProfile $staff, Branch $branch): Resource
{
    return Resource::query()->create([
        'type' => Resource::TYPE_PRACTITIONER,
        'name' => 'Validation Resource',
        'staff_profile_id' => $staff->id,
        'branch_id' => $branch->id,
        'active' => true,
    ]);
}

function f3Encounter(Patient $patient, StaffProfile $staff, Branch $branch): Encounter
{
    return Encounter::query()->create([
        'patient_id' => $patient->id,
        'practitioner_id' => $staff->id,
        'branch_id' => $branch->id,
        'appointment_id' => null,
        'type' => Encounter::TYPE_CONSULTATION,
        'started_at' => '2026-04-01 10:00:00',
        'status' => Encounter::STATUS_OPEN,
        'reason_for_visit' => 'Billing validation fixture',
    ]);
}

function f3Visit(Patient $patient, Branch $branch, Resource $resource, string $status = Visit::STATUS_COMPLETED): Visit
{
    return Visit::query()->create([
        'planned_visit_id' => null,
        'patient_id' => $patient->id,
        'resource_id' => $resource->id,
        'branch_id' => $branch->id,
        'scheduled_start_at' => '2026-04-01 09:00:00',
        'checked_in_at' => $status === Visit::STATUS_COMPLETED ? '2026-04-01 09:00:00' : null,
        'checked_out_at' => $status === Visit::STATUS_COMPLETED ? '2026-04-01 10:00:00' : null,
        'status' => $status,
        'client_visit_uuid' => 'f3-visit-'.strtolower((string) Str::ulid()),
    ]);
}

function f3Catalog(array $rules = [], int $version = 1): TariffCatalog
{
    return TariffCatalog::query()->create([
        'key' => 'eu-generic',
        'name' => 'EU Generic',
        'version' => $version,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'status' => TariffCatalog::STATUS_ACTIVE,
        'rules' => ['validation_rules' => $rules],
    ]);
}

function f3Item(TariffCatalog $catalog, string $code, array $overrides = []): TariffItem
{
    return TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id,
        'code' => $code,
        'description' => "{$code} service",
        'unit_price_minor' => 1000,
        'vat_rate_bp' => 0,
        'unit' => 'session',
        'requires_service_documentation' => false,
        'active' => true,
        ...$overrides,
    ]);
}

function f3Charge(
    Patient $patient,
    Branch $branch,
    TariffCatalog $catalog,
    TariffItem $item,
    User $actor,
    string $date,
    int $quantity = 1,
    ?Visit $visit = null,
    ?Encounter $encounter = null,
): Charge {
    return Charge::query()->create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter?->id,
        'visit_id' => $visit?->id,
        'branch_id' => $branch->id,
        'service_date' => $date,
        'tariff_catalog_id' => $catalog->id,
        'tariff_item_id' => $item->id,
        'code' => $item->code,
        'description' => $item->description,
        'unit_price_minor' => $item->unit_price_minor,
        'vat_rate_bp' => $item->vat_rate_bp,
        'quantity' => $quantity,
        'line_total_minor' => $quantity * $item->unit_price_minor,
        'status' => Charge::STATUS_DRAFT,
        'created_by' => $actor->id,
    ]);
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, staff: StaffProfile, resource: resource}
 */
function f3Fixture(string $slug = 'alpha', string $role = 'billing'): array
{
    $tenant = f3Tenant($slug);
    f3Ctx()->set($tenant);
    $actor = f3User($tenant, $role);
    $branch = f3Branch(strtoupper(substr($slug, 0, 4)));
    $patient = f3Patient();
    $staff = f3Staff($actor, $branch);
    $resource = f3Resource($staff, $branch);

    return compact('tenant', 'actor', 'branch', 'patient', 'staff', 'resource');
}

function f3Validate(Patient $patient, User $actor): array
{
    return app(ChargeValidator::class)->validateForPatientPeriod(
        $patient,
        '2026-04-01',
        '2026-04-30',
        $actor,
    );
}

function f3AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('max quantity per period passes under the cap and fails with a distinct reason code', function () {
    $fixture = f3Fixture();
    $catalog = f3Catalog([[
        'type' => ChargeValidator::RULE_MAX_QUANTITY_PER_PERIOD,
        'code' => 'HOME',
        'max' => 2,
        'period' => 'day',
    ]]);
    $home = f3Item($catalog, 'HOME');
    $other = f3Item($catalog, 'OTHER');

    f3Charge($fixture['patient'], $fixture['branch'], $catalog, $home, $fixture['actor'], '2026-04-01', 2);
    $failing = f3Charge($fixture['patient'], $fixture['branch'], $catalog, $home, $fixture['actor'], '2026-04-01', 1);
    $passing = f3Charge($fixture['patient'], $fixture['branch'], $catalog, $other, $fixture['actor'], '2026-04-01', 5);

    $result = f3Validate($fixture['patient'], $fixture['actor']);

    expect($result['validated'])->toBe([$passing->id])
        ->and($result['violations'])->toHaveCount(2)
        ->and($result['violations'][0]['reason_code'])->toBe(ChargeValidator::REASON_MAX_QUANTITY_PER_PERIOD_EXCEEDED)
        ->and($failing->refresh()->status)->toBe(Charge::STATUS_DRAFT)
        ->and($passing->refresh()->status)->toBe(Charge::STATUS_VALIDATED);
});

test('incompatible codes pass on different dates and fail together on the same date', function () {
    $fixture = f3Fixture();
    $catalog = f3Catalog([[
        'type' => ChargeValidator::RULE_INCOMPATIBLE_CODES,
        'codes' => ['A', 'B'],
    ]]);
    $a = f3Item($catalog, 'A');
    $b = f3Item($catalog, 'B');

    f3Charge($fixture['patient'], $fixture['branch'], $catalog, $a, $fixture['actor'], '2026-04-01');
    f3Charge($fixture['patient'], $fixture['branch'], $catalog, $b, $fixture['actor'], '2026-04-01');
    $passing = f3Charge($fixture['patient'], $fixture['branch'], $catalog, $b, $fixture['actor'], '2026-04-02');

    $result = f3Validate($fixture['patient'], $fixture['actor']);

    expect($result['validated'])->toBe([$passing->id])
        ->and($result['violations'])->toHaveCount(2)
        ->and($result['violations'][0]['reason_code'])->toBe(ChargeValidator::REASON_INCOMPATIBLE_CODES_SAME_DATE);
});

test('requires code passes with the base code and fails without it', function () {
    $fixture = f3Fixture();
    $catalog = f3Catalog([[
        'type' => ChargeValidator::RULE_REQUIRES_CODE,
        'code' => 'ADDON',
        'requires' => 'BASE',
    ]]);
    $addon = f3Item($catalog, 'ADDON');
    $base = f3Item($catalog, 'BASE');

    $failing = f3Charge($fixture['patient'], $fixture['branch'], $catalog, $addon, $fixture['actor'], '2026-04-01');
    $passingAddon = f3Charge($fixture['patient'], $fixture['branch'], $catalog, $addon, $fixture['actor'], '2026-04-02');
    $passingBase = f3Charge($fixture['patient'], $fixture['branch'], $catalog, $base, $fixture['actor'], '2026-04-02');

    $result = f3Validate($fixture['patient'], $fixture['actor']);

    expect($result['validated'])->toBe([$passingAddon->id, $passingBase->id])
        ->and($result['violations'])->toBe([[
            'charge_id' => $failing->id,
            'rule' => ChargeValidator::RULE_REQUIRES_CODE,
            'reason_code' => ChargeValidator::REASON_REQUIRED_CODE_MISSING,
            'message' => 'Code ADDON requires code BASE on the same service date.',
        ]]);
});

test('documentation required is rechecked at validation time', function () {
    $fixture = f3Fixture();
    $catalog = f3Catalog([[
        'type' => ChargeValidator::RULE_DOCUMENTATION_REQUIRED,
        'codes' => ['DOC'],
    ]]);
    $doc = f3Item($catalog, 'DOC', ['requires_service_documentation' => true]);
    $completedVisit = f3Visit($fixture['patient'], $fixture['branch'], $fixture['resource'], Visit::STATUS_COMPLETED);
    $reopenedVisit = f3Visit($fixture['patient'], $fixture['branch'], $fixture['resource'], Visit::STATUS_COMPLETED);

    $passing = f3Charge($fixture['patient'], $fixture['branch'], $catalog, $doc, $fixture['actor'], '2026-04-01', 1, $completedVisit);
    $failing = f3Charge($fixture['patient'], $fixture['branch'], $catalog, $doc, $fixture['actor'], '2026-04-01', 1, $reopenedVisit);
    $reopenedVisit->forceFill(['status' => Visit::STATUS_SCHEDULED])->save();

    $result = f3Validate($fixture['patient'], $fixture['actor']);

    expect($result['validated'])->toBe([$passing->id])
        ->and($result['violations'])->toBe([[
            'charge_id' => $failing->id,
            'rule' => ChargeValidator::RULE_DOCUMENTATION_REQUIRED,
            'reason_code' => ChargeValidator::REASON_DOCUMENTATION_REQUIRED_MISSING,
            'message' => 'Code DOC requires a signed encounter note or completed visit.',
        ]]);
});

test('validation is idempotent and does not duplicate statuses violations or audit rows', function () {
    $fixture = f3Fixture();
    $catalog = f3Catalog([[
        'type' => ChargeValidator::RULE_REQUIRES_CODE,
        'code' => 'ADDON',
        'requires' => 'BASE',
    ]]);
    $addon = f3Item($catalog, 'ADDON');
    $clean = f3Item($catalog, 'CLEAN');
    $failing = f3Charge($fixture['patient'], $fixture['branch'], $catalog, $addon, $fixture['actor'], '2026-04-01');
    $passing = f3Charge($fixture['patient'], $fixture['branch'], $catalog, $clean, $fixture['actor'], '2026-04-01');

    $first = f3Validate($fixture['patient'], $fixture['actor']);
    $second = f3Validate($fixture['patient'], $fixture['actor']);

    expect($second)->toBe($first)
        ->and(ChargeViolation::query()->where('charge_id', $failing->id)->count())->toBe(1)
        ->and($failing->refresh()->status)->toBe(Charge::STATUS_DRAFT)
        ->and($passing->refresh()->status)->toBe(Charge::STATUS_VALIDATED)
        ->and(f3AuditRows($fixture['tenant']->id, 'charge.violation'))->toHaveCount(1)
        ->and(f3AuditRows($fixture['tenant']->id, 'charge.validated'))->toHaveCount(1);
});

test('validation is tenant isolated audited fail closed and RBAC guarded', function () {
    $alpha = f3Fixture('alpha');
    $catalog = f3Catalog([]);
    $item = f3Item($catalog, 'CLEAN');
    $charge = f3Charge($alpha['patient'], $alpha['branch'], $catalog, $item, $alpha['actor'], '2026-04-01');

    $reception = f3User($alpha['tenant'], 'reception');
    expect(fn () => f3Validate($alpha['patient'], $reception))->toThrow(AuthorizationException::class);

    $result = f3Validate($alpha['patient'], $alpha['actor']);

    $beta = f3Fixture('beta');
    f3Catalog([]);

    expect(Charge::query()->whereKey($charge->id)->exists())->toBeFalse()
        ->and($result['validated'])->toBe([$charge->id]);

    f3Ctx()->set($alpha['tenant']);

    expect(Charge::query()->whereKey($charge->id)->exists())->toBeTrue()
        ->and(f3AuditRows($alpha['tenant']->id, 'charge.validated'))->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($alpha['tenant']->id)['ok'])->toBeTrue();

    f3Ctx()->forget();

    expect(fn () => ChargeViolation::query()->count())->toThrow(TenantContextMissingException::class);
});

test('golden files assert exact validation output for frozen catalog versions', function (string $fixturePath) {
    $golden = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
    $fixture = f3Fixture('golden-'.Str::slug(pathinfo($fixturePath, PATHINFO_FILENAME)));
    $catalog = f3Catalog($golden['rules'], (int) $golden['catalog_version']);
    $items = [];
    $chargesByKey = [];

    foreach ($golden['charges'] as $chargeFixture) {
        $code = (string) $chargeFixture['code'];
        $items[$code] ??= f3Item($catalog, $code, [
            'requires_service_documentation' => (bool) ($chargeFixture['requires_service_documentation'] ?? false),
        ]);

        $visit = null;
        if (($chargeFixture['source'] ?? 'manual') !== 'manual') {
            $visit = f3Visit($fixture['patient'], $fixture['branch'], $fixture['resource'], Visit::STATUS_COMPLETED);
        }

        $charge = f3Charge(
            $fixture['patient'],
            $fixture['branch'],
            $catalog,
            $items[$code],
            $fixture['actor'],
            (string) $chargeFixture['service_date'],
            (int) ($chargeFixture['quantity'] ?? 1),
            $visit,
        );

        if (($chargeFixture['source'] ?? 'manual') === 'visit_reopened' && $visit instanceof Visit) {
            $visit->forceFill(['status' => Visit::STATUS_SCHEDULED])->save();
        }

        $chargesByKey[$charge->id] = (string) $chargeFixture['key'];
    }

    $result = f3Validate($fixture['patient'], $fixture['actor']);
    $actual = [
        'validated' => collect($result['validated'])
            ->map(fn (string $id): string => $chargesByKey[$id])
            ->values()
            ->all(),
        'violations' => collect($result['violations'])
            ->map(fn (array $violation): array => [
                'charge' => $chargesByKey[$violation['charge_id']],
                'rule' => $violation['rule'],
                'reason_code' => $violation['reason_code'],
                'message' => $violation['message'],
            ])
            ->values()
            ->all(),
    ];

    expect($actual)->toBe($golden['expected']);
})->with(function (): array {
    return collect(glob(__DIR__.'/../../Fixtures/billing/golden/*.json') ?: [])
        ->sort()
        ->values()
        ->all();
});
