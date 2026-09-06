<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\Incident;
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
| QA-FIX.4b — device times are parsed to UTC at the sync boundary (P4-C4, D-202)
|--------------------------------------------------------------------------
| The datetime columns these values land in are cast 'datetime', so Eloquent
| parses the string with Carbon and serialises it with format('Y-m-d H:i:s')
| IN THE CARBON'S OWN TIMEZONE. An offset-bearing instant therefore stored its
| LOCAL WALL CLOCK:
|
|   sent 2026-09-06T07:35:00+02:00  ->  stored 07:35:00   (true UTC 05:35:00)
|   sent 2026-09-06T00:35:00-05:00  ->  stored 00:35:00   (true UTC 05:35:00)
|   sent 2026-09-06T05:35:00.000Z   ->  stored 05:35:00   (correct)
|
| EVERY FIXTURE BELOW USES A NON-ZERO OFFSET. A UTC/Z fixture would pass with or
| without the fix and prove nothing (D-174) — the offset is the whole point.
*/

/** One instant, expressed three ways. Every case must store DTU_UTC. */
const DTU_PLUS_2 = '2026-08-03T09:35:00+02:00';
const DTU_MINUS_5 = '2026-08-03T02:35:00-05:00';
const DTU_ZULU = '2026-08-03T07:35:00.000Z';
const DTU_UTC = '2026-08-03 07:35:00';

function dtuFixture(string $slug = 'utc'): array
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
        'user_id' => $user->id, 'first_name' => 'Utc', 'last_name' => 'Nurse',
        'display_name' => 'Utc Nurse', 'profession' => 'nurse', 'primary_branch_id' => $branch->id,
    ]);
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER, 'name' => 'Utc Nurse Resource',
        'staff_profile_id' => $staff->id, 'branch_id' => $branch->id,
    ]);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Ute', 'last_name' => 'Zeit', 'date_of_birth' => '1950-03-03', 'sex' => 'female',
    ]);
    $service = Service::query()->create([
        'name' => 'Home nursing',
        'code' => strtoupper($slug).'-HOME',
        'category' => 'home-care',
        'default_duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => false,
        'active' => true,
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

/** Sync one or more actions through the real service. */
function dtuSync(array $fx, array $actions): array
{
    return app(NurseSyncService::class)->sync($fx['user'], $actions);
}

function dtuAction(string $type, array $payload, string $deviceTime, int $seq = 1, ?string $uuid = null): array
{
    return [
        'client_uuid' => $uuid ?? ('dtu-'.$type.'-'.$seq.'-'.bin2hex(random_bytes(4))),
        'type' => $type,
        'payload' => $payload,
        'device_timestamp' => $deviceTime,
        'sequence' => $seq,
    ];
}

/** Read a column straight from the DB so no model cast can re-interpret it on the way out. */
function dtuRaw(string $table, string $column, ?string $where = null): ?string
{
    $q = DB::table($table);
    if ($where !== null) {
        $q->whereRaw($where);
    }
    $row = $q->orderByDesc('created_at')->first();

    return $row?->{$column} === null ? null : substr((string) $row->{$column}, 0, 19);
}

/** Check in so the execution Visit exists, then return it. */
function dtuCheckedInVisit(array $fx, string $deviceTime = DTU_PLUS_2): Visit
{
    dtuSync($fx, [dtuAction('check_in', [
        'planned_visit_id' => $fx['plannedVisit']->id,
        'client_visit_uuid' => 'dtu-visit-'.bin2hex(random_bytes(4)),
        'manual_reason' => 'QA-FIX.4b fixture',
    ], $deviceTime)]);

    return Visit::query()->latest('created_at')->firstOrFail();
}

test('check_in stores the UTC INSTANT, not the device wall clock (occurred_at + checked_in_at)', function () {
    $fx = dtuFixture('ci');

    $visit = dtuCheckedInVisit($fx, DTU_PLUS_2);

    // Sent 09:35+02:00 — the true instant is 07:35 UTC. Pre-fix this stored 09:35.
    expect(substr((string) $visit->getRawOriginal('checked_in_at'), 0, 19))->toBe(DTU_UTC)
        ->and(dtuRaw('visit_events', 'occurred_at'))->toBe(DTU_UTC);
});

test('check_out stores the UTC instant', function () {
    $fx = dtuFixture('co');
    $visit = dtuCheckedInVisit($fx, DTU_PLUS_2);

    dtuSync($fx, [dtuAction('check_out', [
        'planned_visit_id' => $fx['plannedVisit']->id,
        'client_visit_uuid' => $visit->client_visit_uuid,
        'manual_reason' => 'QA-FIX.4b fixture',
    ], '2026-08-03T11:35:00+02:00', 2)]);

    expect(substr((string) $visit->fresh()->getRawOriginal('checked_out_at'), 0, 19))->toBe('2026-08-03 09:35:00');
});

test('visit_vitals recorded_at stores the UTC instant', function () {
    $fx = dtuFixture('vv');
    $visit = dtuCheckedInVisit($fx);

    dtuSync($fx, [dtuAction('visit_vitals', [
        'client_visit_uuid' => $visit->client_visit_uuid,
        'systolic' => 128, 'diastolic' => 82,
    ], DTU_PLUS_2, 2)]);

    expect(dtuRaw('visit_vitals', 'recorded_at'))->toBe(DTU_UTC);
});

test('visit_note recorded_at stores the UTC instant', function () {
    $fx = dtuFixture('vn');
    $visit = dtuCheckedInVisit($fx);

    dtuSync($fx, [dtuAction('visit_note', [
        'client_visit_uuid' => $visit->client_visit_uuid,
        'body' => 'QA-FIX.4b note',
    ], DTU_PLUS_2, 2)]);

    expect(dtuRaw('visit_notes', 'recorded_at'))->toBe(DTU_UTC);
});

test('the ledger device_timestamp stores the UTC instant — every action type passes through it', function () {
    $fx = dtuFixture('led');

    dtuCheckedInVisit($fx, DTU_PLUS_2);

    expect(dtuRaw('nurse_sync_actions', 'device_timestamp'))->toBe(DTU_UTC);
});

test('an incident stores BOTH its own occurred_at and the ledger time in UTC', function () {
    $fx = dtuFixture('inc');
    $visit = dtuCheckedInVisit($fx);

    dtuSync($fx, [dtuAction('incident_report', [
        'client_visit_uuid' => $visit->client_visit_uuid,
        // payload.occurred_at is NOT covered by the controller's validation — it must be
        // normalised by the same boundary, not left as a wall clock.
        'occurred_at' => '2026-08-03T09:35:00+02:00',
        'category' => Incident::CATEGORY_FALL,
        'description' => 'QA-FIX.4b incident',
        'severity' => Incident::SEVERITY_LOW,
    ], '2026-08-03T10:00:00+02:00', 2)]);

    expect(dtuRaw('incidents', 'occurred_at'))->toBe(DTU_UTC);
});

test('THE SAME INSTANT SENT THREE WAYS STORES ONE VALUE — +02:00, -05:00 and Z all agree', function () {
    // This is the test that makes the fix meaningful rather than incidental: three different
    // wall clocks, one instant, one stored value.
    $stored = [];

    foreach (['p2' => DTU_PLUS_2, 'm5' => DTU_MINUS_5, 'z' => DTU_ZULU] as $slug => $sent) {
        $fx = dtuFixture('same-'.$slug);
        $visit = dtuCheckedInVisit($fx, $sent);
        $stored[$slug] = substr((string) $visit->getRawOriginal('checked_in_at'), 0, 19);
    }

    expect($stored['p2'])->toBe(DTU_UTC)
        ->and($stored['m5'])->toBe(DTU_UTC)
        ->and($stored['z'])->toBe(DTU_UTC)
        ->and(array_unique(array_values($stored)))->toHaveCount(1);
});

test('POSITIVE CONTROL — a Z instant is UNCHANGED by the fix (it was already correct)', function () {
    $fx = dtuFixture('zulu');
    $visit = dtuCheckedInVisit($fx, DTU_ZULU);

    // The shipped PWA sends toISOString(), i.e. Z. The fix must not move these rows.
    expect(substr((string) $visit->getRawOriginal('checked_in_at'), 0, 19))->toBe(DTU_UTC);
});

test('an UNPARSEABLE payload occurred_at is REJECTED, never silently replaced with another time', function () {
    $fx = dtuFixture('bad');
    $visit = dtuCheckedInVisit($fx);

    $before = Incident::query()->count();

    $results = dtuSync($fx, [dtuAction('incident_report', [
        'client_visit_uuid' => $visit->client_visit_uuid,
        'occurred_at' => 'not-a-timestamp',
        'category' => Incident::CATEGORY_FALL,
        'description' => 'QA-FIX.4b bad time',
        'severity' => Incident::SEVERITY_LOW,
    ], DTU_PLUS_2, 2)]);

    // Rejected cleanly — NOT a 500 (the P4-H1 shape) and NOT recorded at device_timestamp instead,
    // which would put the incident at a time the reporter never stated (D-176 / D-179).
    expect($results[0]['status'])->toBe(NurseSyncAction::STATUS_REJECTED)
        ->and($results[0]['code'])->toBe(NurseSyncService::CODE_VALIDATION_FAILED)
        ->and(Incident::query()->count())->toBe($before);
});

test('POSITIVE CONTROL — the boundary did not break ordinary sync: a normal action is still accepted', function () {
    $fx = dtuFixture('ok');
    $visit = dtuCheckedInVisit($fx);

    $results = dtuSync($fx, [dtuAction('visit_note', [
        'client_visit_uuid' => $visit->client_visit_uuid,
        'body' => 'still works',
    ], DTU_ZULU, 2)]);

    expect($results[0]['status'])->toBe(NurseSyncAction::STATUS_ACCEPTED)
        ->and($results[0]['code'])->toBe(NurseSyncService::CODE_ACCEPTED);
});
