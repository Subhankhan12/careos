<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Services\AuditService;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitEvent;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Services\ServiceAgreementService;
use Modules\Nursing\Services\VisitService;
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

function e4Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Nursing',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function e4Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function e4Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function e4User(Tenant $tenant, string $role = 'coordinator'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => e4Role($role)->id,
    ]);

    return $user;
}

function e4Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function e4Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Proof',
        'last_name' => 'Patient',
        'date_of_birth' => '1945-05-05',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function e4SchedulingService(array $overrides = []): Service
{
    return Service::query()->create([
        'name' => 'Proof visit',
        'code' => 'PROOF-VISIT',
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
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, agreement: ServiceAgreement, agreementService: AgreementService, plan: VisitPlan, resource: BookableResource, plannedVisit: PlannedVisit, visit: Visit}
 */
function e4Fixture(string $slug = 'alpha', string $clientUuid = 'offline-visit-1'): array
{
    $tenant = e4Tenant($slug);
    e4Ctx()->set($tenant);
    $actor = e4User($tenant);
    $branch = e4Branch(strtoupper(substr($slug, 0, 4)));
    $patient = e4Patient(['first_name' => ucfirst($slug)]);
    $service = e4SchedulingService(['code' => strtoupper($slug).'-PROOF']);

    $agreement = app(ServiceAgreementService::class)->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'funding_type' => ServiceAgreement::FUNDING_OTHER,
        'starts_on' => '2026-08-01',
    ], [[
        'service_id' => $service->id,
        'planned_frequency_text' => 'Proof visits',
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
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => ucfirst($slug).' Nurse',
        'branch_id' => $branch->id,
    ]);
    $plannedVisit = PlannedVisit::query()->create([
        'visit_plan_id' => $plan->id,
        'patient_id' => $patient->id,
        'scheduled_date' => '2026-08-03',
        'window_start_at' => '2026-08-03 09:00:00',
        'window_end_at' => '2026-08-03 10:00:00',
        'duration_minutes' => 60,
        'required_qualification' => 'RN',
        'status' => PlannedVisit::STATUS_ASSIGNED,
        'assigned_resource_id' => $resource->id,
        'assigned_at' => '2026-08-01 12:00:00',
        'assigned_by' => $actor->id,
        'location_latitude' => '47.376900',
        'location_longitude' => '8.541700',
    ]);
    $visit = app(VisitService::class)->createFromPlannedVisit($plannedVisit, $clientUuid);

    return [
        'tenant' => $tenant,
        'actor' => $actor,
        'branch' => $branch,
        'patient' => $patient,
        'agreement' => $agreement,
        'agreementService' => $agreement->agreementServices()->firstOrFail(),
        'plan' => $plan,
        'resource' => $resource,
        'plannedVisit' => $plannedVisit,
        'visit' => $visit,
    ];
}

function e4AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('check-in stores a spatial point with accuracy and audits the event', function () {
    $fixture = e4Fixture();

    $event = app(VisitService::class)->checkIn($fixture['visit'], $fixture['actor'], [
        'latitude' => 47.3769,
        'longitude' => 8.5417,
        'accuracy_meters' => 8.5,
    ], '2026-08-03 09:02:00');

    $row = DB::selectOne('SELECT ST_AsText(location) AS wkt, accuracy_meters FROM visit_events WHERE id = ?', [$event->id]);
    $auditRows = e4AuditRows($fixture['tenant']->id, 'visit.check_in');

    expect($fixture['visit']->refresh()->status)->toBe(Visit::STATUS_IN_PROGRESS)
        ->and($row->wkt)->toContain('POINT')
        ->and((float) $row->accuracy_meters)->toBe(8.5)
        ->and($event->location_source)->toBe(VisitEvent::SOURCE_GPS)
        ->and($auditRows)->toHaveCount(1)
        ->and($auditRows[0]->patient_id)->toBe($fixture['patient']->id)
        ->and(app(AuditService::class)->verifyChain($fixture['tenant']->id)['ok'])->toBeTrue();
});

test('GPS-less check-in requires a non-empty manual reason and records manual source', function () {
    $fixture = e4Fixture();
    $service = app(VisitService::class);

    expect(fn () => $service->checkIn($fixture['visit'], $fixture['actor'], '', '2026-08-03 09:00:00'))
        ->toThrow(InvalidArgumentException::class)
        ->and(VisitEvent::query()->count())->toBe(0);

    $event = $service->checkIn($fixture['visit'], $fixture['actor'], 'GPS denied by device', '2026-08-03 09:03:00');

    expect($event->location_source)->toBe(VisitEvent::SOURCE_MANUAL)
        ->and($event->manual_reason)->toBe('GPS denied by device')
        ->and($event->distance_meters)->toBeNull();
});

test('check-out requires a prior check-in and only one check-in and check-out can be recorded', function () {
    $fixture = e4Fixture();
    $service = app(VisitService::class);

    expect(fn () => $service->checkOut($fixture['visit'], $fixture['actor'], 'GPS unavailable', '2026-08-03 10:00:00'))
        ->toThrow(InvalidArgumentException::class);

    $service->checkIn($fixture['visit'], $fixture['actor'], 'GPS unavailable', '2026-08-03 09:00:00');

    expect(fn () => $service->checkIn($fixture['visit']->refresh(), $fixture['actor'], 'Duplicate', '2026-08-03 09:10:00'))
        ->toThrow(InvalidArgumentException::class);

    $service->checkOut($fixture['visit']->refresh(), $fixture['actor'], [
        'latitude' => 47.37691,
        'longitude' => 8.54171,
        'accuracy_meters' => 9,
    ], '2026-08-03 10:00:00');

    expect($fixture['visit']->refresh()->status)->toBe(Visit::STATUS_COMPLETED)
        ->and(VisitEvent::query()->count())->toBe(2);
});

test('visit events are append-only at the database level', function () {
    $fixture = e4Fixture();
    $event = app(VisitService::class)->checkIn($fixture['visit'], $fixture['actor'], 'GPS unavailable', '2026-08-03 09:00:00');

    expect(fn () => DB::update('UPDATE visit_events SET manual_reason = ? WHERE id = ?', ['Changed', $event->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::delete('DELETE FROM visit_events WHERE id = ?', [$event->id]))
        ->toThrow(QueryException::class);
});

test('geofence distance is computed and distant check-in is flagged but not blocked', function () {
    $fixture = e4Fixture();

    $event = app(VisitService::class)->checkIn($fixture['visit'], $fixture['actor'], [
        'latitude' => 46.2044,
        'longitude' => 6.1432,
        'accuracy_meters' => 12,
    ], '2026-08-03 09:00:00');
    $audit = e4AuditRows($fixture['tenant']->id, 'visit.check_in')->first();
    $context = json_decode($audit->context, true, 512, JSON_THROW_ON_ERROR);

    expect((float) $event->distance_meters)->toBeGreaterThan(200000)
        ->and($fixture['visit']->refresh()->status)->toBe(Visit::STATUS_IN_PROGRESS)
        ->and($context['geofence_flagged'])->toBeTrue();
});

test('visits and visit events are tenant isolated fail closed and client UUID is unique per tenant', function () {
    $alpha = e4Fixture('alpha', 'same-offline-id');
    $alphaVisit = $alpha['visit'];
    $beta = e4Fixture('beta', 'same-offline-id');

    expect(Visit::query()->whereKey($alphaVisit->id)->exists())->toBeFalse()
        ->and(Visit::query()->whereKey($beta['visit']->id)->exists())->toBeTrue();

    e4Ctx()->set($alpha['tenant']);

    expect(fn () => app(VisitService::class)->createFromPlannedVisit($alpha['plannedVisit'], 'same-offline-id'))
        ->toThrow(QueryException::class);

    e4Ctx()->forget();

    expect(fn () => Visit::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => VisitEvent::query()->count())->toThrow(TenantContextMissingException::class);
});

test('visit proof schemas include spatial index append-only triggers and privacy notice setting', function () {
    $fixture = e4Fixture();

    $spatial = DB::selectOne(
        "SELECT INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visit_events' AND INDEX_NAME = 'visit_events_location_spatial'"
    );

    expect(Schema::hasColumns('visits', [
        'id',
        'tenant_id',
        'planned_visit_id',
        'patient_id',
        'resource_id',
        'branch_id',
        'scheduled_start_at',
        'checked_in_at',
        'checked_out_at',
        'status',
        'client_visit_uuid',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('visit_events', [
            'id',
            'tenant_id',
            'visit_id',
            'type',
            'occurred_at',
            'received_at',
            'location',
            'accuracy_meters',
            'location_source',
            'manual_reason',
            'distance_meters',
            'recorded_by',
        ]))->toBeTrue()
        ->and($spatial->INDEX_TYPE)->toBe('SPATIAL')
        ->and(DB::select("SHOW TRIGGERS WHERE `Table` = 'visit_events'"))->toHaveCount(2)
        ->and(app(SettingsService::class)->get(VisitService::PRIVACY_NOTICE_SETTING_KEY))
        ->toBe(VisitService::PRIVACY_NOTICE_TEXT);
});
