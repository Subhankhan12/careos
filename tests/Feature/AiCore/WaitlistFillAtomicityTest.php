<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Services\AgentRuntime;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Models\WaitlistEntry;
use Modules\Scheduling\Services\AppointmentService;
use Modules\Scheduling\Services\BookingService;
use Modules\Scheduling\Services\WaitlistService;

uses(RefreshDatabase::class);

/*
 * QA-FIX.10c — `P10-C2`: a refused agent approval half-committed and stranded the patient.
 *
 * Driven in Phase 10: the seeded `scheduler.fill_from_waitlist` proposal named a slot, that slot was
 * booked first through the day board for someone else, and Approve was clicked. The booking was
 * correctly REFUSED (`BookingConflictException` — the re-grounding works, and no second appointment was
 * created), but the refusal left the world half-changed: `waitlist_entries.status = offered`, pointing at
 * a slot now belonging to another patient, with a `waitlist.offered` audit row for an offer that never
 * stood and ZERO rows in `waitlist_offers`. Bruno Nussbaumer was then neither waiting nor booked —
 * invisible to the candidate search, with no product path back.
 *
 * `WaitlistService::offer()` flips the status with a bare `->save()` OUTSIDE any transaction;
 * `accept()` opens its own and throws. A rollback cannot reach a commit that already happened.
 *
 * THE FIXTURE'S POINT IS THE CONFLICT. A fixture that books cleanly proves nothing about this finding:
 * the half-commit only exists on the REFUSAL path, so the slot is deliberately taken first, by a
 * different patient, exactly as the phase drove it.
 */

function wfaTenant(): Tenant
{
    $tenant = Tenant::create(['name' => 'Waitlist Clinic', 'slug' => 'waitlist-atomicity', 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

function wfaUser(Tenant $tenant): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', 'org_admin')->firstOrFail()->id]);

    return $user;
}

function wfaPatient(string $first, string $last): Patient
{
    return app(PatientService::class)->create([
        'first_name' => $first, 'last_name' => $last, 'date_of_birth' => '1988-06-06', 'sex' => 'male',
    ]);
}

function wfaBranch(): Branch
{
    return Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
}

function wfaService(): Service
{
    return Service::create([
        'name' => 'Consult', 'code' => 'CONS', 'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => true, 'active' => true,
    ]);
}

function wfaResource(Branch $branch): BookableResource
{
    $resource = BookableResource::create([
        'type' => BookableResource::TYPE_PRACTITIONER, 'name' => 'Practitioner',
        'branch_id' => $branch->id, 'active' => true,
    ]);
    ResourceAvailability::create(['resource_id' => $resource->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']);

    return $resource;
}

/** The slot is a Monday inside the resource's availability window. */
const WFA_STARTS = '2026-07-13 10:00:00';

const WFA_ENDS = '2026-07-13 10:30:00';

/** @return array{tenant: Tenant, user: User, branch: Branch, service: Service, resource: BookableResource, waiting: Patient, entry: WaitlistEntry} */
function wfaFixture(): array
{
    $tenant = wfaTenant();
    $user = wfaUser($tenant);
    $branch = wfaBranch();
    $service = wfaService();
    $resource = wfaResource($branch);
    $waiting = wfaPatient('Bruno', 'Nussbaumer');

    $entry = app(WaitlistService::class)->create([
        'patient_id' => $waiting->id,
        'service_id' => $service->id,
        'branch_id' => $branch->id,
        'desired_starts_at' => '2026-07-13 09:00:00',
        'desired_ends_at' => '2026-07-13 12:00:00',
        'flexible' => false,
        'priority' => 5,
    ]);

    return compact('tenant', 'user', 'branch', 'service', 'resource', 'waiting', 'entry');
}

/** Propose the fill, exactly as the agent does, and return the pending action. */
function wfaProposal(array $fx): AgentAction
{
    $result = app(AgentRuntime::class)->runTool(
        'scheduler.fill_from_waitlist',
        [
            'service_id' => $fx['service']->id,
            'branch_id' => $fx['branch']->id,
            'starts_at' => WFA_STARTS,
            'ends_at' => WFA_ENDS,
            'resource_ids' => [$fx['resource']->id],
        ],
        $fx['user'],
        'scheduler.fill_waitlist',
        'scheduler-agent',
        'Open slot can be offered to a matching waitlist entry',
    );

    return $result['action'];
}

/** Take the slot for somebody else, which is what makes the approval refuse. */
function wfaTakeTheSlot(array $fx): Appointment
{
    return app(BookingService::class)->book(
        $fx['service']->id,
        wfaPatient('Regula', 'Tanner')->id,
        $fx['branch']->id,
        WFA_STARTS,
        [$fx['resource']->id],
        $fx['user'],
    );
}

it('leaves NOTHING behind when the booking is refused — the patient stays waiting', function () {
    $fx = wfaFixture();
    $action = wfaProposal($fx);
    $taken = wfaTakeTheSlot($fx);

    $before = DB::table('audit_events')->count();

    try {
        app(ApprovalQueue::class)->approve($action, $fx['user']);
        $this->fail('the booking should have been refused — the slot is taken');
    } catch (Throwable $e) {
        expect($e)->not->toBeInstanceOf(AiCoreException::class); // it is the domain conflict, unchanged
    }

    /*
     * THE REPRODUCTION, ASSERTED IN FOUR PLACES. Before the fix the entry read `offered`, an
     * `waitlist.offered` audit row existed, and the patient was stranded against someone else's slot.
     */
    expect($fx['entry']->refresh()->status)->toBe(WaitlistEntry::STATUS_WAITING)
        ->and($fx['entry']->offered_starts_at)->toBeNull()
        ->and($fx['entry']->offered_branch_id)->toBeNull()
        ->and(DB::table('audit_events')->where('action', 'waitlist.offered')->count())->toBe(0);

    // The refusal itself is unchanged: no second appointment, and the first one still stands.
    expect(Appointment::query()->count())->toBe(1)
        ->and(Appointment::query()->firstOrFail()->id)->toBe($taken->id);

    /*
     * NOW NOTHING SURVIVES AT ALL — AND THIS TEST WAS WRITTEN TO FAIL WHEN THAT BECAME TRUE.
     *
     * It used to expect exactly one appended row, `ai_interaction.approved`, and said so by NAME while
     * recording why: `ApprovalQueue::approve()` wrote that row BEFORE calling the tool, so a refused
     * booking left a permanent approval behind. It named that residue as `P10-H1` — "a separate HIGH,
     * still open, deliberately NOT fixed in this gate".
     *
     * `P10-H1` is fixed in QA-FIX.12e (D-230): the recorder moved below `execute()`, so a refused
     * approval appends nothing. The expectation flips from one named row to an EMPTY set, which is the
     * strongest form of this test's own claim — "leaves NOTHING behind" is now literally true. The
     * naming discipline is kept: if the offer flip, its `waitlist.offered` row, or a stale approval ever
     * came back, this set would grow and this fails.
     */
    $appended = DB::table('audit_events')->orderByDesc('occurred_at')->limit(20)->pluck('action')
        ->take(DB::table('audit_events')->count() - $before)->values()->all();

    expect($appended)->toBe([])
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();
});

it('leaves the patient reachable — a refused fill can be retried and still books', function () {
    $fx = wfaFixture();
    $action = wfaProposal($fx);
    $taken = wfaTakeTheSlot($fx);

    try {
        app(ApprovalQueue::class)->approve($action, $fx['user']);
    } catch (Throwable) {
        // expected
    }

    /*
     * THE CONSEQUENCE THAT MADE THIS CRITICAL, NOT MERELY THE COLUMN. `offer()` requires
     * `status === waiting`, so once the entry was stranded in `offered` the SAME action could never take
     * that path again — the retry fell through to "no matching waiting entry" and was recorded as
     * Approved. With the flip rolled back the patient is still a candidate, so freeing the slot and
     * approving again books them, which is what "no product path back" meant.
     */
    expect(app(WaitlistService::class)
        ->matchingForSlot($fx['service']->id, $fx['branch']->id, WFA_STARTS, WFA_ENDS)
        ->contains('id', $fx['entry']->id))->toBeTrue();

    app(AppointmentService::class)->cancel($taken, $fx['user'], 'slot freed again');

    $retry = app(ApprovalQueue::class)->approve(wfaProposal($fx), $fx['user']);

    expect($retry->status)->toBe(AgentAction::STATUS_EXECUTED)
        ->and($retry->result['booked'])->toBeTrue()
        ->and($fx['entry']->refresh()->status)->toBe(WaitlistEntry::STATUS_BOOKED);
});

it('still books normally when the slot is free — the fix changes refusals, not successes', function () {
    $fx = wfaFixture();
    $action = wfaProposal($fx);

    // THE POSITIVE CONTROL (D-174). Wrapping the pair must not change the path that works.
    $approved = app(ApprovalQueue::class)->approve($action, $fx['user']);

    expect($approved->status)->toBe(AgentAction::STATUS_EXECUTED)
        ->and($approved->result['booked'])->toBeTrue()
        ->and(Appointment::query()->count())->toBe(1)
        ->and($fx['entry']->refresh()->status)->toBe(WaitlistEntry::STATUS_BOOKED)
        ->and(DB::table('audit_events')->where('action', 'waitlist.offered')->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();
});

it('refuses instead of reporting success when there is nothing left to book', function () {
    $fx = wfaFixture();
    $action = wfaProposal($fx);

    /*
     * The candidate is removed through a REAL product path, not by writing a status column: a human
     * offers this same slot to the same entry between proposal and approval, which is exactly how the
     * queue's stale proposals go stale. `matchingForSlot` returns only `waiting` entries, so the
     * approval then finds nobody.
     */
    app(WaitlistService::class)->offer($fx['entry'], WFA_STARTS, WFA_ENDS, $fx['branch']->id, $fx['user']);

    /*
     * `P10-C2`'s SECOND HALF. This returned `['booked' => false, 'reason' => 'no_matching_waitlist_entry']`
     * and a tool that RETURNS is a tool that succeeded: `agent_actions.status` became `executed`, both
     * timestamps were stamped, and the Resolved tab rendered **"Approved"** with nothing saying no
     * appointment exists. It must refuse, and the action must stay pending for a human to decide.
     */
    expect(fn () => app(ApprovalQueue::class)->approve($action, $fx['user']))
        ->toThrow(AiCoreException::class);

    expect($action->refresh()->status)->toBe(AgentAction::STATUS_PENDING)
        ->and($action->executed_at)->toBeNull()
        ->and(Appointment::query()->count())->toBe(0);
});
