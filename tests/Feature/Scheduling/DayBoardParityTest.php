<?php

use App\AiCore\Tools\FillFromWaitlistTool;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Exceptions\BookingConflictException;
use Modules\Scheduling\Exceptions\IllegalAppointmentTransitionException;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AppointmentService;
use Modules\Scheduling\Services\BookingService;

uses(RefreshDatabase::class);

/*
 * SCHED.P1 — the day-board.
 *
 * The board used to render all five action verbs on every tile and let the server refuse the
 * illegal ones. Nothing unsafe happened — the machine is authoritative either way — but the screen
 * answered "what may I do?" differently from the server, and a receptionist learned the answer by
 * being told no. These tests pin the new contract: THE BOARD OFFERS ONLY WHAT THE SERVER ACCEPTS.
 */

function dbpCtx(): TenantContext
{
    return app(TenantContext::class);
}

/**
 * A day with an AWKWARD spread (D-189, fifth appearance): uneven status counts, uneven lane loads
 * with several lanes at zero, a real `checked_in_at`, and both an appointment where Arrive is legal
 * and ones where it is not. Every status is REACHED through the real machine, never written.
 *
 * @return array{tenant: Tenant, actor: User, branch: Branch, service: Service, practitioner: resource, room: resource, idle: resource, day: string, byStatus: array<string, Appointment>}
 */
function dbpFixture(): array
{
    // A unique slug: some tests build the fixture more than once, to drive an action from a status
    // the previous run already moved.
    $slug = 'alpha-'.Str::lower(Str::random(8));
    $tenant = Tenant::query()->create(['name' => 'Alpha Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    dbpCtx()->set($tenant);

    $actor = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $actor->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);

    $service = Service::query()->create([
        'name' => 'Consultation 30', 'code' => 'C30', 'category' => 'clinical',
        'default_duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        'requires_resource_types' => ['practitioner'], 'bookable_online' => true, 'active' => true,
    ]);

    $resource = function (string $name) use ($branch): Resource {
        $r = Resource::query()->create(['type' => 'practitioner', 'name' => $name, 'branch_id' => $branch->id, 'active' => true]);

        foreach (range(0, 6) as $weekday) {
            ResourceAvailability::query()->create([
                'resource_id' => $r->id, 'weekday' => $weekday, 'start_time' => '08:00', 'end_time' => '18:00',
            ]);
        }

        return $r;
    };

    $practitioner = $resource('Dr. Weber');
    $room = $resource('Dr. Vogel');
    // A lane with NO appointments at all — the board must render it honestly rather than hide it.
    $idle = $resource('Dr. Iten');

    $bookings = app(BookingService::class);
    $lifecycle = app(AppointmentService::class);
    $day = now()->addDays(3)->startOfDay();

    $patients = collect(range(0, 9))->map(fn (int $i): Patient => app(PatientService::class)->create([
        'first_name' => 'Pat'.$i, 'last_name' => 'Test', 'date_of_birth' => '1980-01-01', 'sex' => 'female',
    ]));

    $book = fn (int $hour, Resource $on, int $i): Appointment => $bookings->book(
        $service->id, $patients[$i]->id, $branch->id,
        $day->copy()->setTime($hour, 0)->toDateTimeString(), [$on->id], $actor,
    );

    // Eight on one lane, one on another, none on the third — deliberately lopsided.
    $made = [];
    foreach ([8, 9, 10, 11, 12, 13, 14, 15] as $i => $hour) {
        $made[] = $book($hour, $practitioner, $i);
    }
    $made[] = $book(9, $room, 8);

    // Each status REACHED through the real transitions.
    $byStatus = [
        Appointment::STATUS_BOOKED => $made[0],
        Appointment::STATUS_CONFIRMED => $lifecycle->confirm($made[1], $actor),
        Appointment::STATUS_ARRIVED => $lifecycle->arrive($lifecycle->confirm($made[2], $actor), $actor),
        Appointment::STATUS_IN_PROGRESS => $lifecycle->start($lifecycle->arrive($lifecycle->confirm($made[3], $actor), $actor), $actor),
        Appointment::STATUS_COMPLETED => $lifecycle->complete($lifecycle->start($lifecycle->arrive($lifecycle->confirm($made[4], $actor), $actor), $actor), $actor),
        Appointment::STATUS_CANCELLED => $lifecycle->cancel($made[5], $actor, 'Called to cancel.'),
        Appointment::STATUS_NO_SHOW => $lifecycle->noShow($made[6], $actor, 'Did not attend.'),
    ];

    // A REAL check-in stamp on the arrived one.
    $byStatus[Appointment::STATUS_ARRIVED]->forceFill(['checked_in_at' => now()->subMinutes(23)])->save();

    return [
        'tenant' => $tenant, 'actor' => $actor, 'branch' => $branch, 'service' => $service,
        'practitioner' => $practitioner, 'room' => $room, 'idle' => $idle,
        'day' => $day->toDateString(), 'byStatus' => $byStatus,
    ];
}

/** Every scalar leaf of a payload, so a scan cannot miss a nested key. */
function dbpLeaves(mixed $value, string $prefix = ''): array
{
    if (! is_array($value)) {
        return [$prefix => $value];
    }

    $out = [];
    foreach ($value as $key => $child) {
        $out += dbpLeaves($child, $prefix === '' ? (string) $key : $prefix.'.'.$key);
    }

    return $out;
}

/** The board's payload for the fixture day. */
function dbpBoard($test, array $f): array
{
    return $test->actingAs($f['actor'])
        ->get(route('scheduling.day-board', ['date' => $f['day'], 'branch_id' => $f['branch']->id]))
        ->viewData('page')['props'];
}

/* ------------------------------------------------------------------ one answer, not two */

test('the board offers exactly the actions the machine allows, including the D-156 compose', function () {
    $f = dbpFixture();
    $byId = collect(dbpBoard($this, $f)['appointments'])->keyBy('id');

    // The awkward spread is really there (D-174) — otherwise the assertions below scan nothing.
    expect($byId)->toHaveCount(9);

    $expected = [
        // `arrive` from `booked` is the D-156 compose: confirm → arrive, two legal edges.
        Appointment::STATUS_BOOKED => ['arrive', 'cancel', 'no_show'],
        Appointment::STATUS_CONFIRMED => ['arrive', 'cancel', 'no_show'],
        Appointment::STATUS_ARRIVED => ['start', 'cancel'],
        Appointment::STATUS_IN_PROGRESS => ['complete'],
        // Terminal: nothing at all.
        Appointment::STATUS_COMPLETED => [],
        Appointment::STATUS_CANCELLED => [],
        Appointment::STATUS_NO_SHOW => [],
    ];

    foreach ($expected as $status => $actions) {
        $row = $byId[$f['byStatus'][$status]->id];
        expect($row['status'])->toBe($status)
            ->and($row['actions'])->toBe($actions, "board actions for {$status}");
    }

    /*
     * ...AND THE GRID ACTUALLY USES THE LIST. A mutation proved this was needed: making `offers()`
     * return true unconditionally left every other assertion green, because they all check the
     * PAYLOAD and none checked that the component consults it. Pest cannot execute the template, so
     * this reads the component: `offers()` must answer from the list, and every action button must
     * be guarded by it.
     */
    $grid = (string) file_get_contents(resource_path('js/Components/ScheduleGrid.vue'));
    expect(file_exists(resource_path('js/Components/ScheduleGrid.vue')))->toBeTrue();

    expect($grid)->toContain('return (appointment.actions ?? []).includes(action);');

    foreach (['arrive', 'start', 'complete', 'cancel', 'no_show'] as $verb) {
        // NB: `toContain($needle, $message)` treats the message as a SECOND NEEDLE in Pest, so the
        // message goes on a boolean expectation instead (the GOV.P3 trap, hit again).
        expect(str_contains($grid, "v-if=\"offers(appointment, '{$verb}')\""))
            ->toBeTrue("the {$verb} button must be gated by the server's action list");
    }

    // Exactly five gated buttons — a sixth would be an action nobody derived.
    expect(substr_count($grid, 'v-if="offers(appointment'))->toBe(5);

    // POSITIVE CONTROL: a legal action IS offered — the list is not simply empty everywhere.
    expect($byId[$f['byStatus'][Appointment::STATUS_ARRIVED]->id]['actions'])->toContain('start');

    // And the illegal one is NOT: Arrive on an already-arrived appointment.
    expect($byId[$f['byStatus'][Appointment::STATUS_ARRIVED]->id]['actions'])->not->toContain('arrive');
});

test('NO offered action is refused — every one is driven for real', function () {
    $f = dbpFixture();
    $byId = collect(dbpBoard($this, $f)['appointments'])->keyBy('id');

    $drivenAny = false;

    foreach ($f['byStatus'] as $status => $appointment) {
        foreach ($byId[$appointment->id]['actions'] as $action) {
            // A fresh fixture per action, so each is driven from the TRUE status the board saw.
            $fresh = dbpFixture();
            $target = $fresh['byStatus'][$status];

            $this->actingAs($fresh['actor'])
                ->post(route('scheduling.day-board.transition'), [
                    'appointment_id' => $target->id,
                    'action' => $action,
                    'reason' => 'driven by the parity test',
                ])
                ->assertRedirect();

            // It actually moved — the action was accepted, not silently swallowed.
            expect(Appointment::query()->whereKey($target->id)->value('status'))
                ->not->toBe($status, "action {$action} on {$status} should have moved it");

            $drivenAny = true;
        }
    }

    // D-174: the loop above really ran, so "nothing was refused" is not vacuously true.
    expect($drivenAny)->toBeTrue();
});

test('an action the board does NOT offer is still refused by the server', function () {
    $f = dbpFixture();

    // The board offers nothing on a completed appointment. Forging one is still refused — the
    // action list grants nothing; the machine remains the authority (D-183: the service refuses,
    // not the UI).
    $completed = $f['byStatus'][Appointment::STATUS_COMPLETED];

    expect(fn () => app(AppointmentService::class)->arrive($completed, $f['actor']))
        ->toThrow(IllegalAppointmentTransitionException::class);

    // POSITIVE CONTROL: the same call on an appointment where it IS legal succeeds, so the refusal
    // is about legality and not about the service being broken.
    $confirmed = $f['byStatus'][Appointment::STATUS_CONFIRMED];
    expect(app(AppointmentService::class)->arrive($confirmed, $f['actor'])->status)
        ->toBe(Appointment::STATUS_ARRIVED);
});

/* --------------------------------------------------------------- the guard, and no override */

test('a conflicting book through the board is REFUSED, never overridden', function () {
    $f = dbpFixture();

    // The 08:00 slot on this practitioner is already taken by the fixture.
    $patient = app(PatientService::class)->create([
        'first_name' => 'Clash', 'last_name' => 'Test', 'date_of_birth' => '1990-05-05', 'sex' => 'male',
    ]);

    $payload = [
        'service_id' => $f['service']->id,
        'patient_id' => $patient->id,
        'branch_id' => $f['branch']->id,
        'starts_at' => Carbon::parse($f['day'])->setTime(8, 0)->toDateTimeString(),
        'resource_ids' => [$f['practitioner']->id],
    ];

    $before = Appointment::query()->count();

    /*
     * D-182: WITHOUT assertNoOverlap this would succeed — the slot is otherwise perfectly
     * bookable, which the positive control below proves by booking the very same shape at a
     * free hour. `withoutExceptionHandling` is deliberate: over HTTP the framework would turn
     * the refusal into a 500 page and the assertion would pass for the wrong reason.
     */
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($f['actor'])->post(route('scheduling.day-board.quick-book'), $payload))
        ->toThrow(BookingConflictException::class);

    expect(Appointment::query()->count())->toBe($before);

    // POSITIVE CONTROL: same service, same resource, same patient — just a free hour.
    $this->actingAs($f['actor'])->post(route('scheduling.day-board.quick-book'), [
        ...$payload,
        'starts_at' => Carbon::parse($f['day'])->setTime(16, 0)->toDateTimeString(),
    ])->assertRedirect();

    expect(Appointment::query()->count())->toBe($before + 1);

    // ...and an override parameter is not merely ignored — nothing anywhere reads one.
    $conflictAgain = [...$payload, 'override' => true, 'keep_both' => true, 'force' => true];

    expect(fn () => $this->actingAs($f['actor'])->post(route('scheduling.day-board.quick-book'), $conflictAgain))
        ->toThrow(BookingConflictException::class);

    expect(Appointment::query()->count())->toBe($before + 1);

    foreach (['override', 'keep_both', 'force'] as $key) {
        expect(str_contains(file_get_contents(base_path('Modules/Scheduling/src/Services/BookingService.php')), "'{$key}'"))
            ->toBeFalse("BookingService must not read a '{$key}' parameter");
    }
});

/* ------------------------------------------------------------------------ the carve-outs */

test('utilisation is a plain ratio of REAL minutes, with no tint, rank or band', function () {
    $f = dbpFixture();
    $props = dbpBoard($this, $f);

    $util = $props['utilisation'];

    /*
     * Eight 30-minute appointments were booked on this lane, but ONE was cancelled — and a
     * cancelled appointment holds no minutes (it releases its resource links entirely). So the
     * honest figure is seven, and asserting eight would have been asserting the booking rather
     * than the record.
     */
    expect($util[$f['practitioner']->id]['bookedMinutes'])->toBe(7 * 30)
        ->and($util[$f['room']->id]['bookedMinutes'])->toBe(30)
        // The idle lane is present and honestly zero, not hidden.
        ->and($util[$f['idle']->id]['bookedMinutes'])->toBe(0);

    // The denominator is the branch's real scan window, and the percentage is just the division.
    $available = $util[$f['practitioner']->id]['availableMinutes'];
    expect($available)->toBeGreaterThan(0)
        ->and($util[$f['practitioner']->id]['percent'])->toBe((int) round(210 / $available * 100))
        ->and($util[$f['idle']->id]['percent'])->toBe(0);

    // NOTHING keyed to the value: no tint, no band, no ranking.
    foreach (array_keys(dbpLeaves($util)) as $path) {
        foreach (['tint', 'colour', 'color', 'class', 'band', 'rank', 'best', 'worst', 'level'] as $needle) {
            expect(str_contains(strtolower($path), $needle))->toBeFalse("utilisation must not carry '{$needle}' ({$path})");
        }
    }

    // The lane order is the resource order, never the utilisation order.
    expect(collect($props['resources'])->pluck('id')->all())
        ->toBe(Resource::query()->where('branch_id', $f['branch']->id)->where('active', true)->orderBy('name')->pluck('id')->all());
});

test('waiting time comes from the real checked_in_at, with no threshold anywhere', function () {
    $f = dbpFixture();
    $byId = collect(dbpBoard($this, $f)['appointments'])->keyBy('id');

    $arrived = $byId[$f['byStatus'][Appointment::STATUS_ARRIVED]->id];
    $booked = $byId[$f['byStatus'][Appointment::STATUS_BOOKED]->id];

    /*
     * The recorded stamp travels as an ISO instant derived from the RAW stored value, and the
     * elapsed minutes are computed server-side from it. Browser verification forced this: a naive
     * timestamp is ambiguous both to the viewer's zone and to the tenant-timezone middleware, which
     * re-labels the stored UTC string as the practice's zone.
     */
    $rawUtc = CarbonImmutable::parse(
        (string) Appointment::query()->whereKey($arrived['id'])->value('checked_in_at')->format('Y-m-d H:i:s'),
        'UTC',
    );

    expect($arrived['checked_in_at'])->toBe($rawUtc->toIso8601String())
        // An appointment nobody checked in carries null, never a zero.
        ->and($booked['checked_in_at'])->toBeNull()
        ->and($booked['waiting_minutes'])->toBeNull();

    // The elapsed is a real, non-negative number of minutes since that instant.
    expect($arrived['waiting_minutes'])->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($arrived['waiting_minutes'])->toBe(
            max(0, (int) $rawUtc->diffInMinutes(CarbonImmutable::now('UTC'))),
        );

    // No threshold, band or escalation key anywhere in the payload...
    foreach (array_keys(dbpLeaves(dbpBoard($this, $f))) as $path) {
        foreach (['threshold', 'overdue', 'late', 'escalat', 'urgent', 'waiting_too'] as $needle) {
            expect(str_contains(strtolower($path), $needle))->toBeFalse("board payload must not carry '{$needle}' ({$path})");
        }
    }

    // ...and the component computes elapsed minutes without comparing them to anything.
    $grid = (string) file_get_contents(resource_path('js/Components/ScheduleGrid.vue'));
    expect($grid)->toContain('function waitingMinutes');
    /*
     * Scan for the BANDING, not the word: the comment beside the render legitimately says there
     * is no amber, and forbidding the string would forbid the explanation. (Third time this
     * shape has appeared — COMMS.P2 and SCHED.P2 both hit it.)
     */
    // No comparison is ever applied to the elapsed minutes — that is what a threshold IS.
    foreach (['waitingMinutes(appointment) >', 'waitingMinutes(appointment) <', 'waitingMinutes(appointment) >='] as $band) {
        expect(str_contains($grid, $band))->toBeFalse("ScheduleGrid must not compare the waiting time ({$band})");
    }

    /*
     * ...and the line itself is styled STATICALLY. Scanning the whole file for `text-danger` would
     * have failed on the Cancel button, which is legitimately destructive — the question is not
     * whether the file contains an alarming colour but whether the WAITING LINE is keyed to its own
     * value (D-169). So this reads the waiting paragraph and asserts it carries no class binding.
     */
    $start = strpos($grid, 'waitingMinutes(appointment) !== null');
    expect($start)->not->toBeFalse('the waiting line must be rendered');
    $line = substr($grid, $start, 320);

    expect($line)->toContain('text-ink-muted')
        ->and(str_contains($line, ':class'))->toBeFalse('the waiting line must not bind a class to its own value');
});

/* ----------------------------------------------------------------- the waitlist stays put */

test('the waitlist tool still needs a human at the APPROVE ceiling, and the hold copy is honest', function () {
    $definition = app(FillFromWaitlistTool::class)->definition();

    expect($definition->autonomyCeiling)->toBe(AutonomyPolicy::APPROVE)
        ->and($definition->permission)->toBe('appointment.manage');

    // The audit found the design claiming the SLOT is held. It is not: the hold is one open offer
    // per ENTRY, and the overlap guard never reads waitlist_offers.
    $guard = (string) file_get_contents(base_path('Modules/Scheduling/src/Services/BookingService.php'));
    expect(str_contains($guard, 'waitlist_offer'))->toBeFalse('assertNoOverlap must not consult waitlist offers');

    $copy = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);
    $hold = strtolower($copy['scheduling']['dayBoard']['waitlistHold']);

    expect($hold)->toContain('not the slot')
        ->and($hold)->not->toContain('no one loses');
});

/* ------------------------------------------------------------------------------ the scan */

test('no override, optimiser or prediction key in the payload or the components', function () {
    $f = dbpFixture();
    $props = dbpBoard($this, $f);

    expect($props['appointments'])->not->toBeEmpty();

    $forbidden = ['override', 'keep_both', 'optimise', 'optimize', 'auto_fill', 'autofill',
        'suggested_move', 'best_', 'no_show_risk', 'predicted', 'forecast', 'on_schedule', 'risk_score'];

    foreach (array_keys(dbpLeaves($props)) as $path) {
        foreach ($forbidden as $needle) {
            expect(str_contains(strtolower($path), $needle))
                ->toBeFalse("board payload must not carry '{$needle}' ({$path})");
        }
    }

    // The components draw none of them either. Each scan resolves its subject first, so it cannot
    // go silent if a file moves (D-173).
    foreach ([
        resource_path('js/pages/Scheduling/DayBoard.vue'),
        resource_path('js/Components/ScheduleGrid.vue'),
    ] as $file) {
        expect(file_exists($file))->toBeTrue("the scanned component must exist at {$file}");
        $source = (string) file_get_contents($file);
        expect(strlen($source))->toBeGreaterThan(1000);

        foreach (['keepBoth', 'overrideConflict', 'optimiseDay', 'autoFill'] as $affordance) {
            expect(str_contains($source, $affordance))->toBeFalse("{$file} must not carry {$affordance}");
        }

        /*
         * `noShowRisk` and `predictedDuration` are the KEYS of the omission list that declines
         * them, so they may appear exactly once on the page that renders that list and never on
         * the grid. Forbidding the string outright would forbid the honesty.
         */
        $allowed = str_ends_with($file, 'DayBoard.vue') ? 1 : 0;
        foreach (['noShowRisk', 'predictedDuration'] as $concept) {
            expect(substr_count($source, $concept))
                ->toBe($allowed, "{$concept} may appear only in the omission list ({$file})");
        }
    }
});

test('the omission card is RENDERED and names each refusal', function () {
    // GOV.P3: assert the keys the component iterates, not merely the copy file.
    $source = (string) file_get_contents(resource_path('js/pages/Scheduling/DayBoard.vue'));

    /*
     * ...AND IT MUST BE REACHABLE. Browser verification caught the card rendering NOWHERE: it had
     * landed inside the quick-book slide-over (`v-if="quickBookOpen"`), so it existed only while
     * that dialog was open — while every source-level assertion below still passed. Source presence
     * is not reachability, so the card's position relative to the modal is now pinned too.
     */
    $cardAt = strpos($source, 'scheduling.dayBoard.omitted.title');
    $modalAt = strpos($source, 'v-if="quickBookOpen"');
    expect($cardAt)->not->toBeFalse()->and($modalAt)->not->toBeFalse();
    expect($cardAt < $modalAt)->toBeTrue('the omission card must sit in the main column, not inside the quick-book modal');

    expect($source)->toContain("const omittedKeys = ['override', 'ranked', 'autofill', 'noShowRisk', 'predictedDuration', 'onSchedule']")
        ->and($source)->toContain('scheduling.dayBoard.omitted.${key}')
        ->and($source)->toContain("t('scheduling.dayBoard.omitted.title')");

    $copy = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);
    $omitted = $copy['scheduling']['dayBoard']['omitted'];

    foreach (['override', 'ranked', 'autofill', 'noShowRisk', 'predictedDuration', 'onSchedule'] as $key) {
        expect($omitted)->toHaveKey($key);
        expect(strlen((string) $omitted[$key]))->toBeGreaterThan(60);
    }

    // Each statement must actually name what it refuses.
    expect(strtolower($omitted['override']))->toContain('refused')
        ->and(strtolower($omitted['ranked']))->toContain('best')
        ->and(strtolower($omitted['noShowRisk']))->toContain('risk')
        ->and(strtolower($omitted['onSchedule']))->toContain('judgment');
});
