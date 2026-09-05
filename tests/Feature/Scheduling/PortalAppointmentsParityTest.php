<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Services\PatientBalanceReader;
use Modules\Clinical\Models\Document;
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
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;

uses(RefreshDatabase::class);

/*
 * PT.P3 — Portal Home + Appointments visual parity over machinery that was already correct.
 *
 * The point of this suite is that the parity work did not loosen anything:
 *
 *  1. HOME'S NUMBERS ARE THE SERVER'S. Counts are real row counts (the PC.P2 lesson — never a Vue
 *     length over a partial payload) and the balance is PT.P2's reader, tying to the engine.
 *  2. APPOINTMENTS SHOWS THE REAL SPREAD in the right groups, across past / imminent / future /
 *     cancelled (D-174 — the spread is what would tempt an imminence tint).
 *  3. THE BOOKING GUARD IS UNTOUCHED: slots come from the real finder, a soft-suspended branch
 *     (BRANCH.P1) offers NONE and refuses a forged booking that skips the slot list, and the
 *     overlap guard means a patient cannot double-book.
 *  4. NO JUDGMENT reaches the patient — no urgency, priority, no-show risk, and no styling keyed to
 *     how soon an appointment is (D-169).
 */

function ptcCtx(): TenantContext
{
    return app(TenantContext::class);
}

/** Sign in the way a real portal request does (the PT.P1 lesson). */
function ptcSignIn($test, PortalAccount $account)
{
    Auth::guard('patient')->setUser($account);

    return $test;
}

/**
 * @return array{tenant: Tenant, staff: User, patient: Patient, account: PortalAccount, branch: Branch, suspended: Branch, service: Service, resource: resource, upcoming: Appointment}
 */
function ptcFixture(): array
{
    $tenant = Tenant::query()->create(['name' => 'Alpha Clinic', 'slug' => 'alpha', 'region' => 'eu', 'status' => 'active']);
    ptcCtx()->set($tenant);

    $staff = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $staff->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    // One branch that accepts online bookings, and one SOFT-SUSPENDED (BRANCH.P1).
    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'active' => true, 'accepts_online_bookings' => true]);
    $suspended = Branch::query()->create(['name' => 'Annexe', 'code' => 'ANNX', 'active' => true, 'accepts_online_bookings' => false]);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Erika', 'last_name' => 'Baumgartner', 'date_of_birth' => '1954-03-12', 'sex' => 'female',
    ]);

    ConsentTemplate::query()->create([
        'key' => 'portal', 'title' => 'Portal Access', 'body' => 'Portal access consent',
        'version' => 1, 'scope_keys' => ['portal.access'], 'is_active' => true,
    ]);
    app(ConsentService::class)->grant($patient, 'portal', 'Erika Baumgartner', $staff);

    $account = PortalAccount::query()->create([
        'patient_id' => $patient->id,
        'email' => 'erika.appts@example.test',
        'password' => bcrypt('secret-password'),
        'status' => PortalAccount::STATUS_ACTIVE,
    ]);

    $service = Service::query()->create([
        'name' => 'Consultation', 'code' => 'C30', 'category' => 'general',
        'default_duration_minutes' => 30, 'requires_resource_types' => ['practitioner', 'room'],
        'active' => true, 'bookable_online' => true,
    ]);

    /*
     * Bookable resources: available all week, so the REAL AvailableSlotFinder actually returns
     * slots. Without availability rows the finder is correctly empty and the guard test below
     * would pass for the wrong reason — it must fail because the branch is suspended, not because
     * nothing was bookable in the first place.
     */
    $makeResource = function (string $type, string $name, ?Branch $at = null) use ($branch): Resource {
        $resource = Resource::query()->create([
            'type' => $type, 'name' => $name, 'branch_id' => ($at ?? $branch)->id, 'active' => true,
        ]);

        foreach (range(0, 6) as $weekday) {
            ResourceAvailability::query()->create([
                'resource_id' => $resource->id,
                'weekday' => $weekday,
                'start_time' => '00:00',
                'end_time' => '23:59',
            ]);
        }

        return $resource;
    };

    $practitioner = $makeResource(Resource::TYPE_PRACTITIONER, 'Dr. Weber');
    $resource = $makeResource(Resource::TYPE_ROOM, 'Room 1');

    /*
     * THE SUSPENDED BRANCH GETS FULLY BOOKABLE RESOURCES TOO — and this matters.
     *
     * Without them the finder would return nothing there anyway, and "a soft-suspended branch
     * offers no slots" would pass for the WRONG REASON: nothing was bookable in the first place.
     * A mutation removing the BRANCH.P1 guard proved exactly that — it went green. With real
     * resources behind it, the ONLY thing standing between the patient and a slot is
     * `accepts_online_bookings`, so removing the guard now turns the suite red.
     */
    $makeResource(Resource::TYPE_PRACTITIONER, 'Dr. Annexe', $suspended);
    $makeResource(Resource::TYPE_ROOM, 'Annexe Room', $suspended);

    $make = function (string $when, string $status) use ($patient, $service, $branch): Appointment {
        $start = now()->parse($when);

        return Appointment::query()->create([
            'patient_id' => $patient->id, 'service_id' => $service->id, 'branch_id' => $branch->id,
            'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30),
            'status' => $status, 'source' => Appointment::SOURCE_ONLINE,
        ]);
    };

    // THE SPREAD: past · imminent · future · cancelled.
    $make(now()->subDays(21)->setTime(9, 0)->toDateTimeString(), Appointment::STATUS_COMPLETED);
    $imminent = $make(now()->addHours(6)->toDateTimeString(), Appointment::STATUS_CONFIRMED);
    $upcoming = $make(now()->addDays(9)->setTime(14, 15)->toDateTimeString(), Appointment::STATUS_BOOKED);
    $make(now()->addDays(15)->setTime(11, 0)->toDateTimeString(), Appointment::STATUS_CANCELLED);

    Document::query()->create([
        'patient_id' => $patient->id, 'category' => Document::CATEGORY_LETTER, 'title' => 'Referral letter',
        'original_filename' => 'referral.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1024,
        'storage_path' => 'tenants/'.$tenant->id.'/documents/referral.pdf',
        'shared_with_patient' => true, 'uploaded_by' => $staff->id, 'uploaded_at' => now(),
    ]);
    // ...and one NOT shared: the count must not include it.
    Document::query()->create([
        'patient_id' => $patient->id, 'category' => Document::CATEGORY_LETTER, 'title' => 'Internal note',
        'original_filename' => 'internal.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 512,
        'storage_path' => 'tenants/'.$tenant->id.'/documents/internal.pdf',
        'shared_with_patient' => false, 'uploaded_by' => $staff->id, 'uploaded_at' => now(),
    ]);

    return compact('tenant', 'staff', 'patient', 'account', 'branch', 'suspended', 'service', 'resource', 'practitioner', 'upcoming');
}

/** Strip comments so the scans test AFFORDANCES, not the prose documenting their absence. */
function ptcStrip(string $source): string
{
    $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;
    $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;

    return strtolower(preg_replace('~(^|\s)//[^\n]*~m', '$1 ', $source) ?? $source);
}

test('Home counts are SERVER-computed row counts and the balance ties to the engine', function () {
    $fx = ptcFixture();

    ptcCtx()->set($fx['tenant']);
    $engineBalance = app(PatientBalanceReader::class)->outstandingMinorFor($fx['patient']->id);

    ptcCtx()->forget();
    $props = ptcSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.home'))
        ->assertOk()
        ->viewData('page')['props'];

    /*
     * POSITIVE CONTROL (D-174): the fixture holds TWO documents but only ONE is shared. A count
     * that measured the wrong set — or a page-side length over a payload — would read 2.
     */
    expect($props['counts']['documents'])->toBe(1);
    expect($props['counts']['upcomingAppointments'])->toBe(2);

    ptcCtx()->set($fx['tenant']);
    expect($props['counts']['documents'])->toBe(
        Document::query()->where('patient_id', $fx['patient']->id)->where('shared_with_patient', true)->count()
    );
    expect($props['counts']['upcomingAppointments'])->toBe(
        Appointment::query()->where('patient_id', $fx['patient']->id)
            ->whereIn('status', [Appointment::STATUS_BOOKED, Appointment::STATUS_CONFIRMED])
            ->where('starts_at', '>=', now())->count()
    );

    // The balance is PT.P2's reader — one source, tying to the engine (δ=0).
    expect($props['outstandingBalanceMinor'])->toBe($engineBalance);
});

test('Appointments renders the real spread in the right groups, with the recorded branch', function () {
    $fx = ptcFixture();

    ptcCtx()->forget();
    $props = ptcSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.appointments'))
        ->assertOk()
        ->viewData('page')['props'];

    // POSITIVE CONTROL: a genuinely mixed spread, not a single-state list.
    expect($props['upcoming'])->toHaveCount(2)
        ->and($props['past'])->toHaveCount(2);

    // Upcoming holds only future booked/confirmed, soonest first.
    $upcomingStatuses = collect($props['upcoming'])->pluck('status')->all();
    expect($upcomingStatuses)->toBe(['confirmed', 'booked']);

    // A CANCELLED future appointment is NOT "upcoming" — it sits with the past.
    expect(collect($props['past'])->pluck('status')->sort()->values()->all())->toBe(['cancelled', 'completed']);

    // The RECORDED branch reaches the card (a real column the payload had simply not carried).
    expect($props['upcoming'][0]['branch'])->toBe('Main');

    // Only online-bookable branches are offered for booking — the suspended one is absent.
    expect(collect($props['branches'])->pluck('name')->all())->toBe(['Main']);
});

test('THE BOOKING GUARD: real finder slots, a soft-suspended branch offers none and refuses a forged booking', function () {
    $fx = ptcFixture();
    $date = now()->addDays(3)->toDateString();

    // 1) The healthy branch returns REAL finder slots (capped at 12), each carrying resource ids —
    //    proof they came from the finder rather than being composed in the page.
    ptcCtx()->forget();
    $ok = ptcSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->postJson(route('portal.appointments.slots'), [
            'service_id' => $fx['service']->id, 'branch_id' => $fx['branch']->id, 'date' => $date,
        ])
        ->assertOk()
        ->json('slots');

    expect($ok)->not->toBeEmpty('the finder returned no slots — the guard test would prove nothing');
    expect(count($ok))->toBeLessThanOrEqual(12);
    expect($ok[0])->toHaveKeys(['starts_at', 'ends_at', 'resource_ids']);

    // 2) THE SOFT-SUSPENDED BRANCH (BRANCH.P1) offers NO slots — an empty list, not an error.
    ptcCtx()->forget();
    $none = ptcSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->postJson(route('portal.appointments.slots'), [
            'service_id' => $fx['service']->id, 'branch_id' => $fx['suspended']->id, 'date' => $date,
        ])
        ->assertOk()
        ->json('slots');

    expect($none)->toBe([]);

    // 3) ...and a forged booking that skips the slot list entirely is REFUSED.
    ptcCtx()->set($fx['tenant']);
    $before = Appointment::query()->where('patient_id', $fx['patient']->id)->count();

    ptcCtx()->forget();
    ptcSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->post(route('portal.appointments.store'), [
            'service_id' => $fx['service']->id,
            'branch_id' => $fx['suspended']->id,
            'starts_at' => $date.' 09:00:00',
            'resource_ids' => [$fx['resource']->id],
        ])
        /*
         * QA-FIX.1b — CORRECTED, and this is a STRENGTHENING, not a weakening.
         *
         * This previously asserted HTTP 500: the refusal escaped `PortalAppointmentController`
         * uncaught and reached the patient as a crash. A refusal is an ANSWER — the branch is
         * soft-suspended, and saying so is not an error condition. That controller now catches
         * `BookingUnavailableException` and redirects back with a field error, so the assertion
         * moves from "it blew up" to "it was refused cleanly". A 500 on a patient-facing POST is
         * exactly the class the FIX.5 route smoke exists to prevent.
         *
         * THE SUBSTANTIVE ASSERTION IS UNCHANGED and still immediately below: nothing was written.
         */
        ->assertRedirect()
        ->assertSessionHasErrors('starts_at');

    ptcCtx()->set($fx['tenant']);
    expect(Appointment::query()->where('patient_id', $fx['patient']->id)->count())->toBe($before);
});

test('THE OVERLAP GUARD: a patient cannot double-book the same resource', function () {
    $fx = ptcFixture();
    $date = now()->addDays(3)->toDateString();

    ptcCtx()->forget();
    $slots = ptcSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->postJson(route('portal.appointments.slots'), [
            'service_id' => $fx['service']->id, 'branch_id' => $fx['branch']->id, 'date' => $date,
        ])
        ->assertOk()
        ->json('slots');

    expect($slots)->not->toBeEmpty();
    $slot = $slots[0];

    // The first booking succeeds through the REAL path.
    ptcCtx()->forget();
    ptcSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->post(route('portal.appointments.store'), [
            'service_id' => $fx['service']->id,
            'branch_id' => $fx['branch']->id,
            'starts_at' => $slot['starts_at'],
            'resource_ids' => $slot['resource_ids'],
        ])
        ->assertRedirect();

    ptcCtx()->set($fx['tenant']);
    $afterFirst = Appointment::query()->where('patient_id', $fx['patient']->id)->count();

    // The SAME slot again must be refused by the overlap guard — not silently double-booked.
    ptcCtx()->forget();
    ptcSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->post(route('portal.appointments.store'), [
            'service_id' => $fx['service']->id,
            'branch_id' => $fx['branch']->id,
            'starts_at' => $slot['starts_at'],
            'resource_ids' => $slot['resource_ids'],
        ])
        ->assertStatus(500);

    ptcCtx()->set($fx['tenant']);
    expect(Appointment::query()->where('patient_id', $fx['patient']->id)->count())->toBe($afterFirst);
});

test('D-170: the portal offers no reschedule, because the backend has none', function () {
    // POSITIVE CONTROL: the routes that DO exist are registered under these names.
    foreach (['portal.appointments', 'portal.appointments.slots', 'portal.appointments.store', 'portal.appointments.cancel'] as $name) {
        expect(app('router')->has($name))->toBeTrue("{$name} is missing — this absence check would prove nothing");
    }

    // ...and no reschedule route was invented to satisfy the wireframe.
    foreach (['portal.appointments.reschedule', 'portal.appointments.move', 'portal.appointments.change'] as $name) {
        expect(app('router')->has($name))->toBeFalse("a portal reschedule route was invented: {$name}");
    }

    $page = ptcStrip((string) file_get_contents(base_path('resources/js/pages/Portal/Appointments.vue')));
    expect(strlen(trim($page)))->toBeGreaterThan(1000);
    foreach (['reschedule', 'movetoanothertime', 'changeappointment'] as $affordance) {
        expect(str_contains($page, $affordance))->toBeFalse("the page offers '{$affordance}' with no backend behind it");
    }
});

test('THE FENCE: no judgment reaches the patient and nothing is tinted by imminence', function () {
    $fx = ptcFixture();

    ptcCtx()->forget();
    $props = ptcSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.appointments'))
        ->assertOk()
        ->viewData('page')['props'];

    // POSITIVE CONTROL: the payload spans imminent → distant, which is what would tempt a ramp.
    expect($props['upcoming'])->toHaveCount(2)
        ->and($props['past'])->toHaveCount(2);

    $forbidden = [
        'urgency', 'priority', 'noshow', 'no_show', 'riskscore', 'risklevel', 'severity',
        'imminence', 'attendancerisk', 'shouldrebook', 'recommendedaction',
    ];

    $squashed = preg_replace('~[^a-z0-9]~', '', strtolower(json_encode($props) ?: '')) ?? '';
    expect(strlen($squashed))->toBeGreaterThan(400);
    foreach ($forbidden as $token) {
        expect(str_contains($squashed, $token))->toBeFalse("fence token '{$token}' appears in the appointments payload");
    }

    // D-173 — the scan follows every surface this gate touched.
    foreach ([
        base_path('resources/js/pages/Portal/Appointments.vue'),
        base_path('resources/js/pages/Portal/Home.vue'),
        base_path('resources/js/Components/Portal/PortalPageHeader.vue'),
        base_path('resources/js/Components/Portal/PortalEmptyState.vue'),
    ] as $path) {
        expect(file_exists($path))->toBeTrue(basename($path).' is missing — this fence would scan nothing');
        $code = ptcStrip((string) file_get_contents($path));
        $squashedFile = preg_replace('~[^a-z0-9]~', '', $code) ?? '';

        foreach ($forbidden as $token) {
            expect(str_contains($squashedFile, $token))->toBeFalse("fence token '{$token}' appears in ".basename($path));
        }

        /*
         * D-169 — NOTHING may be styled by HOW SOON an appointment is. Emphasising the FIRST row
         * (`index === 0`) is positional — a fact about ordering, the same class as PC.P7's date
         * sort — and stays permitted. What is forbidden is chrome keyed to the clock or the
         * status: a row that turns amber as it approaches is the system telling a patient how to
         * feel about their own appointment.
         */
        preg_match_all('~:(?:class|style)="([^"]*)"~', $code, $bindings);
        foreach ($bindings[1] ?? [] as $binding) {
            foreach (['urgency', 'priority', 'severity', 'hoursuntil', 'daysuntil', 'isimminent', 'issoon'] as $needle) {
                expect(str_contains($binding, $needle))->toBeFalse(basename($path)." styles by imminence: {$binding}");
            }

            /*
             * The line is between IDENTITY and PROXIMITY. `selectedSlot?.starts_at === slot.starts_at`
             * is selection state — which chip the patient clicked — and is ordinary UI, exactly like
             * the filter chips on the access log (PC.P5). What is forbidden is chrome keyed to HOW
             * SOON something is: a time compared against `now`, or against a duration threshold.
             * That is the shape every "turn it amber as it approaches" implementation must take.
             */
            expect(preg_match('~(starts_at|startsat|ends_at)[^"]{0,40}[<>]~i', $binding))
                ->toBe(0, basename($path)." compares a time relationally to style a row: {$binding}");
            expect(preg_match('~\bnow\(\)~', $binding))->toBe(0, basename($path)." styles against the clock: {$binding}");
            expect(preg_match('~[<>]=?\s*\d+\s*\)?\s*\?~', $binding))->toBe(0, basename($path)." styles by a numeric threshold: {$binding}");
        }
    }
});

test('PT.P1 audit rows are unchanged — one per render on both screens', function () {
    $fx = ptcFixture();

    foreach (['portal_home' => route('portal.home'), 'portal_appointments' => route('portal.appointments')] as $surface => $url) {
        ptcCtx()->forget();
        ptcSignIn($this, $fx['account'])
            ->withSession(['portal_tenant_id' => $fx['tenant']->id])
            ->get($url)
            ->assertOk();

        ptcCtx()->set($fx['tenant']);

        // Decoded, never byte-matched (the PT.P1-FIX lesson).
        $rows = DB::table('audit_events')
            ->where('action', 'read')
            ->where('patient_id', $fx['patient']->id)
            ->pluck('context')
            ->filter(function ($context) use ($surface): bool {
                $decoded = json_decode((string) $context, true);

                return is_array($decoded) && ($decoded['surface'] ?? null) === $surface;
            });

        expect($rows)->toHaveCount(1, "{$surface} no longer writes exactly one row per render");
    }
});
