<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\NurseSyncAction;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitAttachment;
use Modules\Nursing\Models\VisitNote;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Models\VisitTask;
use Modules\Nursing\Models\VisitVital;
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

function e7Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Nursing',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function e7Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function e7Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function e7User(Tenant $tenant, string $role = 'nurse'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => e7Role($role)->id,
    ]);

    return $user;
}

function e7Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function e7Staff(Branch $branch, User $user, string $name = 'Nora Nurse'): StaffProfile
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

function e7Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Execute',
        'last_name' => 'Patient',
        'date_of_birth' => '1940-01-02',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function e7Service(string $code): Service
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
 * @return array{tenant: Tenant, user: User, branch: Branch, staff: StaffProfile, resource: BookableResource, patient: Patient, agreementService: AgreementService, plannedVisit: PlannedVisit, visit: Visit, task: VisitTask}
 */
function e7Fixture(string $slug = 'alpha'): array
{
    $tenant = e7Tenant($slug);
    e7Ctx()->set($tenant);
    $user = e7User($tenant);
    $branch = e7Branch(strtoupper(substr($slug, 0, 4)));
    $staff = e7Staff($branch, $user, ucfirst($slug).' Nurse');
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => ucfirst($slug).' Nurse Resource',
        'staff_profile_id' => $staff->id,
        'branch_id' => $branch->id,
    ]);
    $patient = e7Patient(['first_name' => ucfirst($slug)]);
    $service = e7Service(strtoupper($slug).'-HOME');
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
    $visit = app(VisitService::class)->createFromPlannedVisit($plannedVisit, $slug.'-offline-visit');
    $task = VisitTask::query()->create([
        'visit_id' => $visit->id,
        'agreement_service_id' => $agreementService->id,
        'description' => 'Support morning care',
    ]);

    return [
        'tenant' => $tenant,
        'user' => $user,
        'branch' => $branch,
        'staff' => $staff,
        'resource' => $resource,
        'patient' => $patient,
        'agreementService' => $agreementService,
        'plannedVisit' => $plannedVisit,
        'visit' => $visit,
        'task' => $task,
    ];
}

function e7Token(User $user): string
{
    return $user->createToken('nurse-device', ['nurse:day-pack'])->plainTextToken;
}

function e7AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('offline visit execution actions are idempotent and audited', function () {
    Storage::fake('local');
    $fixture = e7Fixture();
    $batch = [
        [
            'client_uuid' => 'e7-task-done',
            'type' => 'visit_task_done',
            'sequence' => 1,
            'device_timestamp' => '2026-08-03T07:10:00Z',
            'payload' => [
                'visit_id' => $fixture['visit']->id,
                'visit_task_id' => $fixture['task']->id,
                'nurse_resource_id' => $fixture['resource']->id,
            ],
        ],
        [
            'client_uuid' => 'e7-vitals',
            'type' => 'visit_vitals',
            'sequence' => 2,
            'device_timestamp' => '2026-08-03T07:20:00Z',
            'payload' => [
                'visit_id' => $fixture['visit']->id,
                'nurse_resource_id' => $fixture['resource']->id,
                'heart_rate' => 72,
                'spo2' => 98,
            ],
        ],
        [
            'client_uuid' => 'e7-note',
            'type' => 'visit_note',
            'sequence' => 3,
            'device_timestamp' => '2026-08-03T07:30:00Z',
            'payload' => [
                'visit_id' => $fixture['visit']->id,
                'nurse_resource_id' => $fixture['resource']->id,
                'body' => 'Patient reported breakfast was completed.',
            ],
        ],
        [
            'client_uuid' => 'e7-photo',
            'type' => 'visit_photo',
            'sequence' => 4,
            'device_timestamp' => '2026-08-03T07:40:00Z',
            'payload' => [
                'visit_id' => $fixture['visit']->id,
                'nurse_resource_id' => $fixture['resource']->id,
                'mime_type' => 'image/png',
                'data' => base64_encode('photo-bytes'),
            ],
        ],
        [
            'client_uuid' => 'e7-signature',
            'type' => 'visit_signature',
            'sequence' => 5,
            'device_timestamp' => '2026-08-03T07:50:00Z',
            'payload' => [
                'visit_id' => $fixture['visit']->id,
                'nurse_resource_id' => $fixture['resource']->id,
                'mime_type' => 'image/png',
                'data' => base64_encode('signature-bytes'),
            ],
        ],
    ];

    $this->withToken(e7Token($fixture['user']))
        ->postJson('/api/nurse/sync', ['actions' => $batch])
        ->assertOk()
        ->assertJsonPath('results.0.code', NurseSyncService::CODE_ACCEPTED)
        ->assertJsonPath('results.4.code', NurseSyncService::CODE_ACCEPTED);

    $firstNoteId = VisitNote::query()->firstOrFail()->id;
    $this->withToken(e7Token($fixture['user']))
        ->postJson('/api/nurse/sync', ['actions' => $batch])
        ->assertOk()
        ->assertJsonPath('results.2.payload.visit_note_id', $firstNoteId);

    $attachment = VisitAttachment::query()->where('type', VisitAttachment::TYPE_PHOTO)->firstOrFail();

    expect(VisitTask::query()->firstOrFail()->status)->toBe(VisitTask::STATUS_DONE)
        ->and(VisitVital::query()->count())->toBe(1)
        ->and(VisitNote::query()->count())->toBe(1)
        ->and(VisitAttachment::query()->count())->toBe(2)
        ->and(NurseSyncAction::query()->count())->toBe(5)
        ->and($attachment->storage_path)->toStartWith('tenants/'.$fixture['tenant']->id.'/nursing-attachments/'.$fixture['patient']->id.'/'.$fixture['visit']->id.'/')
        ->and($attachment->storage_path)->not->toContain('photo-bytes')
        ->and(Storage::disk('local')->exists($attachment->storage_path))->toBeTrue()
        ->and(e7AuditRows($fixture['tenant']->id, 'nurse_sync.accepted'))->toHaveCount(5);
});

test('visit task not done requires a reason before anything changes', function () {
    $fixture = e7Fixture();

    $this->withToken(e7Token($fixture['user']))
        ->postJson('/api/nurse/sync', ['actions' => [[
            'client_uuid' => 'e7-task-not-done-empty',
            'type' => 'visit_task_not_done',
            'sequence' => 1,
            'device_timestamp' => '2026-08-03T07:10:00Z',
            'payload' => [
                'visit_id' => $fixture['visit']->id,
                'visit_task_id' => $fixture['task']->id,
                'nurse_resource_id' => $fixture['resource']->id,
                'not_done_reason' => '',
            ],
        ]]])
        ->assertOk()
        ->assertJsonPath('results.0.status', NurseSyncAction::STATUS_REJECTED)
        ->assertJsonPath('results.0.code', NurseSyncService::CODE_VALIDATION_FAILED);

    expect($fixture['task']->refresh()->status)->toBe(VisitTask::STATUS_OPEN)
        ->and($fixture['task']->not_done_reason)->toBeNull();
});

test('visit attachments are private and streamed only through an authorized controller', function () {
    Storage::fake('local');
    $fixture = e7Fixture();

    $this->withToken(e7Token($fixture['user']))
        ->postJson('/api/nurse/sync', ['actions' => [[
            'client_uuid' => 'e7-download-photo',
            'type' => 'visit_photo',
            'sequence' => 1,
            'device_timestamp' => '2026-08-03T07:40:00Z',
            'payload' => [
                'visit_id' => $fixture['visit']->id,
                'nurse_resource_id' => $fixture['resource']->id,
                'mime_type' => 'image/png',
                'data' => base64_encode('private-photo-bytes'),
            ],
        ]]])
        ->assertOk();

    $attachment = VisitAttachment::query()->firstOrFail();

    $otherTenant = e7Fixture('beta');

    $this->withToken(e7Token($otherTenant['user']))
        ->getJson(route('api.nurse.attachments.download', $attachment))
        ->assertNotFound();

    e7Ctx()->set($fixture['tenant']);

    $this->withToken(e7Token($fixture['user']))
        ->get(route('api.nurse.attachments.download', $attachment))
        ->assertOk();

    expect($attachment->storage_path)->toStartWith('tenants/'.$fixture['tenant']->id.'/nursing-attachments/')
        ->and($attachment->storage_path)->not->toContain('public')
        ->and(Storage::disk('local')->exists($attachment->storage_path))->toBeTrue();
});

test('visit vitals schema stores raw values only with no interpretive fields', function () {
    $columns = Schema::getColumnListing('visit_vitals');

    foreach (['systolic', 'diastolic', 'heart_rate', 'temperature_c', 'spo2', 'weight_g', 'height_mm'] as $rawColumn) {
        expect(in_array($rawColumn, $columns, true))->toBeTrue();
    }

    foreach (['flag', 'flags', 'range', 'score', 'interpretation', 'derived'] as $forbiddenColumn) {
        expect(in_array($forbiddenColumn, $columns, true))->toBeFalse();
    }
});

test('visit execution sync is tenant isolated', function () {
    $alpha = e7Fixture('alpha');
    $beta = e7Fixture('beta');

    e7Ctx()->set($beta['tenant']);

    $this->withToken(e7Token($beta['user']))
        ->postJson('/api/nurse/sync', ['actions' => [[
            'client_uuid' => 'e7-cross-tenant-note',
            'type' => 'visit_note',
            'sequence' => 1,
            'device_timestamp' => '2026-08-03T07:30:00Z',
            'payload' => [
                'visit_id' => $alpha['visit']->id,
                'nurse_resource_id' => $beta['resource']->id,
                'body' => 'Should never attach across tenants.',
            ],
        ]]])
        ->assertOk()
        ->assertJsonPath('results.0.status', NurseSyncAction::STATUS_REJECTED)
        ->assertJsonPath('results.0.code', NurseSyncService::CODE_VISIT_NOT_FOUND);

    expect(VisitNote::query()->count())->toBe(0)
        ->and(VisitVital::query()->count())->toBe(0)
        ->and(VisitAttachment::query()->count())->toBe(0);
});
