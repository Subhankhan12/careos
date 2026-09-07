<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\NurseSyncAction;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Services\NurseSyncService;
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

/*
|--------------------------------------------------------------------------
| QA-FIX.4e — the PWA can check in and out of a visit (P4-H3, D-205)
|--------------------------------------------------------------------------
| The server already implemented check_in/check_out with the visit state
| machine and EVV events; the CLIENT offered no control, so no visit could be
| started or closed from the field. This is wiring, and these tests pin the
| server half of it: the round trip a field round now performs, the EVV
| honesty guard Phase 4 verified, and the cross-assignment guard that must
| still refuse.
*/

const CIO_REASON = 'No location captured on this device';

function cioFixture(string $slug = 'wire'): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Spitex '.$slug, 'slug' => 'spitex-'.$slug, 'region' => 'eu', 'status' => 'active',
    ]);
    app(TenantContext::class)->set($tenant);

    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', 'nurse')->firstOrFail()->id,
    ]);

    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $staff = StaffProfile::query()->create([
        'user_id' => $user->id, 'first_name' => 'Wire', 'last_name' => 'Nurse',
        'display_name' => 'Wire Nurse', 'profession' => 'nurse', 'primary_branch_id' => $branch->id,
    ]);
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER, 'name' => 'Wire Nurse Resource',
        'staff_profile_id' => $staff->id, 'branch_id' => $branch->id,
    ]);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Wanda', 'last_name' => 'Wire', 'date_of_birth' => '1948-04-04', 'sex' => 'female',
    ]);
    $service = Service::query()->create([
        'name' => 'Home nursing', 'code' => strtoupper($slug).'-HOME', 'category' => 'home-care',
        'default_duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => false, 'active' => true,
    ]);
    $agreement = ServiceAgreement::query()->create([
        'patient_id' => $patient->id, 'branch_id' => $branch->id,
        'funding_type' => ServiceAgreement::FUNDING_OTHER, 'starts_on' => '2026-08-01',
        'status' => ServiceAgreement::STATUS_ACTIVE, 'created_by' => $user->id,
    ]);
    $agreementService = AgreementService::query()->create([
        'service_agreement_id' => $agreement->id, 'service_id' => $service->id,
        'planned_frequency_text' => 'As documented', 'required_qualification' => 'RN', 'duration_minutes' => 60,
    ]);
    $plan = VisitPlan::query()->create([
        'service_agreement_id' => $agreement->id, 'agreement_service_id' => $agreementService->id,
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=1', 'timezone' => 'Europe/Zurich',
        'window_start_time' => '09:00:00', 'window_end_time' => '11:00:00', 'duration_minutes' => 60,
        'starts_on' => '2026-08-03', 'active' => true,
    ]);
    $plannedVisit = PlannedVisit::query()->create([
        'visit_plan_id' => $plan->id, 'patient_id' => $patient->id, 'scheduled_date' => '2026-08-03',
        'window_start_at' => '2026-08-03 07:00:00', 'window_end_at' => '2026-08-03 08:00:00',
        'duration_minutes' => 60, 'required_qualification' => 'RN',
        'status' => PlannedVisit::STATUS_ASSIGNED, 'assigned_resource_id' => $resource->id,
        'assigned_at' => '2026-08-01 12:00:00', 'assigned_by' => $user->id,
    ]);

    return compact('tenant', 'user', 'resource', 'patient', 'plannedVisit');
}

/** Exactly the payload shape the PWA's queueCheckIn/queueCheckOut produce. */
function cioAction(string $type, array $fx, array $extra = [], int $seq = 1): array
{
    return [
        'client_uuid' => 'cio-'.$type.'-'.$seq.'-'.bin2hex(random_bytes(4)),
        'type' => $type,
        'payload' => array_merge([
            'planned_visit_id' => $fx['plannedVisit']->id,
            'client_visit_uuid' => 'offline-'.$fx['plannedVisit']->id,
            'nurse_resource_id' => $fx['resource']->id,
            'patient_id' => $fx['patient']->id,
            'manual_reason' => CIO_REASON,
        ], $extra),
        'device_timestamp' => '2026-08-03T09:35:00+02:00',
        'sequence' => $seq,
    ];
}

function cioSync(array $fx, array $actions): array
{
    return app(NurseSyncService::class)->sync($fx['user'], $actions);
}

test('a check-in from the client creates the execution visit and moves it to in_progress', function () {
    $fx = cioFixture('ci');

    $results = cioSync($fx, [cioAction('check_in', $fx)]);

    expect($results[0]['status'])->toBe(NurseSyncAction::STATUS_ACCEPTED)
        ->and($results[0]['code'])->toBe(NurseSyncService::CODE_ACCEPTED);

    $visit = Visit::query()->where('client_visit_uuid', 'offline-'.$fx['plannedVisit']->id)->firstOrFail();

    // Phase 4 could not produce an in_progress visit at all — no client path reached this state.
    expect($visit->status)->toBe(Visit::STATUS_IN_PROGRESS)
        ->and($visit->checked_in_at)->not->toBeNull();
});

test('a check-out closes the visit', function () {
    $fx = cioFixture('co');

    cioSync($fx, [cioAction('check_in', $fx)]);
    $results = cioSync($fx, [cioAction('check_out', $fx, [], 2)]);

    expect($results[0]['status'])->toBe(NurseSyncAction::STATUS_ACCEPTED);

    $visit = Visit::query()->where('client_visit_uuid', 'offline-'.$fx['plannedVisit']->id)->firstOrFail();

    expect($visit->status)->toBe(Visit::STATUS_COMPLETED)
        ->and($visit->checked_out_at)->not->toBeNull();
});

test('THE FULL FIELD ROUND — check in, record vitals, write a note, check out', function () {
    $fx = cioFixture('round');
    $uuid = 'offline-'.$fx['plannedVisit']->id;

    // This is the round trip Phase 4 could not perform at all.
    $results = cioSync($fx, [
        cioAction('check_in', $fx, [], 1),
        cioAction('visit_vitals', $fx, ['systolic' => 128, 'diastolic' => 82], 2),
        cioAction('visit_note', $fx, ['body' => 'Round complete, patient settled.'], 3),
        cioAction('check_out', $fx, [], 4),
    ]);

    expect(collect($results)->pluck('status')->all())
        ->each->toBe(NurseSyncAction::STATUS_ACCEPTED);

    $visit = Visit::query()->where('client_visit_uuid', $uuid)->firstOrFail();

    expect($visit->status)->toBe(Visit::STATUS_COMPLETED)
        ->and(DB::table('visit_vitals')->where('visit_id', $visit->id)->count())->toBe(1)
        ->and(DB::table('visit_notes')->where('visit_id', $visit->id)->count())->toBe(1);
});

test('EVV HONESTY — a check-in without GPS records manual_reason and leaves location NULL', function () {
    $fx = cioFixture('evv');

    cioSync($fx, [cioAction('check_in', $fx)]);

    $event = DB::table('visit_events')->where('type', 'check_in')->latest('created_at')->first();

    // Phase 4 verified this guard holds; wiring the client must not weaken it. The absence of a
    // location is RECORDED, never fabricated (D-176/D-179), and no accuracy or distance is invented
    // that the server did not compute (D-170).
    expect($event->manual_reason)->toBe(CIO_REASON)
        ->and($event->location)->toBeNull()
        ->and($event->accuracy_meters)->toBeNull()
        ->and($event->distance_meters)->toBeNull();
});

test('POSITIVE CONTROL — cross-assignment is STILL refused: one nurse cannot check into another nurse\'s visit', function () {
    $fx = cioFixture('cross');

    // A SECOND nurse in the SAME tenant, with a planned visit assigned to them.
    // (Two tenants would only prove tenant isolation, which is a different guard.)
    $other = User::factory()->forTenant($fx['tenant'])->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $other->id,
        'role_id' => Role::query()->where('key', 'nurse')->firstOrFail()->id,
    ]);
    $otherStaff = StaffProfile::query()->create([
        'user_id' => $other->id, 'first_name' => 'Other', 'last_name' => 'Nurse',
        'display_name' => 'Other Nurse', 'profession' => 'nurse',
        'primary_branch_id' => $fx['plannedVisit']->visitPlan?->id === null
            ? Branch::query()->firstOrFail()->id
            : Branch::query()->firstOrFail()->id,
    ]);
    $otherResource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER, 'name' => 'Other Nurse Resource',
        'staff_profile_id' => $otherStaff->id, 'branch_id' => Branch::query()->firstOrFail()->id,
    ]);

    $theirVisit = PlannedVisit::query()->create([
        'visit_plan_id' => $fx['plannedVisit']->visit_plan_id,
        'patient_id' => $fx['patient']->id,
        'scheduled_date' => '2026-08-04',
        'window_start_at' => '2026-08-04 07:00:00', 'window_end_at' => '2026-08-04 08:00:00',
        'duration_minutes' => 60, 'required_qualification' => 'RN',
        'status' => PlannedVisit::STATUS_ASSIGNED, 'assigned_resource_id' => $otherResource->id,
        'assigned_at' => '2026-08-01 12:00:00', 'assigned_by' => $other->id,
    ]);

    // Our nurse attempts to check in to THEIR visit.
    $results = cioSync($fx, [[
        'client_uuid' => 'cio-cross-1',
        'type' => 'check_in',
        'payload' => [
            'planned_visit_id' => $theirVisit->id,
            'client_visit_uuid' => 'offline-'.$theirVisit->id,
            'manual_reason' => CIO_REASON,
        ],
        'device_timestamp' => '2026-08-04T09:35:00+02:00',
        'sequence' => 1,
    ]]);

    expect($results[0]['status'])->toBe(NurseSyncAction::STATUS_REJECTED)
        ->and($results[0]['code'])->toBe(NurseSyncService::CODE_SCHEDULE_CHANGED)
        ->and(Visit::query()->where('client_visit_uuid', 'offline-'.$theirVisit->id)->exists())->toBeFalse();
});

test('POSITIVE CONTROL — the device timestamp on a check-in is still stored as UTC (QA-FIX.4b holds)', function () {
    $fx = cioFixture('utc');

    cioSync($fx, [cioAction('check_in', $fx)]);

    $visit = Visit::query()->where('client_visit_uuid', 'offline-'.$fx['plannedVisit']->id)->firstOrFail();

    // Sent 09:35+02:00 — the true instant is 07:35 UTC.
    expect(substr((string) $visit->getRawOriginal('checked_in_at'), 0, 19))->toBe('2026-08-03 07:35:00');
});
