<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Services\AuditService;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Services\ServiceAgreementService;
use Modules\Nursing\Services\VisitPlanGenerator;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
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

function e2Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Nursing',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function e2Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function e2Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function e2User(Tenant $tenant, string $role = 'coordinator'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => e2Role($role)->id,
    ]);

    return $user;
}

function e2Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function e2Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Planned',
        'last_name' => 'Visit',
        'date_of_birth' => '1940-01-12',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function e2SchedulingService(array $overrides = []): Service
{
    return Service::query()->create([
        'name' => 'Home care visit',
        'code' => 'HOME-CARE',
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
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, agreement: ServiceAgreement, agreementService: AgreementService}
 */
function e2Fixture(string $slug = 'alpha'): array
{
    $tenant = e2Tenant($slug);
    e2Ctx()->set($tenant);
    $actor = e2User($tenant);
    $branch = e2Branch(strtoupper(substr($slug, 0, 4)));
    $patient = e2Patient(['first_name' => ucfirst($slug)]);
    $service = e2SchedulingService(['code' => strtoupper($slug).'-HOME']);

    $agreement = app(ServiceAgreementService::class)->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'funding_type' => ServiceAgreement::FUNDING_OTHER,
        'starts_on' => '2026-01-01',
    ], [[
        'service_id' => $service->id,
        'planned_frequency_text' => 'As defined by RRULE',
        'required_qualification' => 'RN',
        'duration_minutes' => 60,
    ]], $actor);

    return [
        'tenant' => $tenant,
        'actor' => $actor,
        'branch' => $branch,
        'patient' => $patient,
        'agreement' => $agreement,
        'agreementService' => $agreement->agreementServices()->firstOrFail(),
    ];
}

function e2VisitPlan(array $fixture, array $overrides = []): VisitPlan
{
    return VisitPlan::query()->create([
        'service_agreement_id' => $fixture['agreement']->id,
        'agreement_service_id' => $fixture['agreementService']->id,
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE,FR;COUNT=6',
        'timezone' => 'Europe/Zurich',
        'window_start_time' => '09:00:00',
        'window_end_time' => '11:00:00',
        'duration_minutes' => 60,
        'starts_on' => '2026-08-03',
        'ends_on' => null,
        'active' => true,
        ...$overrides,
    ]);
}

function e2AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('RRULE generation materializes an exact weekly Mon Wed Fri pattern', function () {
    $fixture = e2Fixture();
    $plan = e2VisitPlan($fixture);

    $created = app(VisitPlanGenerator::class)->materialize($plan, '2026-08-01', '2026-08-16');
    $visits = PlannedVisit::query()->where('visit_plan_id', $plan->id)->orderBy('scheduled_date')->get();

    expect($created)->toBe(6)
        ->and($visits->pluck('scheduled_date')->map->toDateString()->all())->toBe([
            '2026-08-03',
            '2026-08-05',
            '2026-08-07',
            '2026-08-10',
            '2026-08-12',
            '2026-08-14',
        ])
        ->and($visits->pluck('duration_minutes')->unique()->all())->toBe([60])
        ->and($visits->pluck('required_qualification')->unique()->all())->toBe(['RN'])
        ->and(e2AuditRows($fixture['tenant']->id, 'planned_visit.materialized'))->toHaveCount(6);
});

test('DST correctness preserves local wall clock time across spring forward and fall back', function () {
    $springFixture = e2Fixture('spring');
    $springPlan = e2VisitPlan($springFixture, [
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=3',
        'starts_on' => '2026-03-23',
    ]);

    app(VisitPlanGenerator::class)->materialize($springPlan, '2026-03-20', '2026-04-07');

    $springVisits = PlannedVisit::query()
        ->where('visit_plan_id', $springPlan->id)
        ->orderBy('scheduled_date')
        ->get();

    expect($springVisits)->toHaveCount(3)
        ->and($springVisits->map(
            fn (PlannedVisit $visit) => $visit->window_start_at->copy()->setTimezone('Europe/Zurich')->format('H:i'),
        )->all())->toBe(['09:00', '09:00', '09:00'])
        ->and($springVisits[0]->window_start_at->format('H:i'))->toBe('08:00')
        ->and($springVisits[1]->window_start_at->format('H:i'))->toBe('07:00');

    $fallFixture = e2Fixture('fall');
    $fallPlan = e2VisitPlan($fallFixture, [
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=3',
        'starts_on' => '2026-10-19',
    ]);

    app(VisitPlanGenerator::class)->materialize($fallPlan, '2026-10-15', '2026-11-03');

    $fallVisits = PlannedVisit::query()
        ->where('visit_plan_id', $fallPlan->id)
        ->orderBy('scheduled_date')
        ->get();

    expect($fallVisits)->toHaveCount(3)
        ->and($fallVisits->map(
            fn (PlannedVisit $visit) => $visit->window_start_at->copy()->setTimezone('Europe/Zurich')->format('H:i'),
        )->all())->toBe(['09:00', '09:00', '09:00'])
        ->and($fallVisits[0]->window_start_at->format('H:i'))->toBe('07:00')
        ->and($fallVisits[1]->window_start_at->format('H:i'))->toBe('08:00');
});

test('re materializing is idempotent and creates no duplicate planned visits', function () {
    $fixture = e2Fixture();
    $plan = e2VisitPlan($fixture, [
        'rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=3',
        'starts_on' => '2026-08-04',
    ]);
    $generator = app(VisitPlanGenerator::class);

    expect($generator->materialize($plan, '2026-08-01', '2026-08-31'))->toBe(3)
        ->and($generator->materialize($plan, '2026-08-01', '2026-08-31'))->toBe(0)
        ->and(PlannedVisit::query()->where('visit_plan_id', $plan->id)->count())->toBe(3);
});

test('cancelled occurrence is not resurrected by re materialization', function () {
    $fixture = e2Fixture();
    $plan = e2VisitPlan($fixture, [
        'rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=2',
        'starts_on' => '2026-08-04',
    ]);
    $generator = app(VisitPlanGenerator::class);

    $generator->materialize($plan, '2026-08-01', '2026-08-31');
    $cancelled = PlannedVisit::query()
        ->where('visit_plan_id', $plan->id)
        ->whereDate('scheduled_date', '2026-08-04')
        ->firstOrFail();

    $generator->cancelOccurrence($cancelled, 'Patient unavailable', $fixture['actor']);
    $generator->materialize($plan, '2026-08-01', '2026-08-31');

    expect($cancelled->refresh()->status)->toBe(PlannedVisit::STATUS_CANCELLED)
        ->and($cancelled->cancellation_reason)->toBe('Patient unavailable')
        ->and(PlannedVisit::query()->where('visit_plan_id', $plan->id)->count())->toBe(2)
        ->and(e2AuditRows($fixture['tenant']->id, 'planned_visit.cancelled'))->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($fixture['tenant']->id)['ok'])->toBeTrue();
});

test('planned visit tables are tenant isolated fail closed and schemas are present', function () {
    $alpha = e2Fixture('alpha');
    $alphaPlan = e2VisitPlan($alpha, [
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=1',
        'starts_on' => '2026-08-03',
    ]);
    app(VisitPlanGenerator::class)->materialize($alphaPlan, '2026-08-01', '2026-08-10');

    $beta = e2Fixture('beta');
    $betaPlan = e2VisitPlan($beta, [
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=1',
        'starts_on' => '2026-08-03',
    ]);

    expect(VisitPlan::query()->whereKey($alphaPlan->id)->exists())->toBeFalse()
        ->and(VisitPlan::query()->whereKey($betaPlan->id)->exists())->toBeTrue()
        ->and(PlannedVisit::query()->where('visit_plan_id', $alphaPlan->id)->exists())->toBeFalse();

    e2Ctx()->forget();

    expect(fn () => VisitPlan::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => PlannedVisit::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(Schema::hasColumns('visit_plans', [
            'id',
            'tenant_id',
            'service_agreement_id',
            'agreement_service_id',
            'rrule',
            'timezone',
            'window_start_time',
            'window_end_time',
            'duration_minutes',
            'starts_on',
            'ends_on',
            'active',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('planned_visits', [
            'id',
            'tenant_id',
            'visit_plan_id',
            'patient_id',
            'scheduled_date',
            'window_start_at',
            'window_end_at',
            'duration_minutes',
            'required_qualification',
            'status',
            'assigned_resource_id',
            'cancellation_reason',
        ]))->toBeTrue();
});

test('nursing materialize visits command is horizon based and idempotent', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');
    $fixture = e2Fixture();
    e2VisitPlan($fixture);

    $this->artisan('nursing:materialize-visits', ['--weeks' => 2])
        ->expectsOutput('Planned visits materialized: 6.')
        ->assertSuccessful();

    $this->artisan('nursing:materialize-visits', ['--weeks' => 2])
        ->expectsOutput('Planned visits materialized: 0.')
        ->assertSuccessful();

    expect(PlannedVisit::query()->count())->toBe(6);

    Carbon::setTestNow();
});
