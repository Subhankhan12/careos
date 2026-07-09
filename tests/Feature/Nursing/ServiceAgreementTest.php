<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Services\AuditService;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Services\ServiceAgreementService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\Service;

uses(RefreshDatabase::class);

function e1Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function e1Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function e1Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function e1User(Tenant $tenant, string $role = 'coordinator'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    if ($role !== '') {
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => e1Role($role)->id,
        ]);
    }

    return $user;
}

function e1Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function e1Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Nursing',
        'last_name' => 'Patient',
        'date_of_birth' => '1946-04-12',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function e1SchedulingService(array $overrides = []): Service
{
    return Service::query()->create([
        'name' => 'Home nursing visit',
        'code' => 'HOME-NURSE',
        'category' => 'home-care',
        'default_duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => false,
        'active' => true,
        ...$overrides,
    ]);
}

/**
 * @return array<string, mixed>
 */
function e1AgreementPayload(Patient $patient, Branch $branch, array $overrides = []): array
{
    return [
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'funding_type' => ServiceAgreement::FUNDING_PRIVATE_INSURANCE,
        'payer_name' => 'Care Mutual',
        'authorization_ref' => 'AUTH-123',
        'authorized_hours_per_week' => '12.50',
        'starts_on' => '2026-08-01',
        'ends_on' => null,
        ...$overrides,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function e1AgreementServices(Service $service, array $overrides = []): array
{
    return [[
        'service_id' => $service->id,
        'planned_frequency_text' => 'Three visits per week as documented',
        'required_qualification' => 'RN',
        'duration_minutes' => 60,
        ...$overrides,
    ]];
}

function e1CreateAgreement(User $actor, Patient $patient, Branch $branch, Service $service): ServiceAgreement
{
    return app(ServiceAgreementService::class)->create(
        e1AgreementPayload($patient, $branch),
        e1AgreementServices($service),
        $actor,
    );
}

function e1AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('service agreements and agreement services are tenant isolated and fail closed', function () {
    $alpha = e1Tenant('alpha');
    $beta = e1Tenant('beta');

    e1Ctx()->set($alpha);
    $alphaUser = e1User($alpha);
    $alphaAgreement = e1CreateAgreement($alphaUser, e1Patient(), e1Branch('ALPHA'), e1SchedulingService([
        'code' => 'ALPHA-HOME',
    ]));

    e1Ctx()->set($beta);
    $betaUser = e1User($beta);
    $betaAgreement = e1CreateAgreement($betaUser, e1Patient([
        'first_name' => 'Beta',
    ]), e1Branch('BETA'), e1SchedulingService([
        'code' => 'BETA-HOME',
    ]));

    expect(ServiceAgreement::query()->pluck('id')->all())->toBe([$betaAgreement->id])
        ->and(ServiceAgreement::query()->whereKey($alphaAgreement->id)->exists())->toBeFalse()
        ->and(AgreementService::query()->where('service_agreement_id', $alphaAgreement->id)->exists())->toBeFalse();

    e1Ctx()->forget();

    expect(fn () => ServiceAgreement::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => AgreementService::query()->count())->toThrow(TenantContextMissingException::class);
});

test('agreement manage is granted to org admins and coordinators but not reception', function () {
    $tenant = e1Tenant('alpha');
    e1Ctx()->set($tenant);

    $orgAdmin = e1User($tenant, 'org_admin');
    $coordinator = e1User($tenant, 'coordinator');
    $reception = e1User($tenant, 'reception');
    $branch = e1Branch();
    $patient = e1Patient();
    $service = e1SchedulingService();

    expect(Gate::forUser($orgAdmin)->allows('agreement.manage'))->toBeTrue()
        ->and(Gate::forUser($coordinator)->allows('agreement.manage'))->toBeTrue()
        ->and(Gate::forUser($reception)->allows('agreement.manage'))->toBeFalse()
        ->and(fn () => e1CreateAgreement($reception, $patient, $branch, $service))
        ->toThrow(AuthorizationException::class);
});

test('service agreement lifecycle enforces legal transitions and audits changes', function () {
    $tenant = e1Tenant('alpha');
    e1Ctx()->set($tenant);
    $actor = e1User($tenant);
    $agreement = e1CreateAgreement($actor, e1Patient(), e1Branch(), e1SchedulingService());
    $service = app(ServiceAgreementService::class);

    $service->activate($agreement, $actor);
    $service->suspend($agreement->refresh(), $actor);
    $service->activate($agreement->refresh(), $actor);
    $service->end($agreement->refresh(), $actor);

    expect($agreement->refresh()->status)->toBe(ServiceAgreement::STATUS_ENDED)
        ->and(fn () => $service->activate($agreement->refresh(), $actor))->toThrow(InvalidArgumentException::class)
        ->and(e1AuditRows($tenant->id, 'service_agreement.created'))->toHaveCount(1)
        ->and(e1AuditRows($tenant->id, 'service_agreement.active'))->toHaveCount(2)
        ->and(e1AuditRows($tenant->id, 'service_agreement.suspended'))->toHaveCount(1)
        ->and(e1AuditRows($tenant->id, 'service_agreement.ended'))->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('cross tenant patient branch and service references are rejected', function () {
    $alpha = e1Tenant('alpha');
    $beta = e1Tenant('beta');

    e1Ctx()->set($alpha);
    $actor = e1User($alpha);
    $alphaPatient = e1Patient();
    $alphaBranch = e1Branch('ALPHA');
    $alphaService = e1SchedulingService(['code' => 'ALPHA']);

    e1Ctx()->set($beta);
    $betaPatient = e1Patient(['first_name' => 'Beta']);
    $betaBranch = e1Branch('BETA');
    $betaService = e1SchedulingService(['code' => 'BETA']);

    e1Ctx()->set($alpha);

    expect(fn () => app(ServiceAgreementService::class)->create(
        e1AgreementPayload($betaPatient, $alphaBranch),
        e1AgreementServices($alphaService),
        $actor,
    ))->toThrow(CrossTenantReferenceException::class)
        ->and(fn () => app(ServiceAgreementService::class)->create(
            e1AgreementPayload($alphaPatient, $betaBranch),
            e1AgreementServices($alphaService),
            $actor,
        ))->toThrow(CrossTenantReferenceException::class)
        ->and(fn () => app(ServiceAgreementService::class)->create(
            e1AgreementPayload($alphaPatient, $alphaBranch),
            e1AgreementServices($betaService),
            $actor,
        ))->toThrow(CrossTenantReferenceException::class);
});

test('reading a service agreement writes a patient scoped read audit row', function () {
    $tenant = e1Tenant('alpha');
    e1Ctx()->set($tenant);
    $actor = e1User($tenant);
    $patient = e1Patient();
    $agreement = e1CreateAgreement($actor, $patient, e1Branch(), e1SchedulingService());

    $readAgreement = app(ServiceAgreementService::class)->read($agreement, $actor);
    $readRows = e1AuditRows($tenant->id, 'read');

    expect($readAgreement->id)->toBe($agreement->id)
        ->and($readRows)->toHaveCount(1)
        ->and($readRows[0]->resource_type)->toBe('service_agreement')
        ->and($readRows[0]->resource_id)->toBe($agreement->id)
        ->and($readRows[0]->patient_id)->toBe($patient->id)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('service agreement schemas expose the expected columns', function () {
    expect(Schema::hasColumns('service_agreements', [
        'id',
        'tenant_id',
        'patient_id',
        'branch_id',
        'funding_type',
        'payer_name',
        'authorization_ref',
        'authorized_hours_per_week',
        'starts_on',
        'ends_on',
        'status',
        'created_by',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('agreement_services', [
            'id',
            'tenant_id',
            'service_agreement_id',
            'service_id',
            'planned_frequency_text',
            'required_qualification',
            'duration_minutes',
        ]))->toBeTrue();
});
