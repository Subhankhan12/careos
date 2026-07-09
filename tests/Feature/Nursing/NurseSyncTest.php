<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Clinical\Models\Vital;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\NurseSyncAction;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\SyncConflict;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitEvent;
use Modules\Nursing\Models\VisitObservation;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Services\NurseSyncService;
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

function e6Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Nursing',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function e6Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function e6Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function e6User(Tenant $tenant, string $role = 'nurse'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => e6Role($role)->id,
    ]);

    return $user;
}

function e6Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function e6Staff(Branch $branch, User $user, string $name = 'Nora Nurse'): StaffProfile
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

function e6Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Sync',
        'last_name' => 'Patient',
        'date_of_birth' => '1940-01-02',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function e6Service(string $code): Service
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
 * @return array{tenant: Tenant, user: User, branch: Branch, staff: StaffProfile, resource: BookableResource, patient: Patient, plannedVisit: PlannedVisit}
 */
function e6Fixture(string $slug = 'alpha'): array
{
    $tenant = e6Tenant($slug);
    e6Ctx()->set($tenant);
    $user = e6User($tenant);
    $branch = e6Branch(strtoupper(substr($slug, 0, 4)));
    $staff = e6Staff($branch, $user, ucfirst($slug).' Nurse');
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => ucfirst($slug).' Nurse Resource',
        'staff_profile_id' => $staff->id,
        'branch_id' => $branch->id,
    ]);
    $patient = e6Patient(['first_name' => ucfirst($slug)]);
    $service = e6Service(strtoupper($slug).'-HOME');
    $agreement = ServiceAgreement::query()->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'funding_type' => ServiceAgreement::FUNDING_OTHER,
        'starts_on' => '2026-08-01',
        'status' => ServiceAgreement::STATUS_ACTIVE,
        'created_by' => $user->id,
    ]);
    $agreementService = AgreementService::query()->create([
        'service_agreement_id' => $agreement->id,
        'service_id' => $service->id,
        'planned_frequency_text' => 'As documented',
        'required_qualification' => 'RN',
        'duration_minutes' => 60,
    ]);
    $plan = VisitPlan::query()->create([
        'service_agreement_id' => $agreement->id,
        'agreement_service_id' => $agreementService->id,
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=1',
        'timezone' => 'Europe/Zurich',
        'window_start_time' => '09:00:00',
        'window_end_time' => '11:00:00',
        'duration_minutes' => 60,
        'starts_on' => '2026-08-03',
        'active' => true,
    ]);
    $plannedVisit = PlannedVisit::query()->create([
        'visit_plan_id' => $plan->id,
        'patient_id' => $patient->id,
        'scheduled_date' => '2026-08-03',
        'window_start_at' => '2026-08-03 07:00:00',
        'window_end_at' => '2026-08-03 08:00:00',
        'duration_minutes' => 60,
        'required_qualification' => 'RN',
        'status' => PlannedVisit::STATUS_ASSIGNED,
        'assigned_resource_id' => $resource->id,
        'assigned_at' => '2026-08-01 12:00:00',
        'assigned_by' => $user->id,
    ]);

    return [
        'tenant' => $tenant,
        'user' => $user,
        'branch' => $branch,
        'staff' => $staff,
        'resource' => $resource,
        'patient' => $patient,
        'plannedVisit' => $plannedVisit,
    ];
}

function e6Token(User $user): string
{
    return $user->createToken('nurse-device', ['nurse:day-pack'])->plainTextToken;
}

function e6AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('sync replay is idempotent and does not double record visits or vitals', function () {
    $fixture = e6Fixture();
    $batch = [
        [
            'client_uuid' => 'action-check-in-1',
            'type' => 'check_in',
            'sequence' => 1,
            'device_timestamp' => '2026-08-03T07:05:00Z',
            'payload' => [
                'planned_visit_id' => $fixture['plannedVisit']->id,
                'client_visit_uuid' => 'offline-visit-uuid-1',
                'nurse_resource_id' => $fixture['resource']->id,
                'manual_reason' => 'GPS unavailable',
            ],
        ],
        [
            'client_uuid' => 'action-vitals-1',
            'type' => 'vitals',
            'sequence' => 2,
            'device_timestamp' => '2026-08-03T07:10:00Z',
            'payload' => [
                'client_visit_uuid' => 'offline-visit-uuid-1',
                'nurse_resource_id' => $fixture['resource']->id,
                'heart_rate' => 72,
                'spo2' => 98,
            ],
        ],
    ];

    $this->withToken(e6Token($fixture['user']))
        ->postJson('/api/nurse/sync', ['actions' => $batch])
        ->assertOk()
        ->assertJsonPath('results.0.code', NurseSyncService::CODE_ACCEPTED)
        ->assertJsonPath('results.1.code', NurseSyncService::CODE_ACCEPTED);

    $this->withToken(e6Token($fixture['user']))
        ->postJson('/api/nurse/sync', ['actions' => $batch])
        ->assertOk()
        ->assertJsonPath('results.0.payload.visit_id', Visit::query()->firstOrFail()->id)
        ->assertJsonPath('results.1.payload.vital_id', Vital::query()->firstOrFail()->id);

    expect(Visit::query()->count())->toBe(1)
        ->and(VisitEvent::query()->where('type', VisitEvent::TYPE_CHECK_IN)->count())->toBe(1)
        ->and(Vital::query()->count())->toBe(1)
        ->and(NurseSyncAction::query()->count())->toBe(2)
        ->and(e6AuditRows($fixture['tenant']->id, 'nurse_sync.accepted'))->toHaveCount(2);
});

test('server schedule changes reject schedule actions with explanatory code', function () {
    $fixture = e6Fixture();
    $fixture['plannedVisit']->forceFill([
        'status' => PlannedVisit::STATUS_CANCELLED,
        'cancellation_reason' => 'Cancelled by dispatch',
    ])->save();

    $this->withToken(e6Token($fixture['user']))
        ->postJson('/api/nurse/sync', ['actions' => [[
            'client_uuid' => 'action-check-in-cancelled',
            'type' => 'check_in',
            'sequence' => 1,
            'device_timestamp' => '2026-08-03T07:05:00Z',
            'payload' => [
                'planned_visit_id' => $fixture['plannedVisit']->id,
                'client_visit_uuid' => 'offline-cancelled-visit',
                'nurse_resource_id' => $fixture['resource']->id,
                'manual_reason' => 'GPS unavailable',
            ],
        ]]])
        ->assertOk()
        ->assertJsonPath('results.0.status', NurseSyncAction::STATUS_REJECTED)
        ->assertJsonPath('results.0.code', NurseSyncService::CODE_SCHEDULE_CHANGED);

    expect(Visit::query()->count())->toBe(0)
        ->and(VisitEvent::query()->count())->toBe(0)
        ->and(e6AuditRows($fixture['tenant']->id, 'nurse_sync.rejected'))->toHaveCount(1);
});

test('client note content wins and is persisted flagged when server schedule changed', function () {
    $fixture = e6Fixture();
    $visit = app(VisitService::class)->createFromPlannedVisit($fixture['plannedVisit'], 'offline-note-visit');
    $fixture['plannedVisit']->forceFill([
        'status' => PlannedVisit::STATUS_CANCELLED,
        'cancellation_reason' => 'Cancelled after nurse arrived',
    ])->save();

    $this->withToken(e6Token($fixture['user']))
        ->postJson('/api/nurse/sync', ['actions' => [[
            'client_uuid' => 'action-note-1',
            'type' => 'note',
            'sequence' => 1,
            'device_timestamp' => '2026-08-03T07:20:00Z',
            'payload' => [
                'visit_id' => $visit->id,
                'nurse_resource_id' => $fixture['resource']->id,
                'note_text' => 'Patient was seen at the door before dispatch cancellation was received.',
            ],
        ]]])
        ->assertOk()
        ->assertJsonPath('results.0.status', NurseSyncAction::STATUS_ACCEPTED)
        ->assertJsonPath('results.0.code', NurseSyncService::CODE_ACCEPTED_WITH_FLAG);

    $observation = VisitObservation::query()->firstOrFail();

    expect($observation->note_text)->toBe('Patient was seen at the door before dispatch cancellation was received.')
        ->and($observation->flagged)->toBeTrue()
        ->and($observation->flag_reason)->toBe('server_schedule_changed_client_note_preserved')
        ->and(e6AuditRows($fixture['tenant']->id, 'nurse_sync.accepted'))->toHaveCount(1);
});

test('ambiguous offline conflict creates a sync conflict for human review', function () {
    $fixture = e6Fixture();
    $visit = app(VisitService::class)->createFromPlannedVisit($fixture['plannedVisit'], 'offline-signature-visit');

    $this->withToken(e6Token($fixture['user']))
        ->postJson('/api/nurse/sync', ['actions' => [[
            'client_uuid' => 'action-signature-1',
            'type' => 'signature',
            'sequence' => 1,
            'device_timestamp' => '2026-08-03T07:25:00Z',
            'payload' => [
                'visit_id' => $visit->id,
                'nurse_resource_id' => $fixture['resource']->id,
                'signature_hash' => 'client-side-signature-hash',
            ],
        ]]])
        ->assertOk()
        ->assertJsonPath('results.0.status', NurseSyncAction::STATUS_CONFLICT)
        ->assertJsonPath('results.0.code', NurseSyncService::CODE_AMBIGUOUS_CONFLICT);

    $conflict = SyncConflict::query()->firstOrFail();

    expect($conflict->status)->toBe(SyncConflict::STATUS_OPEN)
        ->and($conflict->reason)->toBe(NurseSyncService::CODE_AMBIGUOUS_CONFLICT)
        ->and($conflict->visit_id)->toBe($visit->id)
        ->and(e6AuditRows($fixture['tenant']->id, 'nurse_sync.conflict'))->toHaveCount(1);
});

test('sync is tenant isolated and cannot attach another tenants visit', function () {
    $alpha = e6Fixture('alpha');
    $alphaVisit = app(VisitService::class)->createFromPlannedVisit($alpha['plannedVisit'], 'alpha-visit');
    $beta = e6Fixture('beta');

    e6Ctx()->set($beta['tenant']);

    $this->withToken(e6Token($beta['user']))
        ->postJson('/api/nurse/sync', ['actions' => [[
            'client_uuid' => 'action-cross-tenant-note',
            'type' => 'note',
            'sequence' => 1,
            'device_timestamp' => '2026-08-03T07:20:00Z',
            'payload' => [
                'visit_id' => $alphaVisit->id,
                'nurse_resource_id' => $beta['resource']->id,
                'note_text' => 'Should not cross tenant boundaries.',
            ],
        ]]])
        ->assertOk()
        ->assertJsonPath('results.0.status', NurseSyncAction::STATUS_REJECTED)
        ->assertJsonPath('results.0.code', NurseSyncService::CODE_VISIT_NOT_FOUND);

    expect(VisitObservation::query()->count())->toBe(0)
        ->and(NurseSyncAction::query()->count())->toBe(1);
});
