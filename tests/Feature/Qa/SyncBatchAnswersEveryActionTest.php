<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\NurseSyncAction;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitNote;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Services\NurseSyncService;
use Modules\Nursing\Services\VisitService;
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
| QA-FIX.12b — FAMILY 7, the partial write (`P4-H2`, and `P4-H1` closes with it).
|
| THE INVARIANT THIS FILE PINS: **every action in a batch yields exactly one result.**
|
| `P4-H2` was not a transaction-boundary defect. The unit of atomicity is the ACTION and that is
| correct — actions are independent and deduped by `client_uuid`, so a batch-level rollback would
| throw away good care because one action was malformed. What was missing is that a FAILING action
| must still ANSWER. When one escaped, the whole response became a 500 with no `results` array while
| the actions that had already run stayed durable: the device was told everything failed when part of
| it had succeeded.
|
| WHY `P4-H1` CLOSES WITH IT, AND WHY THAT IS A PROPERTY OF THE CLIENT, NOT A HOPE. Both of `P4-H1`'s
| 500s were the same escape, and its "jam" is `nurse-pwa/src/api.ts` removing NOTHING on a non-OK
| response. That client already removes every `client_uuid` present in `results` — accepted or
| rejected — so a complete envelope drains the outbox with no client change at all. The test below
| pins that the envelope is complete, because that is the property the client depends on.
*/

function sbaCtx(): TenantContext
{
    return app(TenantContext::class);
}

/** @return array<string, mixed> */
function sbaFixture(string $slug = 'sba'): array
{
    $tenant = Tenant::query()->create([
        'name' => 'SBA '.$slug, 'slug' => $slug, 'region' => 'eu', 'status' => 'active',
    ]);
    sbaCtx()->set($tenant);

    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $role = Role::query()->where('key', 'nurse')->first();
    if ($role !== null) {
        RoleAssignment::query()->firstOrCreate(['user_id' => $user->id, 'role_id' => $role->id]);
    }

    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4))]);
    $staff = StaffProfile::query()->create([
        'first_name' => 'Nora', 'last_name' => 'Nurse', 'display_name' => 'Nora Nurse',
        'profession' => 'nurse', 'primary_branch_id' => $branch->id, 'user_id' => $user->id,
        'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER, 'name' => 'Nora Nurse Resource',
        'staff_profile_id' => $staff->id, 'branch_id' => $branch->id, 'active' => true,
    ]);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Petra', 'last_name' => 'Pflege',
        'date_of_birth' => '1950-04-04', 'sex' => 'female',
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
        'planned_frequency_text' => 'As documented', 'required_qualification' => 'RN',
        'duration_minutes' => 60,
    ]);
    $plan = VisitPlan::query()->create([
        'service_agreement_id' => $agreement->id, 'agreement_service_id' => $agreementService->id,
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=1', 'timezone' => 'Europe/Zurich',
        'window_start_time' => '09:00:00', 'window_end_time' => '11:00:00',
        'duration_minutes' => 60, 'starts_on' => '2026-08-03', 'active' => true,
    ]);
    $plannedVisit = PlannedVisit::query()->create([
        'visit_plan_id' => $plan->id, 'patient_id' => $patient->id,
        'scheduled_date' => '2026-08-03', 'window_start_at' => '2026-08-03 07:00:00',
        'window_end_at' => '2026-08-03 08:00:00', 'duration_minutes' => 60,
        'required_qualification' => 'RN', 'status' => PlannedVisit::STATUS_ASSIGNED,
        'assigned_resource_id' => $resource->id, 'assigned_at' => '2026-08-01 12:00:00',
        'assigned_by' => $user->id,
    ]);

    return compact('tenant', 'user', 'branch', 'staff', 'resource', 'patient', 'plannedVisit');
}

function sbaToken(User $user): string
{
    return $user->createToken('nurse-device', ['nurse:day-pack'])->plainTextToken;
}

/** An execution Visit created through the REAL service, then checked in, so it is no longer scheduled. */
function sbaCheckedInVisit(array $f): Visit
{
    $visit = app(VisitService::class)->createFromPlannedVisit($f['plannedVisit'], 'sba-visit-'.bin2hex(random_bytes(4)));
    // A STRING is the manual reason; an ARRAY is a GPS location and then requires lat/long. This
    // device captures no GPS (D-205), so it states the reason rather than fabricating a position.
    app(VisitService::class)->checkIn($visit, $f['user'], 'no gps on this device', '2026-08-03T07:05:00Z');

    return $visit->refresh();
}

/* ------------------------------------------------------------------ *
 | `P4-H2` — the finding's exact batch.                                |
 * ------------------------------------------------------------------ */

it('P4-H2: a batch with one failing action still answers for BOTH, and the report matches the database', function () {
    $f = sbaFixture('sba-h2');
    $visit = sbaCheckedInVisit($f);
    $token = sbaToken($f['user']);

    $notesBefore = VisitNote::query()->count();

    sbaCtx()->forget();

    $response = $this->withToken($token)->postJson('/api/nurse/sync', ['actions' => [
        [
            'client_uuid' => 'sba-good-note',
            'type' => 'visit_note',
            'sequence' => 1,
            'device_timestamp' => '2026-08-03T08:00:00Z',
            'payload' => ['visit_id' => $visit->id, 'body' => 'Wound dressing changed.'],
        ],
        [
            // THE FINDING'S INPUT: `client_visit_uuid` deliberately absent.
            'client_uuid' => 'sba-bad-checkin',
            'type' => 'check_in',
            'sequence' => 2,
            'device_timestamp' => '2026-08-03T08:05:00Z',
            'payload' => ['planned_visit_id' => $f['plannedVisit']->id, 'nurse_resource_id' => $f['resource']->id, 'manual_reason' => 'no gps'],
        ],
    ]]);

    // BEFORE THE FIX: HTTP 500 with no `results` at all (driven, and re-driven after).
    $response->assertOk();

    $results = collect($response->json('results'));

    expect($results)->toHaveCount(2)
        ->and($results->firstWhere('client_uuid', 'sba-good-note')['status'])->toBe(NurseSyncAction::STATUS_ACCEPTED)
        ->and($results->firstWhere('client_uuid', 'sba-bad-checkin')['status'])->toBe(NurseSyncAction::STATUS_REJECTED)
        ->and($results->firstWhere('client_uuid', 'sba-bad-checkin')['code'])->toBe(NurseSyncService::CODE_ACTION_FAILED);

    /*
     * THE DEFECT WAS THE CONTRADICTION, NOT THE COMMIT. The good note SHOULD be durable — that is what
     * a per-action boundary means. What was wrong is that the response denied it. Both halves asserted.
     */
    expect(VisitNote::query()->count())->toBe($notesBefore + 1);
});

it('P4-H2: an action that WRITES and then throws leaves nothing durable — its own transaction rolled back', function () {
    $f = sbaFixture('sba-roll');
    $token = sbaToken($f['user']);

    $visitsBefore = Visit::query()->count();

    sbaCtx()->forget();

    /*
     * A DELIBERATELY DISCRIMINATING FIXTURE, because the obvious one proves nothing. A `check_in` with
     * no `client_visit_uuid` throws while EVALUATING the argument, before any write — so asserting
     * "nothing was written" there would pass even with no transaction at all, which is the weak-test
     * trap this programme has already been caught by once.
     *
     * This one WRITES FIRST and THEN throws: `client_visit_uuid` is present, so
     * `createFromPlannedVisit()` really creates the execution `Visit`; the `location` then carries a
     * latitude and NO longitude, so `VisitService::locationPayload()` throws afterwards. Only a real
     * rollback can make the Visit disappear. Mutation-checked by removing the `DB::transaction`
     * wrapper in `dispatch()`, which leaves the orphan behind and reddens this.
     */
    $this->withToken($token)->postJson('/api/nurse/sync', ['actions' => [[
        'client_uuid' => 'sba-rollback',
        'type' => 'check_in',
        'sequence' => 1,
        'device_timestamp' => '2026-08-03T08:05:00Z',
        'payload' => [
            'planned_visit_id' => $f['plannedVisit']->id,
            'client_visit_uuid' => 'sba-rollback-visit',
            'nurse_resource_id' => $f['resource']->id,
            'location' => ['latitude' => 47.3769],
        ],
    ]]])->assertOk()->assertJsonPath('results.0.status', NurseSyncAction::STATUS_REJECTED);

    sbaCtx()->set($f['tenant']);

    // No half-made execution Visit was left behind by the action that failed.
    expect(Visit::query()->count())->toBe($visitsBefore)
        ->and(Visit::query()->where('client_visit_uuid', 'sba-rollback-visit')->exists())->toBeFalse();
});

/* ------------------------------------------------------------------ *
 | `P4-H1` — both 500s, and the jam.                                   |
 * ------------------------------------------------------------------ */

it('P4-H1: a check_in missing client_visit_uuid is REJECTED, not a 500', function () {
    $f = sbaFixture('sba-h1a');
    $token = sbaToken($f['user']);

    sbaCtx()->forget();

    $response = $this->withToken($token)->postJson('/api/nurse/sync', ['actions' => [[
        'client_uuid' => 'sba-missing-uuid',
        'type' => 'check_in',
        'sequence' => 1,
        'device_timestamp' => '2026-08-03T08:05:00Z',
        'payload' => ['planned_visit_id' => $f['plannedVisit']->id, 'nurse_resource_id' => $f['resource']->id, 'manual_reason' => 'no gps'],
    ]]]);

    // Phase 4 drove this and got `500 Undefined array key "client_visit_uuid"`.
    $response->assertOk()
        ->assertJsonPath('results.0.status', NurseSyncAction::STATUS_REJECTED)
        ->assertJsonPath('results.0.code', NurseSyncService::CODE_ACTION_FAILED);
});

it('P4-H1: a check_in on a visit that is no longer scheduled is REJECTED, not a 500', function () {
    $f = sbaFixture('sba-h1b');
    $visit = sbaCheckedInVisit($f);
    $token = sbaToken($f['user']);

    sbaCtx()->forget();

    $response = $this->withToken($token)->postJson('/api/nurse/sync', ['actions' => [[
        'client_uuid' => 'sba-recheckin',
        'type' => 'check_in',
        'sequence' => 1,
        'device_timestamp' => '2026-08-03T08:10:00Z',
        'payload' => [
            'planned_visit_id' => $f['plannedVisit']->id,
            'client_visit_uuid' => $visit->client_visit_uuid,
            'nurse_resource_id' => $f['resource']->id,
            'manual_reason' => 'no gps',
        ],
    ]]]);

    // Phase 4 drove this and got `500 Only scheduled visits can be checked in.`
    $response->assertOk()
        ->assertJsonPath('results.0.status', NurseSyncAction::STATUS_REJECTED);
});

it('P4-H1: THE JAM DISSOLVES — every client_uuid sent comes back, which is what the device deletes by', function () {
    $f = sbaFixture('sba-jam');
    $visit = sbaCheckedInVisit($f);
    $token = sbaToken($f['user']);

    $sent = [
        ['client_uuid' => 'sba-jam-1', 'type' => 'visit_note', 'sequence' => 1, 'device_timestamp' => '2026-08-03T08:00:00Z',
            'payload' => ['visit_id' => $visit->id, 'body' => 'One.']],
        ['client_uuid' => 'sba-jam-poison', 'type' => 'check_in', 'sequence' => 2, 'device_timestamp' => '2026-08-03T08:01:00Z',
            'payload' => ['planned_visit_id' => $f['plannedVisit']->id, 'nurse_resource_id' => $f['resource']->id]],
        ['client_uuid' => 'sba-jam-3', 'type' => 'visit_note', 'sequence' => 3, 'device_timestamp' => '2026-08-03T08:02:00Z',
            'payload' => ['visit_id' => $visit->id, 'body' => 'Three.']],
        ['client_uuid' => 'sba-jam-unknown', 'type' => 'not_a_real_action', 'sequence' => 4, 'device_timestamp' => '2026-08-03T08:03:00Z',
            'payload' => ['visit_id' => $visit->id]],
    ];

    sbaCtx()->forget();

    $response = $this->withToken($token)->postJson('/api/nurse/sync', ['actions' => $sent])->assertOk();

    /*
     * THE PROPERTY THE CLIENT DEPENDS ON. `nurse-pwa/src/api.ts` does
     * `removeOutboxEntries(payload.results.map((r) => r.client_uuid))` — it deletes by the uuids that
     * come BACK. A poison action that produced no result therefore stayed queued for ever and blocked
     * everything behind it. Asserted as set equality, not as a count, so a result for the wrong action
     * cannot satisfy it.
     */
    $returned = collect($response->json('results'))->pluck('client_uuid')->sort()->values()->all();
    $expected = collect($sent)->pluck('client_uuid')->sort()->values()->all();

    expect($returned)->toBe($expected);

    // And the actions BEHIND the poison one were really processed, not merely answered.
    expect(VisitNote::query()->where('body', 'Three.')->exists())->toBeTrue();
});

it('P4-H1: the client removes by the returned uuids — pinned in the PWA, so the claim above is not a hope', function () {
    $api = (string) file_get_contents(base_path('nurse-pwa/src/api.ts'));

    // If this line changed shape, the "jam dissolves" argument would no longer follow from the envelope.
    expect($api)->toContain('removeOutboxEntries(payload.results.map((result) => result.client_uuid))');
});

/* ------------------------------------------------------------------ *
 | The boundaries the fix must NOT cross.                              |
 * ------------------------------------------------------------------ */

it('a batch-level refusal is still batch-level: a 403 about the TOKEN is not downgraded to a per-action rejection', function () {
    $f = sbaFixture('sba-403');

    // A nurse with NO active practitioner resource — `nurseResources()` refuses the whole batch.
    $stranger = User::factory()->forTenant($f['tenant'])->twoFactorEnabled()->create();
    $token = sbaToken($stranger);

    sbaCtx()->forget();

    /*
     * THE POSITIVE CONTROL FOR "I DID NOT SWALLOW EVERYTHING" (D-174). A catch-all that turned every
     * throw into a rejected result would make this 200, and the device would be told its action was
     * refused when in fact its TOKEN is unusable for every action in the batch.
     *
     * WHERE THIS BOUNDARY LIVES, STATED HONESTLY: this 403 is thrown by `nurseResources()` inside
     * `sync()`, BEFORE the per-action map — so it never passes through `process()`'s catch, and this
     * test does not pin that catch. It pins the boundary itself, which is what matters. (An earlier
     * version of the fix also re-threw `HttpException` inside `process()`; a mutation deleting that arm
     * left every test green, because nothing can reach it, so the arm was removed rather than kept.)
     */
    $this->withToken($token)->postJson('/api/nurse/sync', ['actions' => [[
        'client_uuid' => 'sba-403-action',
        'type' => 'visit_note',
        'sequence' => 1,
        'device_timestamp' => '2026-08-03T08:00:00Z',
        'payload' => ['body' => 'x'],
    ]]])->assertForbidden();

    expect(NurseSyncAction::query()->where('client_action_uuid', 'sba-403-action')->exists())->toBeFalse();
});

it('a replayed batch returns the SAME answers and does not run twice', function () {
    $f = sbaFixture('sba-replay');
    $visit = sbaCheckedInVisit($f);
    $token = sbaToken($f['user']);

    $actions = [
        ['client_uuid' => 'sba-replay-note', 'type' => 'visit_note', 'sequence' => 1, 'device_timestamp' => '2026-08-03T08:00:00Z',
            'payload' => ['visit_id' => $visit->id, 'body' => 'Once only.']],
        ['client_uuid' => 'sba-replay-bad', 'type' => 'check_in', 'sequence' => 2, 'device_timestamp' => '2026-08-03T08:01:00Z',
            'payload' => ['planned_visit_id' => $f['plannedVisit']->id, 'nurse_resource_id' => $f['resource']->id]],
    ];

    sbaCtx()->forget();

    $first = $this->withToken($token)->postJson('/api/nurse/sync', ['actions' => $actions])->assertOk()->json('results');

    /*
     * A REJECTION IS LEDGERED, SO A RETRY REPLAYS IT RATHER THAN RE-RUNNING IT. Without the ledger row
     * the failing action would be re-attempted on every sync — the jam again, one layer down.
     */
    $second = $this->withToken($token)->postJson('/api/nurse/sync', ['actions' => $actions])->assertOk()->json('results');

    expect($second)->toBe($first);

    sbaCtx()->set($f['tenant']);
    expect(VisitNote::query()->where('body', 'Once only.')->count())->toBe(1)
        ->and(NurseSyncAction::query()->whereIn('client_action_uuid', ['sba-replay-note', 'sba-replay-bad'])->count())->toBe(2);
});

it('POSITIVE CONTROL: an all-good batch is still all accepted and still writes', function () {
    $f = sbaFixture('sba-good');
    $visit = sbaCheckedInVisit($f);
    $token = sbaToken($f['user']);

    sbaCtx()->forget();

    $results = $this->withToken($token)->postJson('/api/nurse/sync', ['actions' => [
        ['client_uuid' => 'sba-ok-1', 'type' => 'visit_note', 'sequence' => 1, 'device_timestamp' => '2026-08-03T08:00:00Z',
            'payload' => ['visit_id' => $visit->id, 'body' => 'Alpha.']],
        ['client_uuid' => 'sba-ok-2', 'type' => 'visit_note', 'sequence' => 2, 'device_timestamp' => '2026-08-03T08:01:00Z',
            'payload' => ['visit_id' => $visit->id, 'body' => 'Beta.']],
    ]])->assertOk()->json('results');

    // The suite must not be satisfiable by a service that rejects everything.
    expect(collect($results)->pluck('status')->unique()->all())->toBe([NurseSyncAction::STATUS_ACCEPTED]);

    sbaCtx()->set($f['tenant']);
    expect(VisitNote::query()->whereIn('body', ['Alpha.', 'Beta.'])->count())->toBe(2);
});
