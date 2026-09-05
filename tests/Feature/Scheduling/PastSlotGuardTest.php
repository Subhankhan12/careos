<?php

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Exceptions\BookingUnavailableException;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AvailableSlotFinder;
use Modules\Scheduling\Services\BookingService;

uses(RefreshDatabase::class);

/*
 * QA-FIX.1b — A BOOKING MAY NOT START IN THE PAST (P1-H3, D-194).
 *
 * The audit reproduced this in a browser: at 22:21 local the reschedule panel offered TODAY at
 * 08:00, labelled "soonest", and confirming it created a real appointment 742 minutes in the past,
 * status `booked`, with no warning. Two separate things were missing and both are covered here:
 *
 *   1. THE FINDER must not OFFER an elapsed slot — so every consumer inherits it.
 *   2. THE BOOKING PATH must REFUSE one anyway — because the finder not offering something is a
 *      different guarantee from the server refusing it, and a stale or forged request skips the
 *      finder entirely (D-183: pin the refusal where nothing outside can answer first).
 *
 * The clock is frozen LATE IN THE DAY throughout. Without that these tests would pass for the
 * wrong reason — the fixture date would simply be wholly future or wholly past — which is the
 * D-182 shape: a refusal test must be one that would SUCCEED without its guard.
 */

const PSG_DAY = '2026-07-13';      // a Monday
const PSG_LATE = '2026-07-13 16:00:00'; // late enough that the morning has gone, the afternoon has not

function psgCtx(): TenantContext
{
    return app(TenantContext::class);
}

/**
 * @return array{tenant: Tenant, branch: Branch, service: Service, resource: BookableResource, patient: Patient, user: User}
 */
function psgFixture(string $timezone = 'UTC'): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Past Slot Practice', 'slug' => 'psg-'.substr(md5($timezone), 0, 6),
        'region' => 'eu', 'status' => 'active',
    ]);
    psgCtx()->set($tenant);

    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => $timezone]);
    $service = Service::query()->create([
        'name' => 'Consult', 'code' => 'CONS', 'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => true, 'active' => true,
    ]);
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER, 'name' => 'Dr Past',
        'branch_id' => $branch->id, 'active' => true,
    ]);
    ResourceAvailability::query()->create([
        'resource_id' => $resource->id, 'weekday' => 1, 'start_time' => '07:00', 'end_time' => '19:00',
    ]);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Pat', 'last_name' => 'Past', 'date_of_birth' => '1980-01-01', 'sex' => 'female',
    ]);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    return compact('tenant', 'branch', 'service', 'resource', 'patient', 'user');
}

/** @return list<string> */
function psgSlotStarts(array $fx): array
{
    return array_column(
        app(AvailableSlotFinder::class)->forServiceBranchDate($fx['service'], $fx['branch']->id, PSG_DAY, 48),
        'starts_at',
    );
}

afterEach(fn () => Carbon::setTestNow());

// ── 1. THE FINDER ────────────────────────────────────────────────────────────────────────────

test('the finder offers NO slot that has already started — with a positive control that later ones ARE offered', function () {
    Carbon::setTestNow(CarbonImmutable::parse(PSG_LATE));
    $fx = psgFixture();

    $starts = psgSlotStarts($fx);

    // THE POSITIVE CONTROL (D-174/D-182): the afternoon IS offered, so the finder is genuinely
    // producing slots for this day and the absences below mean something. Without it, a finder
    // that returned nothing at all would pass every assertion here.
    expect($starts)->not->toBeEmpty()
        ->and($starts)->toContain(PSG_DAY.' 16:30:00')
        ->and($starts)->toContain(PSG_DAY.' 17:00:00');

    // The morning has gone. Under the defect every one of these was offered — the 08:00 in the
    // browser reproduction was the first of them, labelled "soonest".
    expect($starts)->not->toContain(PSG_DAY.' 07:00:00')
        ->and($starts)->not->toContain(PSG_DAY.' 08:00:00')
        ->and($starts)->not->toContain(PSG_DAY.' 12:00:00');

    // And nothing at or before "now" survives, whatever the stride happens to land on.
    foreach ($starts as $start) {
        expect(CarbonImmutable::parse($start)->greaterThan(CarbonImmutable::parse(PSG_LATE)))->toBeTrue();
    }
});

test('the finder still offers a whole future day (the fix is about elapsed time, not about filtering)', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-07-13 05:00:00'));
    $fx = psgFixture();

    $starts = psgSlotStarts($fx);

    expect($starts)->toContain(PSG_DAY.' 07:00:00')
        ->and($starts)->toContain(PSG_DAY.' 18:30:00');
});

test('the finder reads the BRANCH clock, not the server clock', function () {
    // 06:00 UTC is already 08:00 in Zurich, so a Zurich branch has lost its 07:00 slot while a
    // UTC branch has not. Same instant, two branches, two correct answers — which is only possible
    // if the comparison is made in the branch's zone.
    Carbon::setTestNow(CarbonImmutable::parse('2026-07-13 06:00:00'));

    $utc = psgFixture('UTC');
    expect(psgSlotStarts($utc))->toContain(PSG_DAY.' 07:00:00');

    $zurich = psgFixture('Europe/Zurich');
    expect(psgSlotStarts($zurich))->not->toContain(PSG_DAY.' 07:00:00')
        ->and(psgSlotStarts($zurich))->toContain(PSG_DAY.' 09:00:00');
});

// ── 2. THE BOOKING GUARD, INDEPENDENTLY OF THE FINDER ────────────────────────────────────────

test('the SERVICE refuses a past start — pinned where nothing outside can answer first', function () {
    Carbon::setTestNow(CarbonImmutable::parse(PSG_LATE));
    $fx = psgFixture();

    // Called directly: no controller, no finder, no UI. This is the forged/stale request.
    expect(fn () => app(BookingService::class)->book(
        $fx['service']->id, $fx['patient']->id, $fx['branch']->id,
        PSG_DAY.' 08:00:00', [$fx['resource']->id], $fx['user'],
        allowPastStart: false,
    ))->toThrow(BookingUnavailableException::class);

    psgCtx()->set($fx['tenant']);
    expect(Appointment::query()->count())->toBe(0);
});

test('the guard refuses only the PAST — a later slot on the same day still books', function () {
    Carbon::setTestNow(CarbonImmutable::parse(PSG_LATE));
    $fx = psgFixture();

    // The positive control for the refusal above: same call, same fixture, a future start.
    $appointment = app(BookingService::class)->book(
        $fx['service']->id, $fx['patient']->id, $fx['branch']->id,
        PSG_DAY.' 17:00:00', [$fx['resource']->id], $fx['user'],
        allowPastStart: false,
    );

    expect($appointment->status)->toBe(Appointment::STATUS_BOOKED);
});

test('bookOnline DEFAULTS to refusing a past start — the patient-facing path is strict without asking', function () {
    Carbon::setTestNow(CarbonImmutable::parse(PSG_LATE));
    $fx = psgFixture();

    /*
     * Called exactly as the portal and public controllers call it: no flag. The default on this
     * method is the SAFE one (the opposite of `book()`), so a patient-facing path cannot backdate
     * by forgetting an argument, and nothing a client sends can relax it.
     *
     * The flag exists on this method only so the demo seeders can RECORD historical online
     * bookings while keeping `source = online` / `booked_by = null` (D-031).
     */
    expect(fn () => app(BookingService::class)->bookOnline(
        $fx['service']->id, $fx['patient']->id, $fx['branch']->id,
        PSG_DAY.' 08:00:00', [$fx['resource']->id],
    ))->toThrow(BookingUnavailableException::class);
});

// ── 3. THE THREE REQUEST PATHS ───────────────────────────────────────────────────────────────

test('the STAFF quick-book path refuses a forged past start and writes nothing', function () {
    Carbon::setTestNow(CarbonImmutable::parse(PSG_LATE));
    $fx = psgFixture();

    psgCtx()->forget();
    $this->actingAs($fx['user'])
        ->post(route('scheduling.day-board.quick-book'), [
            'service_id' => $fx['service']->id,
            'patient_id' => $fx['patient']->id,
            'branch_id' => $fx['branch']->id,
            'starts_at' => PSG_DAY.' 08:00:00',
            'resource_ids' => [$fx['resource']->id],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('starts_at');

    psgCtx()->set($fx['tenant']);
    expect(Appointment::query()->count())->toBe(0);
});

test('the PUBLIC booking form refuses a forged past start, and creates no patient for it', function () {
    Carbon::setTestNow(CarbonImmutable::parse(PSG_LATE));
    $fx = psgFixture();
    $patientsBefore = Patient::query()->count();

    psgCtx()->forget();
    $this->post(route('public.booking.store', $fx['tenant']->slug), [
        'service_id' => $fx['service']->id,
        'branch_id' => $fx['branch']->id,
        'starts_at' => PSG_DAY.' 08:00:00',
        'resource_ids' => [$fx['resource']->id],
        'first_name' => 'Walk', 'last_name' => 'In',
        'date_of_birth' => '1990-05-05', 'sex' => 'female',
        'email' => 'walk.in@example.test',
    ])
        ->assertRedirect()
        ->assertSessionHasErrors('starts_at');

    psgCtx()->set($fx['tenant']);
    // The whole attempt rolls back, so the visitor's details are not left behind either.
    expect(Appointment::query()->count())->toBe(0)
        ->and(Patient::query()->count())->toBe($patientsBefore);
});

// ── 4. RECORDING WHAT ALREADY HAPPENED IS STILL ALLOWED ──────────────────────────────────────

test('backdated RECORDING still works — the guard distinguishes booking from recording', function () {
    Carbon::setTestNow(CarbonImmutable::parse(PSG_LATE));
    $fx = psgFixture();

    /*
     * The demo seeders lay down a real past week through this same method, and a practice
     * legitimately records a visit it forgot to enter. That is RECORDING, not booking, and the
     * caller says so explicitly. `allowPastStart` is a call-site constant — it is never read from
     * a request — so permitting this cannot be reached by anything a client sends.
     */
    $recorded = app(BookingService::class)->book(
        $fx['service']->id, $fx['patient']->id, $fx['branch']->id,
        PSG_DAY.' 08:00:00', [$fx['resource']->id], $fx['user'],
        allowPastStart: true,
    );

    expect($recorded->starts_at->toDateTimeString())->toBe(PSG_DAY.' 08:00:00');
});

test('the PORTAL self-booking path refuses a forged past start and writes nothing', function () {
    Carbon::setTestNow(CarbonImmutable::parse(PSG_LATE));
    $fx = psgFixture();

    // A real portal identity: the consent gate is part of the path, so it is satisfied rather
    // than reached past.
    ConsentTemplate::query()->create([
        'key' => 'portal', 'title' => 'Portal Access', 'body' => 'Portal access consent',
        'version' => 1, 'scope_keys' => ['portal.access'], 'is_active' => true,
    ]);
    app(ConsentService::class)->grant($fx['patient'], 'portal', 'Pat Past', $fx['user']);
    $account = PortalAccount::query()->create([
        'patient_id' => $fx['patient']->id,
        'email' => 'pat.past@portal.test',
        'password' => bcrypt('secret-portal-pass'),
        'status' => PortalAccount::STATUS_ACTIVE,
        'activated_at' => now(),
    ]);

    psgCtx()->forget();
    $this->actingAs($account, 'patient')
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->post(route('portal.appointments.store'), [
            'service_id' => $fx['service']->id,
            'branch_id' => $fx['branch']->id,
            'starts_at' => PSG_DAY.' 08:00:00',
            'resource_ids' => [$fx['resource']->id],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('starts_at');

    psgCtx()->set($fx['tenant']);
    expect(Appointment::query()->count())->toBe(0);
});
