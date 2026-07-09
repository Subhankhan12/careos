<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Services\AuditService;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\NurseConstraint;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Services\AssignmentValidator;
use Modules\Nursing\Services\ServiceAgreementService;
use Modules\Nursing\Services\VisitAssignmentService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\Service;

uses(RefreshDatabase::class);

function e3Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Nursing',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function e3Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function e3Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function e3User(Tenant $tenant, string $role = 'coordinator'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    if ($role !== '') {
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => e3Role($role)->id,
        ]);
    }

    return $user;
}

function e3Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function e3Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Dispatch',
        'last_name' => 'Patient',
        'date_of_birth' => '1942-02-02',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function e3SchedulingService(array $overrides = []): Service
{
    return Service::query()->create([
        'name' => 'Nursing visit',
        'code' => 'NURSE-VISIT',
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

function e3Resource(Branch $branch, string $name = 'Nurse One'): BookableResource
{
    return BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => $name,
        'branch_id' => $branch->id,
    ]);
}

function e3Constraint(BookableResource $resource, array $overrides = []): NurseConstraint
{
    return NurseConstraint::query()->create([
        'resource_id' => $resource->id,
        'qualification' => 'RN',
        'max_hours_per_week' => '40.00',
        'max_travel_minutes_between_visits' => 60,
        ...$overrides,
    ]);
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, agreement: ServiceAgreement, agreementService: AgreementService, plan: VisitPlan, resource: BookableResource}
 */
function e3Fixture(string $slug = 'alpha'): array
{
    $tenant = e3Tenant($slug);
    e3Ctx()->set($tenant);
    $actor = e3User($tenant);
    $branch = e3Branch(strtoupper(substr($slug, 0, 4)));
    $patient = e3Patient(['first_name' => ucfirst($slug)]);
    $service = e3SchedulingService(['code' => strtoupper($slug).'-NURSE']);

    $agreement = app(ServiceAgreementService::class)->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'funding_type' => ServiceAgreement::FUNDING_OTHER,
        'starts_on' => '2026-08-01',
    ], [[
        'service_id' => $service->id,
        'planned_frequency_text' => 'Weekdays as documented',
        'required_qualification' => 'RN',
        'duration_minutes' => 60,
    ]], $actor);

    $plan = VisitPlan::query()->create([
        'service_agreement_id' => $agreement->id,
        'agreement_service_id' => $agreement->agreementServices()->firstOrFail()->id,
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=1',
        'timezone' => 'Europe/Zurich',
        'window_start_time' => '09:00:00',
        'window_end_time' => '11:00:00',
        'duration_minutes' => 60,
        'starts_on' => '2026-08-03',
        'active' => true,
    ]);

    $resource = e3Resource($branch);
    e3Constraint($resource);

    return [
        'tenant' => $tenant,
        'actor' => $actor,
        'branch' => $branch,
        'patient' => $patient,
        'agreement' => $agreement,
        'agreementService' => $agreement->agreementServices()->firstOrFail(),
        'plan' => $plan,
        'resource' => $resource,
    ];
}

function e3Visit(array $fixture, array $overrides = []): PlannedVisit
{
    return PlannedVisit::query()->create([
        'visit_plan_id' => $fixture['plan']->id,
        'patient_id' => $fixture['patient']->id,
        'scheduled_date' => '2026-08-03',
        'window_start_at' => '2026-08-03 09:00:00',
        'window_end_at' => '2026-08-03 10:00:00',
        'duration_minutes' => 60,
        'required_qualification' => 'RN',
        'status' => PlannedVisit::STATUS_PLANNED,
        'location_latitude' => '47.376900',
        'location_longitude' => '8.541700',
        ...$overrides,
    ]);
}

function e3AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('assignment validator qualification rule has pass and fail outcomes', function () {
    $fixture = e3Fixture();
    $visit = e3Visit($fixture);

    expect(app(AssignmentValidator::class)->validate($visit, $fixture['resource'], []))->toBe([]);

    $visit->forceFill(['required_qualification' => 'LPN'])->save();

    expect(app(AssignmentValidator::class)->validate($visit->refresh(), $fixture['resource'], []))
        ->toContain(AssignmentValidator::REASON_QUALIFICATION);
});

test('assignment validator window rule uses half open interval overlap with pass and fail outcomes', function () {
    $fixture = e3Fixture();
    $visit = e3Visit($fixture, [
        'window_start_at' => '2026-08-03 10:00:00',
        'window_end_at' => '2026-08-03 11:00:00',
    ]);
    $adjacent = e3Visit($fixture, [
        'scheduled_date' => '2026-08-04',
        'window_start_at' => '2026-08-03 09:00:00',
        'window_end_at' => '2026-08-03 10:00:00',
        'status' => PlannedVisit::STATUS_ASSIGNED,
        'assigned_resource_id' => $fixture['resource']->id,
    ]);

    expect(app(AssignmentValidator::class)->validate($visit, $fixture['resource'], [$adjacent]))->not->toContain(AssignmentValidator::REASON_WINDOW_OVERLAP);

    $overlap = e3Visit($fixture, [
        'scheduled_date' => '2026-08-05',
        'window_start_at' => '2026-08-03 10:30:00',
        'window_end_at' => '2026-08-03 11:30:00',
        'status' => PlannedVisit::STATUS_ASSIGNED,
        'assigned_resource_id' => $fixture['resource']->id,
    ]);

    expect(app(AssignmentValidator::class)->validate($visit, $fixture['resource'], [$overlap]))
        ->toContain(AssignmentValidator::REASON_WINDOW_OVERLAP);
});

test('assignment validator travel rule uses straight line estimation with pass and fail outcomes', function () {
    $fixture = e3Fixture();
    app(SettingsService::class)->set('nursing.dispatch.average_speed_kmh', 40, 'float');
    $visit = e3Visit($fixture, [
        'window_start_at' => '2026-08-03 10:00:00',
        'window_end_at' => '2026-08-03 11:00:00',
    ]);
    $near = e3Visit($fixture, [
        'scheduled_date' => '2026-08-04',
        'window_start_at' => '2026-08-03 09:00:00',
        'window_end_at' => '2026-08-03 09:30:00',
        'status' => PlannedVisit::STATUS_ASSIGNED,
        'assigned_resource_id' => $fixture['resource']->id,
        'location_latitude' => '47.377000',
        'location_longitude' => '8.542000',
    ]);

    expect(app(AssignmentValidator::class)->validate($visit, $fixture['resource'], [$near]))->not->toContain(AssignmentValidator::REASON_TRAVEL_INFEASIBLE);

    $far = e3Visit($fixture, [
        'scheduled_date' => '2026-08-05',
        'window_start_at' => '2026-08-03 09:00:00',
        'window_end_at' => '2026-08-03 09:30:00',
        'status' => PlannedVisit::STATUS_ASSIGNED,
        'assigned_resource_id' => $fixture['resource']->id,
        'location_latitude' => '46.204400',
        'location_longitude' => '6.143200',
    ]);

    expect(app(AssignmentValidator::class)->validate($visit, $fixture['resource'], [$far]))
        ->toContain(AssignmentValidator::REASON_TRAVEL_INFEASIBLE);
});

test('assignment validator hour cap rule has pass and fail outcomes', function () {
    $fixture = e3Fixture();
    NurseConstraint::query()->where('resource_id', $fixture['resource']->id)->update(['max_hours_per_week' => '1.50']);
    $visit = e3Visit($fixture, [
        'window_start_at' => '2026-08-03 10:00:00',
        'window_end_at' => '2026-08-03 11:00:00',
    ]);
    $existing = e3Visit($fixture, [
        'scheduled_date' => '2026-08-04',
        'window_start_at' => '2026-08-03 08:00:00',
        'window_end_at' => '2026-08-03 09:00:00',
        'status' => PlannedVisit::STATUS_ASSIGNED,
        'assigned_resource_id' => $fixture['resource']->id,
    ]);

    expect(app(AssignmentValidator::class)->validate($visit, $fixture['resource'], []))->not->toContain(AssignmentValidator::REASON_HOUR_CAP_EXCEEDED)
        ->and(app(AssignmentValidator::class)->validate($visit, $fixture['resource'], [$existing]))
        ->toContain(AssignmentValidator::REASON_HOUR_CAP_EXCEEDED);
});

test('visit assignment requires dispatch permission persists assignment and audits unassignment', function () {
    $fixture = e3Fixture();
    $visit = e3Visit($fixture);
    $reception = e3User($fixture['tenant'], 'reception');

    expect(Gate::forUser($fixture['actor'])->allows('dispatch.manage'))->toBeTrue()
        ->and(Gate::forUser($reception)->allows('dispatch.manage'))->toBeFalse()
        ->and(fn () => app(VisitAssignmentService::class)->assign($visit, $fixture['resource'], $reception))
        ->toThrow(AuthorizationException::class);

    $assigned = app(VisitAssignmentService::class)->assign($visit, $fixture['resource'], $fixture['actor']);

    expect($assigned->status)->toBe(PlannedVisit::STATUS_ASSIGNED)
        ->and($assigned->assigned_resource_id)->toBe($fixture['resource']->id)
        ->and($assigned->assigned_by)->toBe($fixture['actor']->id)
        ->and($assigned->assigned_at)->not->toBeNull()
        ->and(e3AuditRows($fixture['tenant']->id, 'planned_visit.assigned'))->toHaveCount(1);

    $unassigned = app(VisitAssignmentService::class)->unassign($assigned, $fixture['actor']);

    expect($unassigned->status)->toBe(PlannedVisit::STATUS_PLANNED)
        ->and($unassigned->assigned_resource_id)->toBeNull()
        ->and(e3AuditRows($fixture['tenant']->id, 'planned_visit.unassigned'))->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($fixture['tenant']->id)['ok'])->toBeTrue();
});

test('assignment data is tenant isolated fail closed and schema is present', function () {
    $alpha = e3Fixture('alpha');
    $alphaVisit = e3Visit($alpha);

    $beta = e3Fixture('beta');
    e3Visit($beta);

    expect(PlannedVisit::query()->whereKey($alphaVisit->id)->exists())->toBeFalse()
        ->and(NurseConstraint::query()->where('resource_id', $alpha['resource']->id)->exists())->toBeFalse();

    e3Ctx()->forget();

    expect(fn () => PlannedVisit::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => NurseConstraint::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(Schema::hasColumns('planned_visits', [
            'assigned_resource_id',
            'assigned_at',
            'assigned_by',
            'location_latitude',
            'location_longitude',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('nurse_constraints', [
            'id',
            'tenant_id',
            'resource_id',
            'qualification',
            'max_hours_per_week',
            'max_travel_minutes_between_visits',
        ]))->toBeTrue();
});

test('dispatcher board renders tenant scoped visits and read logs board data', function () {
    $alpha = e3Fixture('alpha');
    $alphaVisit = e3Visit($alpha);
    app(VisitAssignmentService::class)->assign($alphaVisit, $alpha['resource'], $alpha['actor']);

    $beta = e3Fixture('beta');
    e3Visit($beta);

    e3Ctx()->set($alpha['tenant']);

    $this->actingAs($alpha['actor'])
        ->get(route('nursing.dispatch', ['branch_id' => $alpha['branch']->id, 'date' => '2026-08-03']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Nursing/Dispatch')
            ->has('nurseLanes', 1)
            ->has('nurseLanes.0.visits', 1)
            ->where('nurseLanes.0.visits.0.patient', 'Alpha Patient')
            ->has('unassignedVisits', 0));

    $readRows = e3AuditRows($alpha['tenant']->id, 'read');

    expect($readRows)->toHaveCount(1)
        ->and($readRows[0]->resource_type)->toBe('planned_visit')
        ->and($readRows[0]->resource_id)->toBe($alphaVisit->id)
        ->and($readRows[0]->patient_id)->toBe($alpha['patient']->id);

    $this->actingAs(e3User($alpha['tenant'], 'reception'))
        ->get(route('nursing.dispatch', ['branch_id' => $alpha['branch']->id, 'date' => '2026-08-03']))
        ->assertForbidden();
});
