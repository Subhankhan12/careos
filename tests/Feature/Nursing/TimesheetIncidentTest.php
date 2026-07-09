<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Services\AuditService;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\Incident;
use Modules\Nursing\Models\NurseSyncAction;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\TimesheetLine;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Services\NurseSyncService;
use Modules\Nursing\Services\TimesheetService;
use Modules\Nursing\Services\VisitService;
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
use Modules\Scheduling\Models\Service;

uses(RefreshDatabase::class);

function e8Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Nursing',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function e8Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function e8Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function e8User(Tenant $tenant, string $role = 'coordinator'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => e8Role($role)->id,
    ]);

    return $user;
}

function e8Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function e8Staff(Branch $branch, User $user, string $name = 'Nora Nurse'): StaffProfile
{
    [$first, $last] = explode(' ', $name, 2);

    return StaffProfile::query()->create([
        'user_id' => $user->id,
        'first_name' => $first,
        'last_name' => $last,
        'display_name' => $name,
        'profession' => 'nurse',
        'primary_branch_id' => $branch->id,
    ]);
}

function e8Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Actual',
        'last_name' => 'Patient',
        'date_of_birth' => '1942-02-02',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function e8Service(string $code): Service
{
    return Service::query()->create([
        'name' => 'Home nursing',
        'code' => $code,
        'category' => 'home-care',
        'default_duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => false,
        'active' => true,
    ]);
}

/**
 * @return array{tenant: Tenant, approver: User, nurse: User, branch: Branch, staff: StaffProfile, resource: BookableResource, patient: Patient, plannedVisit: PlannedVisit, visit: Visit}
 */
function e8Fixture(string $slug = 'alpha', string $clientUuid = 'e8-offline-visit', int $plannedMinutes = 60): array
{
    $tenant = e8Tenant($slug);
    e8Ctx()->set($tenant);
    $approver = e8User($tenant, 'coordinator');
    $nurse = e8User($tenant, 'nurse');
    $branch = e8Branch(strtoupper(substr($slug, 0, 4)));
    $staff = e8Staff($branch, $nurse, ucfirst($slug).' Nurse');
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => ucfirst($slug).' Nurse Resource',
        'staff_profile_id' => $staff->id,
        'branch_id' => $branch->id,
    ]);
    $patient = e8Patient(['first_name' => ucfirst($slug)]);
    $service = e8Service(strtoupper($slug).'-HOME');
    $agreement = ServiceAgreement::query()->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'funding_type' => ServiceAgreement::FUNDING_OTHER,
        'starts_on' => '2026-08-01',
        'status' => ServiceAgreement::STATUS_ACTIVE,
        'created_by' => $approver->id,
    ]);
    $agreementService = AgreementService::query()->create([
        'service_agreement_id' => $agreement->id,
        'service_id' => $service->id,
        'planned_frequency_text' => 'As documented',
        'required_qualification' => 'RN',
        'duration_minutes' => $plannedMinutes,
    ]);
    $plan = VisitPlan::query()->create([
        'service_agreement_id' => $agreement->id,
        'agreement_service_id' => $agreementService->id,
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=1',
        'timezone' => 'Europe/Zurich',
        'window_start_time' => '09:00:00',
        'window_end_time' => '11:00:00',
        'duration_minutes' => $plannedMinutes,
        'starts_on' => '2026-08-03',
        'active' => true,
    ]);
    $plannedVisit = PlannedVisit::query()->create([
        'visit_plan_id' => $plan->id,
        'patient_id' => $patient->id,
        'scheduled_date' => '2026-08-03',
        'window_start_at' => '2026-08-03 07:00:00',
        'window_end_at' => '2026-08-03 08:00:00',
        'duration_minutes' => $plannedMinutes,
        'required_qualification' => 'RN',
        'status' => PlannedVisit::STATUS_ASSIGNED,
        'assigned_resource_id' => $resource->id,
        'assigned_at' => '2026-08-01 12:00:00',
        'assigned_by' => $approver->id,
        'location_latitude' => '47.376900',
        'location_longitude' => '8.541700',
    ]);
    $visit = app(VisitService::class)->createFromPlannedVisit($plannedVisit, $clientUuid);

    return [
        'tenant' => $tenant,
        'approver' => $approver,
        'nurse' => $nurse,
        'branch' => $branch,
        'staff' => $staff,
        'resource' => $resource,
        'patient' => $patient,
        'plannedVisit' => $plannedVisit,
        'visit' => $visit,
    ];
}

function e8Token(User $user): string
{
    return $user->createToken('nurse-device', ['nurse:day-pack'])->plainTextToken;
}

function e8AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

function e8CheckIn(array $fixture, string $at, bool $manual = false): void
{
    app(VisitService::class)->checkIn(
        $fixture['visit']->refresh(),
        $fixture['nurse'],
        $manual ? 'GPS unavailable for proof' : [
            'latitude' => 47.3769,
            'longitude' => 8.5417,
            'accuracy_meters' => 8,
        ],
        $at,
    );
}

function e8CheckOut(array $fixture, string $at, bool $manual = false): void
{
    app(VisitService::class)->checkOut(
        $fixture['visit']->refresh(),
        $fixture['nurse'],
        $manual ? 'GPS unavailable for proof' : [
            'latitude' => 47.37691,
            'longitude' => 8.54171,
            'accuracy_meters' => 8,
        ],
        $at,
    );
}

test('timesheet minutes derive from actual check in and check out not the plan', function () {
    $fixture = e8Fixture(plannedMinutes: 60);
    e8CheckIn($fixture, '2026-08-03 07:05:00');
    e8CheckOut($fixture, '2026-08-03 08:25:00');

    $lines = app(TimesheetService::class)->generateFromVisits(
        $fixture['resource'],
        '2026-08-03',
        '2026-08-03',
    );
    $line = $lines->first();

    expect($line)->toBeInstanceOf(TimesheetLine::class)
        ->and($line->minutes)->toBe(80)
        ->and($line->minutes)->not->toBe($fixture['plannedVisit']->duration_minutes)
        ->and($line->started_at->toDateTimeString())->toBe('2026-08-03 07:05:00')
        ->and($line->ended_at?->toDateTimeString())->toBe('2026-08-03 08:25:00');
});

test('missing check out is flagged without guessing an end time', function () {
    $fixture = e8Fixture('missing');
    e8CheckIn($fixture, '2026-08-03 07:05:00');

    $line = app(TimesheetService::class)
        ->generateFromVisits($fixture['resource'], '2026-08-03', '2026-08-03')
        ->firstOrFail();

    expect($line->discrepancy_flags)->toContain(TimesheetLine::FLAG_MISSING_CHECK_OUT)
        ->and($line->ended_at)->toBeNull()
        ->and($line->minutes)->toBeNull();
});

test('approved timesheet lines are immutable while drafts remain editable', function () {
    $fixture = e8Fixture('lock');
    e8CheckIn($fixture, '2026-08-03 07:00:00');
    e8CheckOut($fixture, '2026-08-03 08:00:00');
    $service = app(TimesheetService::class);
    $line = $service->generateFromVisits($fixture['resource'], '2026-08-03', '2026-08-03')->firstOrFail();
    $nurseOnly = $fixture['nurse'];

    $line->forceFill(['travel_minutes' => 12])->save();

    expect($line->refresh()->travel_minutes)->toBe(12)
        ->and(fn () => $service->approve($line, $nurseOnly))->toThrow(AuthorizationException::class);

    $approved = $service->approve($line->refresh(), $fixture['approver']);

    expect($approved->status)->toBe(TimesheetLine::STATUS_APPROVED)
        ->and(fn () => $approved->forceFill(['travel_minutes' => 20])->save())->toThrow(LogicException::class)
        ->and(fn () => $approved->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::update('UPDATE timesheet_lines SET minutes = ? WHERE id = ?', [1, $approved->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::delete('DELETE FROM timesheet_lines WHERE id = ?', [$approved->id]))
        ->toThrow(QueryException::class);
});

test('manual location and duration deviations are flagged for the approver', function () {
    $fixture = e8Fixture('flags', plannedMinutes: 60);
    e8CheckIn($fixture, '2026-08-03 07:00:00', manual: true);
    e8CheckOut($fixture, '2026-08-03 08:40:00');

    $line = app(TimesheetService::class)
        ->generateFromVisits($fixture['resource'], '2026-08-03', '2026-08-03')
        ->firstOrFail();

    expect($line->minutes)->toBe(100)
        ->and($line->discrepancy_flags)->toContain(TimesheetLine::FLAG_MANUAL_LOCATION)
        ->and($line->discrepancy_flags)->toContain(TimesheetLine::FLAG_DURATION_DEVIATION);
});

test('incidents can be reported offline idempotently and preserve reporter selected severity', function () {
    $fixture = e8Fixture('incident');
    $batch = [[
        'client_uuid' => 'incident-action-1',
        'type' => 'incident_report',
        'sequence' => 1,
        'device_timestamp' => '2026-08-03T07:15:00Z',
        'payload' => [
            'visit_id' => $fixture['visit']->id,
            'nurse_resource_id' => $fixture['resource']->id,
            'occurred_at' => '2026-08-03T07:14:00Z',
            'category' => Incident::CATEGORY_SAFETY,
            'severity' => Incident::SEVERITY_HIGH,
            'description' => 'Loose rug observed near the front door.',
        ],
    ]];

    $this->withToken(e8Token($fixture['nurse']))
        ->postJson('/api/nurse/sync', ['actions' => $batch])
        ->assertOk()
        ->assertJsonPath('results.0.code', NurseSyncService::CODE_ACCEPTED);

    $firstIncidentId = Incident::query()->firstOrFail()->id;
    $this->withToken(e8Token($fixture['nurse']))
        ->postJson('/api/nurse/sync', ['actions' => $batch])
        ->assertOk()
        ->assertJsonPath('results.0.payload.incident_id', $firstIncidentId);

    $incident = Incident::query()->firstOrFail();
    $audit = e8AuditRows($fixture['tenant']->id, 'incident.reported')->first();
    $context = json_decode($audit->context, true, 512, JSON_THROW_ON_ERROR);

    expect(Incident::query()->count())->toBe(1)
        ->and(NurseSyncAction::query()->count())->toBe(1)
        ->and($incident->severity)->toBe(Incident::SEVERITY_HIGH)
        ->and($incident->description)->toBe('Loose rug observed near the front door.')
        ->and($audit->patient_id)->toBe($fixture['patient']->id)
        ->and($context['severity_source'])->toBe('reporter_selected')
        ->and($context['system_assessed_severity'])->toBeFalse()
        ->and(app(AuditService::class)->verifyChain($fixture['tenant']->id)['ok'])->toBeTrue();
});

test('incidents are tenant isolated and cannot be reported against another tenant visit', function () {
    $alpha = e8Fixture('alpha');
    $beta = e8Fixture('beta');

    e8Ctx()->set($beta['tenant']);

    $this->withToken(e8Token($beta['nurse']))
        ->postJson('/api/nurse/sync', ['actions' => [[
            'client_uuid' => 'incident-cross-tenant',
            'type' => 'incident_report',
            'sequence' => 1,
            'device_timestamp' => '2026-08-03T07:15:00Z',
            'payload' => [
                'visit_id' => $alpha['visit']->id,
                'nurse_resource_id' => $beta['resource']->id,
                'occurred_at' => '2026-08-03T07:14:00Z',
                'category' => Incident::CATEGORY_OTHER,
                'severity' => Incident::SEVERITY_LOW,
                'description' => 'Should not cross tenants.',
            ],
        ]]])
        ->assertOk()
        ->assertJsonPath('results.0.status', NurseSyncAction::STATUS_REJECTED)
        ->assertJsonPath('results.0.code', NurseSyncService::CODE_VISIT_NOT_FOUND);

    expect(Incident::query()->count())->toBe(0);
});

test('incident and timesheet schemas expose expected columns and triggers', function () {
    expect(Schema::hasColumns('incidents', [
        'id',
        'tenant_id',
        'visit_id',
        'patient_id',
        'reported_by_resource_id',
        'occurred_at',
        'category',
        'description',
        'severity',
        'status',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('timesheet_lines', [
            'id',
            'tenant_id',
            'resource_id',
            'visit_id',
            'date',
            'started_at',
            'ended_at',
            'minutes',
            'travel_minutes',
            'discrepancy_flags',
            'status',
            'approved_by',
            'approved_at',
        ]))->toBeTrue()
        ->and(DB::select("SHOW TRIGGERS WHERE `Table` = 'timesheet_lines'"))->toHaveCount(2);
});
