<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Permission;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AvailabilityService;
use Modules\Scheduling\Services\AvailableSlotFinder;
use Modules\Scheduling\Services\BookingService;

uses(RefreshDatabase::class);

/*
 * SCHED.P3 — provider / resource availability.
 *
 * Two things carry the weight here. ONE SOURCE: the page's "effective hours" must be the slot
 * finder's own answer, proven by changing availability and watching the finder's slots move. And
 * THE CONSEQUENCE: withdrawing availability under a booked appointment is NOT guarded anywhere in
 * this system, and these tests pin that fact so a future gate cannot assume otherwise.
 */

function apCtx(): TenantContext
{
    return app(TenantContext::class);
}

/**
 * @param  list<string>  $permissions
 */
function apUser(Tenant $tenant, array $permissions, string $roleKey): User
{
    $role = Role::query()->create(['key' => $roleKey, 'name' => $roleKey, 'is_system' => false]);

    foreach ($permissions as $key) {
        $role->permissions()->attach(Permission::query()->where('key', $key)->firstOrFail()->id);
    }

    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id]);

    return $user;
}

/**
 * An AWKWARD availability fixture (D-189): different weekly templates, one resource with NO
 * availability at all, one on a partial week, dated exceptions of BOTH kinds with recorded reasons,
 * a full-day block, an exception over a BOOKED appointment, and a soft-suspended branch beside a
 * bookable one.
 *
 * @return array{tenant: Tenant, actor: User, branch: Branch, suspended: Branch, service: Service, full: resource, partial: resource, bare: resource, booked: Appointment, clashDate: string, monday: string}
 */
function apFixture(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Alpha Clinic', 'slug' => 'alpha-'.Str::lower(Str::random(8)),
        'region' => 'eu', 'status' => 'active',
    ]);
    apCtx()->set($tenant);

    $actor = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $actor->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    // BRANCH.P1: soft-suspended — online booking paused, availability and staff booking unaffected.
    $suspended = Branch::query()->create([
        'name' => 'Annexe', 'code' => 'ANNEX', 'timezone' => 'Europe/Zurich',
        'active' => true, 'accepts_online_bookings' => false,
    ]);

    $service = Service::query()->create([
        'name' => 'Consultation 30', 'code' => 'C30', 'category' => 'clinical',
        'default_duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        'requires_resource_types' => ['practitioner'], 'bookable_online' => true, 'active' => true,
    ]);

    $make = fn (string $name): Resource => Resource::query()->create([
        'type' => 'practitioner', 'name' => $name, 'branch_id' => $branch->id, 'active' => true,
    ]);

    // FULL week, 08:00–18:00 every day.
    $full = $make('Dr. Full');
    foreach (range(0, 6) as $weekday) {
        ResourceAvailability::query()->create([
            'resource_id' => $full->id, 'weekday' => $weekday, 'start_time' => '08:00', 'end_time' => '18:00',
        ]);
    }

    // PARTIAL week — Tuesday morning and Thursday afternoon only.
    $partial = $make('Dr. Partial');
    ResourceAvailability::query()->create([
        'resource_id' => $partial->id, 'weekday' => 2, 'start_time' => '08:00', 'end_time' => '12:00',
    ]);
    ResourceAvailability::query()->create([
        'resource_id' => $partial->id, 'weekday' => 4, 'start_time' => '13:00', 'end_time' => '16:30',
    ]);

    // NO availability at all — the empty template the page must show honestly.
    $bare = $make('Dr. Bare');

    // A booked appointment on the coming Monday, inside Dr. Full's hours.
    $monday = now()->startOfWeek()->addWeek();
    $patient = app(PatientService::class)->create([
        'first_name' => 'Nora', 'last_name' => 'Keller', 'date_of_birth' => '1988-03-14', 'sex' => 'female',
    ]);
    $booked = app(BookingService::class)->book(
        $service->id, $patient->id, $branch->id,
        $monday->copy()->setTime(9, 0)->toDateTimeString(), [$full->id], $actor,
    );

    // Dated exceptions of BOTH kinds, each with a recorded reason.
    ResourceAvailability::query()->create([
        'resource_id' => $full->id, 'date' => $monday->copy()->addDay()->toDateString(),
        'start_time' => '09:00', 'end_time' => '12:00', 'is_available' => true,
        'reason' => 'Zusatzsprechstunde nach Grippewelle',
    ]);
    ResourceAvailability::query()->create([
        'resource_id' => $full->id, 'date' => $monday->copy()->addDays(2)->toDateString(),
        'start_time' => null, 'end_time' => null, 'is_available' => false, 'reason' => 'Feiertag',
    ]);

    return [
        'tenant' => $tenant, 'actor' => $actor, 'branch' => $branch, 'suspended' => $suspended,
        'service' => $service, 'full' => $full, 'partial' => $partial, 'bare' => $bare,
        'booked' => $booked, 'clashDate' => $monday->toDateString(), 'monday' => $monday->toDateString(),
    ];
}

/** Every scalar leaf of a payload, so a scan cannot miss a nested key. */
function apLeaves(mixed $value, string $prefix = ''): array
{
    if (! is_array($value)) {
        return [$prefix => $value];
    }

    $out = [];
    foreach ($value as $key => $child) {
        $out += apLeaves($child, $prefix === '' ? (string) $key : $prefix.'.'.$key);
    }

    return $out;
}

function apPage($test, array $f): array
{
    return $test->actingAs($f['actor'])
        ->get(route('scheduling.availability.index', ['branch_id' => $f['branch']->id, 'week' => $f['monday']]))
        ->viewData('page')['props'];
}

/* ------------------------------------------------------------------------------- the view */

test('the view renders REAL templates and exceptions, including an empty one, with recorded reasons', function () {
    $f = apFixture();
    $props = apPage($this, $f);
    $byId = collect($props['resources'])->keyBy('id');

    expect($props['resources'])->toHaveCount(3);

    // Awkward on purpose: 7 windows, 2 windows, and none at all.
    expect($byId[$f['full']->id]['template'])->toHaveCount(7)
        ->and($byId[$f['partial']->id]['template'])->toHaveCount(2)
        ->and($byId[$f['bare']->id]['template'])->toBe([]);

    // The partial week is the RECORDED pattern, not a normalised one.
    $partial = collect($byId[$f['partial']->id]['template']);
    expect($partial->pluck('weekday')->all())->toBe([2, 4])
        ->and($partial->firstWhere('weekday', 4)['startTime'])->toBe('13:00');

    // Both kinds of exception, each carrying the author's own words verbatim.
    $exceptions = collect($byId[$f['full']->id]['exceptions']);
    expect($exceptions)->toHaveCount(2);

    $extra = $exceptions->firstWhere('isAvailable', true);
    $block = $exceptions->firstWhere('isAvailable', false);

    expect($extra['reason'])->toBe('Zusatzsprechstunde nach Grippewelle')
        ->and($extra['startTime'])->toBe('09:00')
        ->and($block['reason'])->toBe('Feiertag')
        ->and($block['fullDay'])->toBeTrue()
        ->and($block['startTime'])->toBeNull();

    /*
     * Counts are plain and asymmetric — and asserting ONE set is not enough: a hardcoded
     * [3, 1, 2] satisfies it exactly, which a mutation proved. So the catalog is CHANGED and the
     * counts must follow. (D-189, sixth appearance; the same fix as SCHED.P2's.)
     */
    expect($props['counts'])->toBe(['resources' => 3, 'withoutTemplate' => 1, 'exceptions' => 2]);

    $extraResource = Resource::query()->create([
        'type' => 'room', 'name' => 'Raum Neu', 'branch_id' => $f['branch']->id, 'active' => true,
    ]);
    ResourceAvailability::query()->create([
        'resource_id' => $f['bare']->id, 'date' => $f['monday'],
        'start_time' => null, 'end_time' => null, 'is_available' => false, 'reason' => 'Betriebsausflug',
    ]);

    $after = apPage($this, $f);

    // One more resource (itself template-less), and one more exception.
    expect($after['counts'])->toBe(['resources' => 4, 'withoutTemplate' => 2, 'exceptions' => 3])
        ->and(collect($after['resources'])->pluck('id'))->toContain($extraResource->id);
});

test('the effective hours are the SLOT FINDER READER\'s answer, including the precedence rules', function () {
    $f = apFixture();
    $props = apPage($this, $f);
    $byId = collect($props['resources'])->keyBy('id');

    $reader = app(AvailabilityService::class);
    $expected = array_map(
        fn (array $w): array => [
            'date' => $w['date'],
            'startTime' => $w['start_at']->format('H:i'),
            'endTime' => $w['end_at']->format('H:i'),
        ],
        $reader->windowsFor($f['full'], $f['monday'], now()->parse($f['monday'])->addDays(6)->toDateString()),
    );

    // Identical to the reader's own output — the page computes nothing of its own.
    expect($byId[$f['full']->id]['effective'])->toBe($expected)->and($expected)->not->toBeEmpty();

    $byDate = collect($byId[$f['full']->id]['effective'])->groupBy('date');
    $tuesday = now()->parse($f['monday'])->addDay()->toDateString();
    $wednesday = now()->parse($f['monday'])->addDays(2)->toDateString();

    // PRECEDENCE 1 — a dated AVAILABLE row REPLACES the weekly window rather than adding to it.
    expect($byDate[$tuesday]->pluck('startTime')->all())->toBe(['09:00'])
        ->and($byDate[$tuesday]->pluck('endTime')->all())->toBe(['12:00']);

    // PRECEDENCE 2 — a FULL-DAY block empties the day entirely.
    expect($byDate->has($wednesday))->toBeFalse();

    // POSITIVE CONTROL (D-174): an untouched day still shows the weekly window, so the two above
    // are the exceptions doing their work rather than the week being empty.
    expect($byDate[$f['monday']]->pluck('startTime')->all())->toBe(['08:00']);

    // A resource with no template has no effective hours — stated, not hidden.
    expect($byId[$f['bare']->id]['effective'])->toBe([]);
});

/* ------------------------------------------------------------------------- ONE SOURCE, by effect */

test('ONE SOURCE — an exception added through the page changes what the FINDER offers', function () {
    $f = apFixture();
    $finder = app(AvailableSlotFinder::class);

    $friday = now()->parse($f['monday'])->addDays(4)->toDateString();

    $before = $finder->forServiceBranchDate($f['service']->refresh(), $f['branch']->id, $friday);

    // POSITIVE CONTROL: the finder offers slots on this day before anything is withdrawn.
    expect($before)->not->toBeEmpty();

    // Withdraw the morning THROUGH THE PAGE.
    $this->actingAs($f['actor'])->post(route('scheduling.availability.store'), [
        'resource_id' => $f['full']->id,
        'branch_id' => $f['branch']->id,
        'date' => $friday,
        'start_time' => '08:00',
        'end_time' => '13:00',
        'is_available' => false,
        'reason' => 'Zahnärztlicher Notfalldienst',
    ])->assertRedirect();

    $after = $finder->forServiceBranchDate($f['service']->refresh(), $f['branch']->id, $friday);

    // The finder's own answer moved: nothing before 13:00 survives.
    expect($after)->not->toBeEmpty();
    foreach ($after as $slot) {
        expect(substr((string) $slot['starts_at'], 11, 5))->toBeGreaterThanOrEqual('13:00');
    }
    expect(count($after))->toBeLessThan(count($before));

    // ...and the page's effective hours agree, because they are the same reader.
    $props = $this->actingAs($f['actor'])->get(route('scheduling.availability.index', [
        'branch_id' => $f['branch']->id, 'week' => $f['monday'],
    ]))->viewData('page')['props'];

    $effectiveFriday = collect(collect($props['resources'])->firstWhere('id', $f['full']->id)['effective'])
        ->where('date', $friday);

    expect($effectiveFriday->pluck('startTime')->all())->toBe(['13:00']);
});

/* --------------------------------------------------------------- writes go through the service */

test('edits go through the writer, and the MODEL\'s own validation still refuses a bad shape', function () {
    $f = apFixture();

    $this->actingAs($f['actor'])->post(route('scheduling.availability.store'), [
        'resource_id' => $f['bare']->id,
        'branch_id' => $f['branch']->id,
        'weekday' => 3,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ])->assertRedirect();

    $created = ResourceAvailability::query()->where('resource_id', $f['bare']->id)->firstOrFail();
    expect($created->weekday)->toBe(3);

    // The MODEL owns the shape rules — end must be after start. Surfaced as validation, not a 500.
    $this->actingAs($f['actor'])->post(route('scheduling.availability.store'), [
        'resource_id' => $f['bare']->id,
        'branch_id' => $f['branch']->id,
        'weekday' => 5,
        'start_time' => '17:00',
        'end_time' => '09:00',
    ])->assertSessionHasErrors('availability');

    expect(ResourceAvailability::query()->where('resource_id', $f['bare']->id)->count())->toBe(1);

    // A weekday outside 0-6 is refused at the request edge.
    $this->actingAs($f['actor'])->post(route('scheduling.availability.store'), [
        'resource_id' => $f['bare']->id, 'branch_id' => $f['branch']->id,
        'weekday' => 9, 'start_time' => '09:00', 'end_time' => '10:00',
    ])->assertSessionHasErrors('weekday');

    // Update and delete both persist through the writer.
    $this->actingAs($f['actor'])->post(route('scheduling.availability.update', ['availability' => $created->id]), [
        'branch_id' => $f['branch']->id, 'start_time' => '10:00', 'end_time' => '16:00',
    ])->assertRedirect();

    expect(ResourceAvailability::query()->whereKey($created->id)->value('start_time'))->toStartWith('10:00');

    $this->actingAs($f['actor'])->post(route('scheduling.availability.destroy', ['availability' => $created->id]), [
        'branch_id' => $f['branch']->id,
    ])->assertRedirect();

    expect(ResourceAvailability::query()->whereKey($created->id)->exists())->toBeFalse();
});

/* ------------------------------------------------- the consequence: UNGUARDED, and pinned as such */

test('withdrawing availability over a BOOKED appointment is NOT blocked — the gap is pinned', function () {
    $f = apFixture();

    $appointmentId = $f['booked']->id;
    $before = Appointment::query()->whereKey($appointmentId)->first();

    expect($before->status)->toBe(Appointment::STATUS_BOOKED);

    // The page reports the impact honestly BEFORE saving...
    $impact = $this->actingAs($f['actor'])->postJson(route('scheduling.availability.impact'), [
        'resource_id' => $f['full']->id,
        'branch_id' => $f['branch']->id,
        'date' => $f['clashDate'],
        'start_time' => '08:00',
        'end_time' => '18:00',
    ]);

    $impact->assertOk();
    expect($impact->json('appointments'))->toBe(1);

    // ...and then the save SUCCEEDS anyway, because nothing guards it.
    $this->actingAs($f['actor'])->post(route('scheduling.availability.store'), [
        'resource_id' => $f['full']->id,
        'branch_id' => $f['branch']->id,
        'date' => $f['clashDate'],
        'start_time' => '08:00',
        'end_time' => '18:00',
        'is_available' => false,
        'reason' => 'Fortbildung',
    ])->assertRedirect();

    /*
     * THE FACT THIS TEST EXISTS TO PIN: the appointment is untouched — not moved, not cancelled, not
     * flagged — and now sits outside its resource's hours with nobody told. `assertWithinAvailability`
     * runs at BOOKING time only.
     *
     * This is asserted rather than fixed on purpose. If a future gate adds a guard, this test fails
     * and forces the decision to be explicit instead of arriving by accident.
     */
    $after = Appointment::query()->whereKey($appointmentId)->first();

    expect($after)->not->toBeNull()
        ->and($after->status)->toBe(Appointment::STATUS_BOOKED)
        ->and($after->starts_at->toDateTimeString())->toBe($before->starts_at->toDateTimeString());

    // And the resource genuinely has no hours left that day — so the appointment really is stranded.
    expect(app(AvailabilityService::class)->windowsFor($f['full'], $f['clashDate'], $f['clashDate']))->toBe([]);

    // The page states this rather than implying a protection it has not got.
    $copy = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);
    expect(strtolower($copy['availability']['impact']['warning']))->toContain('will not move')
        ->and(strtolower($copy['availability']['omitted']['guardedWithdrawal']))->toContain('does not guard');
});

/* ------------------------------------------------------------------ permission + tenancy (D-183) */

test('availability is permission-gated and tenant-scoped fail-closed', function () {
    $f = apFixture();

    $stranger = apUser($f['tenant'], ['patient.view'], 'no_appointments');
    $this->actingAs($stranger)->get(route('scheduling.availability.index', ['branch_id' => $f['branch']->id]))->assertForbidden();
    $this->actingAs($stranger)->post(route('scheduling.availability.store'), [
        'resource_id' => $f['full']->id, 'branch_id' => $f['branch']->id,
        'weekday' => 1, 'start_time' => '08:00', 'end_time' => '09:00',
    ])->assertForbidden();

    // POSITIVE CONTROL: appointment.manage is the only thing in the way.
    $scheduler = apUser($f['tenant'], ['appointment.manage'], 'scheduler');
    $this->actingAs($scheduler)->get(route('scheduling.availability.index', ['branch_id' => $f['branch']->id]))->assertOk();

    // A second tenant's availability row is a 404 — never an edit.
    $rowId = ResourceAvailability::query()->where('resource_id', $f['full']->id)->firstOrFail()->id;

    $other = Tenant::query()->create([
        'name' => 'Beta', 'slug' => 'beta-'.Str::lower(Str::random(6)), 'region' => 'eu', 'status' => 'active',
    ]);
    apCtx()->forget();
    apCtx()->set($other);
    $otherAdmin = User::factory()->forTenant($other)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $otherAdmin->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    $this->actingAs($otherAdmin)->post(route('scheduling.availability.update', ['availability' => $rowId]), [
        'start_time' => '00:00', 'end_time' => '23:00',
    ])->assertNotFound();

    $this->actingAs($otherAdmin)->post(route('scheduling.availability.destroy', ['availability' => $rowId]), [])
        ->assertNotFound();
});

/* -------------------------------------------------------------- soft-suspend, stated honestly */

test('a soft-suspended branch still has availability, and the page says so', function () {
    $f = apFixture();

    $props = $this->actingAs($f['actor'])->get(route('scheduling.availability.index', [
        'branch_id' => $f['suspended']->id,
    ]))->viewData('page')['props'];

    // BRANCH.P1: online booking is off, but that is not the same as having no hours.
    expect($props['branchOnlineBookings'])->toBeFalse();

    $copy = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);
    expect(strtolower($copy['availability']['engine']['onlineSuspended']))->toContain('staff booking continues')
        ->and(strtolower($copy['availability']['engine']['onlineSuspended']))->toContain('unaffected');

    // POSITIVE CONTROL: the bookable branch reports the other way.
    $open = $this->actingAs($f['actor'])->get(route('scheduling.availability.index', [
        'branch_id' => $f['branch']->id,
    ]))->viewData('page')['props'];

    expect($open['branchOnlineBookings'])->toBeTrue();
});

/* ------------------------------------------------------------------------------ the fence scan */

test('no suggestion, forecast, auto-template or ranking key in the payload or the component', function () {
    $f = apFixture();
    $props = apPage($this, $f);

    expect($props['resources'])->not->toBeEmpty();

    $forbidden = ['suggest', 'forecast', 'auto_template', 'autotemplate', 'rank', 'underbooked',
        'predicted', 'recommend', 'optimal', 'score'];

    foreach (array_keys(apLeaves($props)) as $path) {
        foreach ($forbidden as $needle) {
            expect(str_contains(strtolower($path), $needle))
                ->toBeFalse("availability payload must not carry '{$needle}' ({$path})");
        }
    }

    $page = resource_path('js/pages/Scheduling/Availability.vue');
    expect(file_exists($page))->toBeTrue("the scanned component must exist at {$page}");
    $source = (string) file_get_contents($page);
    expect(strlen($source))->toBeGreaterThan(2000);

    // Scan for the AFFORDANCE, not the word: the omission list must NAME these to decline them.
    foreach (['form.suggested', 'autoTemplate(', 'rankResources', 'form.forecast'] as $affordance) {
        expect(str_contains($source, $affordance))->toBeFalse("{$page} must not carry {$affordance}");
    }

    // Each declined concept appears exactly once — in the omission list that declines it.
    foreach (['suggested', 'forecast', 'autoTemplate', 'ranking'] as $concept) {
        expect(substr_count($source, $concept))
            ->toBe(1, "{$concept} may appear only in the omission list");
    }
});

test('the omission card is RENDERED and reachable, and names each refusal', function () {
    $source = (string) file_get_contents(resource_path('js/pages/Scheduling/Availability.vue'));

    expect($source)->toContain("const omittedKeys = ['suggested', 'forecast', 'autoTemplate', 'ranking', 'guardedWithdrawal']")
        ->and($source)->toContain('availability.omitted.${key}')
        ->and($source)->toContain("t('availability.omitted.title')");

    /*
     * REACHABILITY, not just presence. SCHED.P1's browser check found an omission card that rendered
     * nowhere because it sat inside a `v-if` dialog while every source assertion still passed. The
     * editor here is conditional, so the card must come AFTER it and outside it.
     */
    $cardAt = strpos($source, "t('availability.omitted.title')");
    $editorAt = strpos($source, 'v-if="open"');
    expect($cardAt)->not->toBeFalse()->and($editorAt)->not->toBeFalse();
    expect($cardAt > $editorAt)->toBeTrue('the omission card must sit outside the conditional editor');

    $copy = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);
    $omitted = $copy['availability']['omitted'];

    foreach (['suggested', 'forecast', 'autoTemplate', 'ranking', 'guardedWithdrawal'] as $key) {
        expect($omitted)->toHaveKey($key);
        expect(strlen((string) $omitted[$key]))->toBeGreaterThan(60);
    }

    expect(strtolower($omitted['suggested']))->toContain('suggested')
        ->and(strtolower($omitted['forecast']))->toContain('predict')
        ->and(strtolower($omitted['ranking']))->toContain('ratio');
});
