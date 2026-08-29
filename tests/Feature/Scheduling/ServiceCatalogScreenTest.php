<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Permission;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AvailableSlotFinder;
use Modules\Scheduling\Services\ServiceCatalog;

uses(RefreshDatabase::class);

/*
 * SCHED.P2 — the service catalog screen.
 *
 * The screen is thin on purpose: `ServiceCatalog` already owned the write rules. So what these
 * tests pin is (a) that the page shows the REAL engine-read values and nothing money-shaped,
 * (b) that a write cannot happen page-side or outside the service, and (c) that the one source
 * holds — change a duration through the screen and the FINDER's slots change with it.
 */

function scsCtx(): TenantContext
{
    return app(TenantContext::class);
}

/**
 * A user holding exactly the permissions named — never a catalogue role, so a gate is the only
 * thing that can refuse (the COMMS.P1 / GOV.P5 lesson).
 *
 * @param  list<string>  $permissions
 */
function scsUser(Tenant $tenant, array $permissions, string $roleKey): User
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
 * An ASYMMETRIC catalog (D-189): durations, buffers, online and active all differ, and one service
 * is referenced by an appointment while another is not. A symmetric fixture would let a hardcoded
 * payload impersonate the real one.
 *
 * @return array{tenant: Tenant, admin: User, branch: Branch, consult: Service, archived: Service, unused: Service}
 */
function scsFixture(): array
{
    $tenant = Tenant::query()->create(['name' => 'Alpha Clinic', 'slug' => 'alpha', 'region' => 'eu', 'status' => 'active']);
    scsCtx()->set($tenant);

    $admin = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $admin->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    $catalog = app(ServiceCatalog::class);

    // Built through the REAL write path, so the rows are what the service produces.
    $consult = $catalog->create([
        'name' => 'Consultation 30', 'code' => 'C30', 'category' => 'clinical',
        'default_duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 5,
        'requires_resource_types' => ['practitioner'], 'bookable_online' => true, 'active' => true,
    ], [$branch->id]);

    $archived = $catalog->create([
        'name' => 'Legacy 20', 'code' => 'L20', 'category' => 'general',
        'default_duration_minutes' => 20, 'buffer_before_minutes' => 5, 'buffer_after_minutes' => 0,
        'requires_resource_types' => ['practitioner'], 'bookable_online' => false, 'active' => true,
    ], []);
    $catalog->update($archived, ['active' => false]);

    $unused = $catalog->create([
        'name' => 'Minor procedure 45', 'code' => 'M45', 'category' => 'clinical',
        'default_duration_minutes' => 45, 'buffer_before_minutes' => 10, 'buffer_after_minutes' => 15,
        'requires_resource_types' => ['practitioner', 'room'], 'bookable_online' => false, 'active' => true,
    ], [$branch->id]);

    // One service is REFERENCED by an appointment; the others are not.
    $patient = app(PatientService::class)->create([
        'first_name' => 'Nora', 'last_name' => 'Keller', 'date_of_birth' => '1988-03-14', 'sex' => 'female',
    ]);
    Appointment::query()->create([
        'service_id' => $consult->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id,
        'starts_at' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
        'ends_at' => now()->addDay()->setTime(9, 30)->toDateTimeString(),
        'status' => Appointment::STATUS_BOOKED, 'source' => 'staff',
    ]);

    return compact('tenant', 'admin', 'branch', 'consult', 'archived', 'unused');
}

/** Every scalar leaf of a payload, so a scan cannot miss a nested key. */
function scsLeaves(mixed $value, string $prefix = ''): array
{
    if (! is_array($value)) {
        return [$prefix => $value];
    }

    $out = [];
    foreach ($value as $key => $child) {
        $out += scsLeaves($child, $prefix === '' ? (string) $key : $prefix.'.'.$key);
    }

    return $out;
}

/* ------------------------------------------------------------------------ the catalog list */

test('the catalog lists the REAL services with the values the engine reads', function () {
    $f = scsFixture();

    $props = $this->actingAs($f['admin'])->get(route('admin.services.index'))->viewData('page')['props'];
    $byCode = collect($props['services'])->keyBy('code');

    expect($props['services'])->toHaveCount(3);

    // Each engine-read field travels as recorded — asymmetric, so a constant cannot impersonate it.
    expect($byCode['C30']['durationMinutes'])->toBe(30)
        ->and($byCode['C30']['bufferAfterMinutes'])->toBe(5)
        ->and($byCode['M45']['durationMinutes'])->toBe(45)
        ->and($byCode['M45']['bufferBeforeMinutes'])->toBe(10)
        ->and($byCode['M45']['bufferAfterMinutes'])->toBe(15)
        ->and($byCode['M45']['requiresResourceTypes'])->toBe(['practitioner', 'room'])
        ->and($byCode['L20']['active'])->toBeFalse()
        ->and($byCode['C30']['bookableOnline'])->toBeTrue()
        ->and($byCode['M45']['bookableOnline'])->toBeFalse();

    // Usage is a real count: one service is referenced, the others are not.
    expect($byCode['C30']['appointmentCount'])->toBe(1)
        ->and($byCode['M45']['appointmentCount'])->toBe(0);

    /*
     * Counts come from the whole catalog — and asserting ONE set of numbers is not enough: a
     * hardcoded [3, 2, 1] would satisfy it exactly, which a mutation proved. So the catalog is
     * CHANGED and the counts must follow (D-189: the fixture has to be able to tell the two
     * implementations apart).
     */
    expect($props['counts'])->toBe(['total' => 3, 'active' => 2, 'online' => 1]);

    app(ServiceCatalog::class)->create([
        'name' => 'Telehealth 20', 'code' => 'T20', 'category' => 'clinical',
        'default_duration_minutes' => 20, 'requires_resource_types' => ['practitioner'],
        'bookable_online' => true, 'active' => true,
    ], []);

    $after = $this->actingAs($f['admin'])->get(route('admin.services.index'))->viewData('page')['props'];

    expect($after['counts'])->toBe(['total' => 4, 'active' => 3, 'online' => 2])
        ->and($after['services'])->toHaveCount(4);

    // The stride shown is the finder's own constant, not a number retyped in a template.
    expect($props['slotStrideMinutes'])->toBe(AvailableSlotFinder::SLOT_STRIDE_MINUTES);
});

test('filters narrow over real columns, and an empty result is stated honestly', function () {
    $f = scsFixture();

    $archived = $this->actingAs($f['admin'])->get(route('admin.services.index', ['state' => 'archived']))
        ->viewData('page')['props']['services'];
    expect(collect($archived)->pluck('code')->all())->toBe(['L20']);

    $online = $this->actingAs($f['admin'])->get(route('admin.services.index', ['state' => 'online']))
        ->viewData('page')['props']['services'];
    expect(collect($online)->pluck('code')->all())->toBe(['C30']);

    $general = $this->actingAs($f['admin'])->get(route('admin.services.index', ['category' => 'general']))
        ->viewData('page')['props']['services'];
    expect(collect($general)->pluck('code')->all())->toBe(['L20']);

    // A filter that matches nothing returns an empty list — the page says so rather than lying.
    $none = $this->actingAs($f['admin'])->get(route('admin.services.index', ['category' => 'nope']))
        ->viewData('page')['props']['services'];
    expect($none)->toBe([]);

    // POSITIVE CONTROL (D-174): unfiltered, all three are there — so "empty" above means filtered,
    // not broken.
    expect($this->actingAs($f['admin'])->get(route('admin.services.index'))
        ->viewData('page')['props']['services'])->toHaveCount(3);
});

/* --------------------------------------------------------------- writes go through the service */

test('create and edit persist through ServiceCatalog, with its validation intact', function () {
    $f = scsFixture();

    $this->actingAs($f['admin'])->post(route('admin.services.store'), [
        'name' => 'Blood draw 10', 'code' => 'B10', 'category' => 'lab',
        'default_duration_minutes' => 10, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        'requires_resource_types' => ['practitioner'], 'bookable_online' => false, 'active' => true,
        'branch_ids' => [$f['branch']->id],
    ])->assertRedirect(route('admin.services.index'));

    $created = Service::query()->where('code', 'B10')->firstOrFail();
    expect($created->default_duration_minutes)->toBe(10)
        ->and($created->branchLinks()->count())->toBe(1);

    // The service's OWN rule — a per-tenant unique code — still refuses, surfaced as validation
    // rather than a 500.
    $this->actingAs($f['admin'])->post(route('admin.services.store'), [
        'name' => 'Duplicate', 'code' => 'B10',
        'default_duration_minutes' => 15, 'requires_resource_types' => ['practitioner'],
    ])->assertSessionHasErrors('service');

    expect(Service::query()->where('code', 'B10')->count())->toBe(1);

    // A zero duration is refused at the HTTP edge by the request rules.
    $this->actingAs($f['admin'])->post(route('admin.services.store'), [
        'name' => 'Zero', 'code' => 'Z0',
        'default_duration_minutes' => 0, 'requires_resource_types' => ['practitioner'],
    ])->assertSessionHasErrors('default_duration_minutes');

    expect(Service::query()->where('code', 'Z0')->exists())->toBeFalse();

    /*
     * ...AND THE SERVICE REFUSES IT TOO, pinned by calling the service DIRECTLY (D-183).
     *
     * A mutation proved this was needed: deleting ServiceCatalog's own duration rule left the suite
     * green, because the request rule above answered first and the service's guard was never the
     * deciding factor. Same shape as GOV.P5's free-text opt-in and PT.P7's tenant binding — a guard
     * behind another guard needs a subject only IT can refuse. This is that subject: no HTTP layer,
     * no request rules, just the service.
     */
    expect(fn () => app(ServiceCatalog::class)->create([
        'name' => 'Direct zero', 'code' => 'DZ0',
        'default_duration_minutes' => 0, 'requires_resource_types' => ['practitioner'],
    ]))->toThrow(InvalidArgumentException::class);

    // POSITIVE CONTROL (D-182): the identical call with a valid duration succeeds, so the refusal
    // above is the duration rule and not something else in the way.
    $ok = app(ServiceCatalog::class)->create([
        'name' => 'Direct fine', 'code' => 'DZ1',
        'default_duration_minutes' => 25, 'requires_resource_types' => ['practitioner'],
    ]);
    expect($ok->default_duration_minutes)->toBe(25);
});

test('ONE SOURCE — a duration edited on this screen changes what the FINDER offers', function () {
    $f = scsFixture();

    $branch = $f['branch'];
    $resource = Modules\Scheduling\Models\Resource::query()->create([
        'type' => 'practitioner', 'name' => 'Dr. Weber', 'branch_id' => $branch->id, 'active' => true,
    ]);
    foreach (range(0, 6) as $weekday) {
        ResourceAvailability::query()->create([
            'resource_id' => $resource->id, 'weekday' => $weekday,
            'start_time' => '08:00', 'end_time' => '12:00',
        ]);
    }

    $finder = app(AvailableSlotFinder::class);
    $date = now()->addDays(2)->toDateString();

    $before = $finder->forServiceBranchDate($f['consult']->refresh(), $branch->id, $date);

    // POSITIVE CONTROL: the finder produces slots at all, so the comparison below is meaningful.
    expect($before)->not->toBeEmpty();
    $firstBefore = $before[0];

    // Edit the duration through the SCREEN, then ask the FINDER again.
    $this->actingAs($f['admin'])->post(route('admin.services.update', ['service' => $f['consult']->id]), [
        'default_duration_minutes' => 90,
    ])->assertRedirect(route('admin.services.index'));

    $after = $finder->forServiceBranchDate($f['consult']->refresh(), $branch->id, $date);

    expect($after)->not->toBeEmpty();

    // The slot LENGTH the finder generates now follows the edited value — proof that the page and
    // the engine read the same field, with no second copy of the duration anywhere.
    $lengthAfter = (int) CarbonImmutable::parse($after[0]['starts_at'])
        ->diffInMinutes(CarbonImmutable::parse($after[0]['ends_at']));
    $lengthBefore = (int) CarbonImmutable::parse($firstBefore['starts_at'])
        ->diffInMinutes(CarbonImmutable::parse($firstBefore['ends_at']));

    expect($lengthBefore)->toBe(30)->and($lengthAfter)->toBe(90);
});

/* ------------------------------------------------------------- no money on a Service (D-184 shape) */

test('a Service carries NO money — with a positive control that pricing exists in Billing', function () {
    $f = scsFixture();

    // The column list itself.
    $columns = collect(DB::select('SHOW COLUMNS FROM services'))->pluck('Field');
    expect($columns)->not->toBeEmpty();

    foreach ($columns as $column) {
        expect(preg_match('/price|amount|minor|tariff|cost|fee/i', (string) $column))
            ->toBe(0, "services.{$column} would be a second source for money");
    }

    // ...and the payload the page receives.
    $props = $this->actingAs($f['admin'])->get(route('admin.services.index'))->viewData('page')['props'];
    expect($props['services'])->not->toBeEmpty();

    foreach (array_keys(scsLeaves($props)) as $path) {
        foreach (['price', 'tariff', 'amount', 'minor', 'cost', 'fee'] as $needle) {
            expect(str_contains(strtolower($path), $needle))
                ->toBeFalse("service payload must not carry a '{$needle}' key; found at {$path}");
        }
    }

    /*
     * THE POSITIVE CONTROL (D-184 shape): money is not missing from the product — it lives in the
     * tenant-authored Billing tariff catalog. Without this, the assertions above would also pass in
     * a product that simply had no pricing at all, which is a different (and wrong) claim.
     */
    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1,
        'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => [],
    ]);
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id, 'code' => '00.0010', 'description' => 'Consultation',
        'unit_price_minor' => 18000, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);

    expect($item->unit_price_minor)->toBe(18000);
});

/* --------------------------------------------------------------- archive, never orphan (D-182) */

test('a service referenced by an appointment cannot be deleted — the database refuses', function () {
    $f = scsFixture();

    // POSITIVE CONTROL: an UNREFERENCED service deletes cleanly, so the refusal below is about the
    // reference and not about deletion being broken in general.
    app(ServiceCatalog::class)->delete($f['unused']);
    expect(Service::query()->whereKey($f['unused']->id)->exists())->toBeFalse();

    // The referenced one cannot go: `appointments.service_id` is ON DELETE RESTRICT. Without that
    // rule this would succeed and orphan a booked appointment — which is exactly why the screen
    // offers no delete at all.
    expect(fn () => app(ServiceCatalog::class)->delete($f['consult']))
        ->toThrow(QueryException::class);

    expect(Service::query()->whereKey($f['consult']->id)->exists())->toBeTrue()
        ->and(Appointment::query()->where('service_id', $f['consult']->id)->count())->toBe(1);

    // The screen exposes no destroy route at all.
    expect(collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r): bool => str_starts_with((string) $r->getName(), 'admin.services.'))
        ->map(fn ($r) => $r->getName())
        ->values()
        ->all())
        ->toBe(['admin.services.index', 'admin.services.store', 'admin.services.update', 'admin.services.active']);
});

test('archiving stops NEW use without touching existing appointments', function () {
    $f = scsFixture();

    $this->actingAs($f['admin'])->post(route('admin.services.active', ['service' => $f['consult']->id]), ['active' => false])
        ->assertRedirect(route('admin.services.index'));

    expect(Service::query()->whereKey($f['consult']->id)->value('active'))->toBeFalse();

    // The appointment that referenced it is untouched — still there, still pointing at it.
    expect(Appointment::query()->where('service_id', $f['consult']->id)->count())->toBe(1);

    // And it can be brought back.
    $this->actingAs($f['admin'])->post(route('admin.services.active', ['service' => $f['consult']->id]), ['active' => true]);
    expect(Service::query()->whereKey($f['consult']->id)->value('active'))->toBeTrue();
});

/* ------------------------------------------------------------------ permission + tenancy (D-183) */

test('the catalog is permission-gated and tenant-scoped fail-closed', function () {
    $f = scsFixture();

    // Holding appointment.manage is NOT enough — this is tenant configuration.
    $scheduler = scsUser($f['tenant'], ['appointment.manage'], 'scheduler_only');
    $this->actingAs($scheduler)->get(route('admin.services.index'))->assertForbidden();
    $this->actingAs($scheduler)->post(route('admin.services.store'), [
        'name' => 'X', 'code' => 'X1', 'default_duration_minutes' => 15, 'requires_resource_types' => ['practitioner'],
    ])->assertForbidden();
    $this->actingAs($scheduler)->post(route('admin.services.update', ['service' => $f['consult']->id]), [
        'default_duration_minutes' => 99,
    ])->assertForbidden();

    // Nothing was written by the refused attempts.
    expect(Service::query()->where('code', 'X1')->exists())->toBeFalse()
        ->and(Service::query()->whereKey($f['consult']->id)->value('default_duration_minutes'))->toBe(30);

    // POSITIVE CONTROL: admin.manage is the only thing that was in the way.
    $admin = scsUser($f['tenant'], ['admin.manage'], 'config_admin');
    $this->actingAs($admin)->get(route('admin.services.index'))->assertOk();

    // A second tenant's service is a 404 — the screen cannot confirm the id exists, let alone edit it.
    $other = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta', 'region' => 'eu', 'status' => 'active']);
    scsCtx()->forget();
    scsCtx()->set($other);
    $otherAdmin = User::factory()->forTenant($other)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $otherAdmin->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    $this->actingAs($otherAdmin)->post(route('admin.services.update', ['service' => $f['consult']->id]), [
        'default_duration_minutes' => 5,
    ])->assertNotFound();

    $this->actingAs($otherAdmin)->post(route('admin.services.active', ['service' => $f['consult']->id]), ['active' => false])
        ->assertNotFound();
});

/* ------------------------------------------------------------------------------ the fence scan */

test('no unbacked setting appears in the payload or the component', function () {
    $f = scsFixture();

    $props = $this->actingAs($f['admin'])->get(route('admin.services.index'))->viewData('page')['props'];
    expect($props['services'])->not->toBeEmpty();

    $forbidden = ['granularity', 'min_notice', 'minnotice', 'provider_buffer', 'providerbuffer',
        'suggested_duration', 'suggestedduration', 'typical', 'predicted', 'forecast', 'utilisation', 'utilization'];

    foreach (array_keys(scsLeaves($props)) as $path) {
        foreach ($forbidden as $needle) {
            expect(str_contains(strtolower($path), $needle))
                ->toBeFalse("service payload must not carry a '{$needle}' key; found at {$path}");
        }
    }

    // The component draws none of them either. The scan resolves its subject first, so it cannot go
    // silent if the file moves (D-173).
    $page = resource_path('js/pages/Admin/ServiceCatalog.vue');
    expect(file_exists($page))->toBeTrue("the scanned component must exist at {$page}");
    $source = (string) file_get_contents($page);
    expect(strlen($source))->toBeGreaterThan(2000);

    /*
     * Scan for the AFFORDANCE, never the word: the omission card must NAME these things in order to
     * say they are absent, so forbidding the strings themselves would forbid the honesty. This is
     * the COMMS.P2 shape — `startRecording`, not "recording".
     */
    foreach (['form.price', 'form.granularity', 'form.min_notice', 'form.provider_buffer', 'form.suggested_duration'] as $affordance) {
        expect(str_contains($source, $affordance))->toBeFalse("{$page} must not bind {$affordance}");
    }

    /*
     * Binding-name scanning alone is too narrow — a mutation added `granularity` to the form object
     * without ever writing the string `form.granularity`, and slipped through. So each declined
     * concept is also COUNTED: it may appear exactly where the omission list names it, and nowhere
     * else. A second occurrence means something started using it.
     */
    foreach (['granularity' => 1, 'minNotice' => 1, 'providerBuffer' => 1, 'suggestedDuration' => 1] as $concept => $allowed) {
        expect(substr_count($source, $concept))
            ->toBe($allowed, "{$concept} may appear only in the omission list that declines it");
    }
});

test('the omission card is RENDERED, and names where pricing actually lives', function () {
    // GOV.P3's lesson: assert the keys the component iterates, not merely the copy file.
    $source = (string) file_get_contents(resource_path('js/pages/Admin/ServiceCatalog.vue'));

    expect($source)->toContain("const omittedKeys = ['price', 'granularity', 'minNotice', 'providerBuffer', 'suggestedDuration']")
        ->and($source)->toContain('serviceCatalog.omitted.${key}')
        ->and($source)->toContain("t('serviceCatalog.omitted.title')")
        // The engine explainer is rendered too, and carries the finder's real stride.
        ->and($source)->toContain("t('serviceCatalog.engine.stride', { minutes: slotStrideMinutes })");

    $copy = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);
    $omitted = $copy['serviceCatalog']['omitted'];

    foreach (['price', 'granularity', 'minNotice', 'providerBuffer', 'suggestedDuration'] as $key) {
        expect($omitted)->toHaveKey($key);
        expect(strlen((string) $omitted[$key]))->toBeGreaterThan(60);
    }

    // The price statement must point somewhere real, not just decline.
    expect(strtolower($omitted['price']))->toContain('billing')
        ->and(strtolower($omitted['price']))->toContain('invoice')
        ->and(strtolower($omitted['granularity']))->toContain('stride')
        ->and(strtolower($omitted['minNotice']))->toContain('notice');
});
