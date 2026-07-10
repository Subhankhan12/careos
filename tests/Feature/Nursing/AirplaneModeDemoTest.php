<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Models\Allergy;
use Modules\Clinical\Models\Medication;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\NurseSyncAction;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\TimesheetLine;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitAttachment;
use Modules\Nursing\Models\VisitEvent;
use Modules\Nursing\Models\VisitNote;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Models\VisitTask;
use Modules\Nursing\Models\VisitVital;
use Modules\Nursing\Services\NurseSyncService;
use Modules\Nursing\Services\TimesheetService;
use Modules\Nursing\Services\VisitService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
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

function ecCtx(): TenantContext
{
    return app(TenantContext::class);
}

/**
 * @return array{tenant: Tenant, nurse: User, branch: Branch, staff: StaffProfile, resource: BookableResource, patient: Patient, plannedVisit: PlannedVisit, visit: Visit, task: VisitTask}
 */
function ecAirplaneFixture(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Airplane Nursing',
        'slug' => 'airplane',
        'region' => 'eu',
        'status' => 'active',
    ]);
    ecCtx()->set($tenant);

    $nurse = User::factory()->forTenant($tenant)->twoFactorEnabled()->create([
        'name' => 'Nora Nurse',
        'email' => 'nora.airplane@example.test',
    ]);
    RoleAssignment::query()->create([
        'user_id' => $nurse->id,
        'role_id' => Role::query()->where('key', 'nurse')->firstOrFail()->id,
    ]);

    $branch = Branch::query()->create(['name' => 'Airplane Branch', 'code' => 'AIR']);
    $staff = StaffProfile::query()->create([
        'user_id' => $nurse->id,
        'first_name' => 'Nora',
        'last_name' => 'Nurse',
        'display_name' => 'Nora Nurse',
        'profession' => 'nurse',
        'primary_branch_id' => $branch->id,
    ]);
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Nora Nurse Resource',
        'staff_profile_id' => $staff->id,
        'branch_id' => $branch->id,
    ]);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Ava',
        'last_name' => 'Offline',
        'date_of_birth' => '1938-04-05',
        'sex' => 'female',
    ]);
    PatientContact::query()->create([
        'patient_id' => $patient->id,
        'type' => PatientContact::TYPE_ADDRESS,
        'line1' => '1 Care Street',
        'city' => 'Zurich',
        'postal' => '8001',
        'country' => 'CH',
        'is_primary' => true,
    ]);
    Allergy::query()->create([
        'patient_id' => $patient->id,
        'substance' => 'Penicillin',
        'substance_key' => 'penicillin',
        'reaction' => 'Rash',
        'severity' => Allergy::SEVERITY_MODERATE,
        'recorded_by' => $staff->id,
        'recorded_at' => '2026-08-01 09:00:00',
    ]);
    Medication::query()->create([
        'patient_id' => $patient->id,
        'name' => 'Clinician documented medication',
        'substance_key' => 'documented-medication',
        'dose_text' => 'As documented',
        'started_on' => '2026-08-01',
        'status' => Medication::STATUS_ACTIVE,
        'recorded_by' => $staff->id,
        'recorded_at' => '2026-08-01 09:05:00',
    ]);

    $service = Service::query()->create([
        'name' => 'Home nursing',
        'code' => 'AIR-HOME',
        'category' => 'home-care',
        'default_duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => false,
        'active' => true,
    ]);
    $agreement = ServiceAgreement::query()->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'funding_type' => ServiceAgreement::FUNDING_OTHER,
        'starts_on' => '2026-08-01',
        'status' => ServiceAgreement::STATUS_ACTIVE,
        'created_by' => $nurse->id,
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
        'assigned_by' => $nurse->id,
        'location_latitude' => '47.376900',
        'location_longitude' => '8.541700',
    ]);

    $visit = app(VisitService::class)->createFromPlannedVisit($plannedVisit, 'airplane-offline-visit');
    $task = VisitTask::query()->create([
        'visit_id' => $visit->id,
        'agreement_service_id' => $agreementService->id,
        'description' => 'Support morning care',
    ]);

    return compact('tenant', 'nurse', 'branch', 'staff', 'resource', 'patient', 'plannedVisit', 'visit', 'task');
}

/**
 * This is the CI-runnable phase-exit harness. Playwright is not installed in
 * this repository, so genuine browser transport offline is covered as a known
 * limitation in the gate report; the nurse-pwa Vitest suite proves encrypted
 * IndexedDB/outbox persistence and this test proves the real server loop.
 */
test('airplane mode: full offline visit syncs and produces a timesheet line', function () {
    Storage::fake('local');
    $fixture = ecAirplaneFixture();

    $loginToken = $this->postJson('/api/nurse/login', [
        'email' => $fixture['nurse']->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->json('token');

    $dayPack = $this->withToken($loginToken)
        ->getJson('/api/nurse/day-pack?date=2026-08-03')
        ->assertOk()
        ->assertJsonCount(1, 'visits')
        ->assertJsonPath('visits.0.id', $fixture['plannedVisit']->id)
        ->assertJsonPath('visits.0.execution_visit_id', $fixture['visit']->id)
        ->assertJsonPath('visits.0.patient.id', $fixture['patient']->id)
        ->assertJsonPath('visits.0.patient.allergies.0.substance', 'Penicillin')
        ->assertJsonPath('visits.0.patient.medications.0.name', 'Clinician documented medication')
        ->assertJsonPath('visits.0.tasks.0.source', 'visit_task')
        ->json();

    $readRows = collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? AND resource_type = ? AND patient_id = ?',
        [$fixture['tenant']->id, 'read', 'patient', $fixture['patient']->id],
    ));

    expect($readRows)->toHaveCount(1)
        ->and($dayPack['visits'][0]['patient']['allergies'][0]['substance'])->toBe('Penicillin')
        ->and($dayPack['visits'][0]['patient']['medications'][0]['name'])->toBe('Clinician documented medication');

    $offlineOutbox = [
        [
            'client_uuid' => 'airplane-check-in',
            'type' => 'check_in',
            'sequence' => 1,
            'device_timestamp' => '2026-08-03T07:05:00Z',
            'payload' => [
                'planned_visit_id' => $fixture['plannedVisit']->id,
                'visit_id' => $fixture['visit']->id,
                'client_visit_uuid' => 'airplane-offline-visit',
                'nurse_resource_id' => $fixture['resource']->id,
                'location' => ['latitude' => 47.3769, 'longitude' => 8.5417, 'accuracy_meters' => 8],
            ],
        ],
        [
            'client_uuid' => 'airplane-task',
            'type' => 'visit_task_done',
            'sequence' => 2,
            'device_timestamp' => '2026-08-03T07:15:00Z',
            'payload' => [
                'visit_id' => $fixture['visit']->id,
                'visit_task_id' => $fixture['task']->id,
                'nurse_resource_id' => $fixture['resource']->id,
            ],
        ],
        [
            'client_uuid' => 'airplane-vitals',
            'type' => 'visit_vitals',
            'sequence' => 3,
            'device_timestamp' => '2026-08-03T07:20:00Z',
            'payload' => [
                'visit_id' => $fixture['visit']->id,
                'nurse_resource_id' => $fixture['resource']->id,
                'heart_rate' => 72,
                'spo2' => 98,
                'temperature_c' => '36.8',
            ],
        ],
        [
            'client_uuid' => 'airplane-note',
            'type' => 'visit_note',
            'sequence' => 4,
            'device_timestamp' => '2026-08-03T07:30:00Z',
            'payload' => [
                'visit_id' => $fixture['visit']->id,
                'nurse_resource_id' => $fixture['resource']->id,
                'body' => 'Observed breakfast completed and documented the visit.',
            ],
        ],
        [
            'client_uuid' => 'airplane-photo',
            'type' => 'visit_photo',
            'sequence' => 5,
            'device_timestamp' => '2026-08-03T07:40:00Z',
            'payload' => [
                'visit_id' => $fixture['visit']->id,
                'nurse_resource_id' => $fixture['resource']->id,
                'mime_type' => 'image/png',
                'data' => base64_encode('airplane-photo-bytes'),
            ],
        ],
        [
            'client_uuid' => 'airplane-signature',
            'type' => 'visit_signature',
            'sequence' => 6,
            'device_timestamp' => '2026-08-03T07:50:00Z',
            'payload' => [
                'visit_id' => $fixture['visit']->id,
                'nurse_resource_id' => $fixture['resource']->id,
                'mime_type' => 'image/png',
                'data' => base64_encode('airplane-signature-bytes'),
            ],
        ],
        [
            'client_uuid' => 'airplane-check-out',
            'type' => 'check_out',
            'sequence' => 7,
            'device_timestamp' => '2026-08-03T08:20:00Z',
            'payload' => [
                'visit_id' => $fixture['visit']->id,
                'nurse_resource_id' => $fixture['resource']->id,
                'location' => ['latitude' => 47.37691, 'longitude' => 8.54171, 'accuracy_meters' => 8],
            ],
        ],
    ];

    $this->withToken($loginToken)
        ->postJson('/api/nurse/sync', ['actions' => $offlineOutbox])
        ->assertOk()
        ->assertJsonPath('results.0.code', NurseSyncService::CODE_ACCEPTED)
        ->assertJsonPath('results.6.code', NurseSyncService::CODE_ACCEPTED);

    $visit = $fixture['visit']->refresh();
    $photo = VisitAttachment::query()->where('type', VisitAttachment::TYPE_PHOTO)->firstOrFail();
    $signature = VisitAttachment::query()->where('type', VisitAttachment::TYPE_SIGNATURE)->firstOrFail();
    $timesheet = app(TimesheetService::class)
        ->generateFromVisits($fixture['resource'], '2026-08-03', '2026-08-03')
        ->firstOrFail();

    expect(Visit::query()->count())->toBe(1)
        ->and($visit->status)->toBe(Visit::STATUS_COMPLETED)
        ->and(VisitEvent::query()->where('visit_id', $visit->id)->pluck('type')->all())->toBe([VisitEvent::TYPE_CHECK_IN, VisitEvent::TYPE_CHECK_OUT])
        ->and(VisitVital::query()->count())->toBe(1)
        ->and(VisitNote::query()->count())->toBe(1)
        ->and(VisitAttachment::query()->where('type', VisitAttachment::TYPE_PHOTO)->count())->toBe(1)
        ->and(VisitAttachment::query()->where('type', VisitAttachment::TYPE_SIGNATURE)->count())->toBe(1)
        ->and($fixture['task']->refresh()->status)->toBe(VisitTask::STATUS_DONE)
        ->and(Storage::disk('local')->exists($photo->storage_path))->toBeTrue()
        ->and(Storage::disk('local')->exists($signature->storage_path))->toBeTrue()
        ->and($timesheet)->toBeInstanceOf(TimesheetLine::class)
        ->and($timesheet->minutes)->toBe(75)
        ->and($timesheet->started_at->toDateTimeString())->toBe('2026-08-03 07:05:00')
        ->and($timesheet->ended_at?->toDateTimeString())->toBe('2026-08-03 08:20:00')
        ->and(app(AuditService::class)->verifyChain($fixture['tenant']->id)['ok'])->toBeTrue();

    $this->withToken($loginToken)
        ->postJson('/api/nurse/sync', ['actions' => $offlineOutbox])
        ->assertOk()
        ->assertJsonPath('results.0.code', NurseSyncService::CODE_ACCEPTED)
        ->assertJsonPath('results.6.code', NurseSyncService::CODE_ACCEPTED);

    expect(NurseSyncAction::query()->count())->toBe(7)
        ->and(Visit::query()->count())->toBe(1)
        ->and(VisitEvent::query()->count())->toBe(2)
        ->and(VisitVital::query()->count())->toBe(1)
        ->and(VisitNote::query()->count())->toBe(1)
        ->and(VisitAttachment::query()->where('type', VisitAttachment::TYPE_PHOTO)->count())->toBe(1)
        ->and(VisitAttachment::query()->where('type', VisitAttachment::TYPE_SIGNATURE)->count())->toBe(1);
});
