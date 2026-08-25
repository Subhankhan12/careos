<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Comms\Contracts\TelehealthProvider;
use Modules\Comms\Models\TelehealthParticipant;
use Modules\Comms\Models\TelehealthSession;
use Modules\Comms\Providers\Telehealth\FakeTelehealthProvider;
use Modules\Comms\Services\TelehealthService;
use Modules\Comms\Services\TelehealthSessionReader;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Permission;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Service;

uses(RefreshDatabase::class);

/*
 * COMMS.P2 — the telehealth sessions list and the pre-join surface.
 *
 * The surfaces here DISPLAY; they must not acquire a capability the machinery does not have. So most
 * of what follows is about what cannot appear: a recording control, a stored token, a live-presence
 * claim, a quality score, or a mutated participant row.
 */

function thpCtx(): TenantContext
{
    return app(TenantContext::class);
}

function thpFake(): FakeTelehealthProvider
{
    $fake = app(FakeTelehealthProvider::class);
    app()->instance(TelehealthProvider::class, $fake);

    return $fake;
}

/**
 * A user holding exactly the permissions named — not a catalogue role, so a gate is the only thing
 * that can refuse (the COMMS.P1 / GOV.P5 lesson).
 *
 * @param  list<string>  $permissions
 */
function thpUser(Tenant $tenant, array $permissions, string $roleKey): User
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
 * Three sessions, each reaching its state through the REAL service path — the same way the demo
 * seeder builds them. Nothing is hand-inserted, so a state the page shows is a state the code
 * actually produced.
 *
 * @return array{tenant: Tenant, actor: User, practitioner: StaffProfile, scheduled: TelehealthSession, joined: TelehealthSession, ended: TelehealthSession}
 */
function thpFixture(): array
{
    thpFake();

    $tenant = Tenant::query()->create(['name' => 'Alpha Clinic', 'slug' => 'alpha', 'region' => 'eu', 'status' => 'active']);
    thpCtx()->set($tenant);

    $actor = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $actor->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN']);
    $practitioner = StaffProfile::query()->create([
        'user_id' => $actor->id,
        'first_name' => 'Marc', 'last_name' => 'Brunner', 'display_name' => 'Dr. M. Brunner',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id,
    ]);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Luca', 'last_name' => 'Bernasconi', 'date_of_birth' => '1984-02-11', 'sex' => 'male',
    ]);

    $service = Service::query()->create([
        'name' => 'Video consult', 'code' => 'VID30', 'category' => 'general',
        'default_duration_minutes' => 30, 'requires_resource_types' => ['practitioner'],
        'bookable_online' => true, 'active' => true,
    ]);

    $appointment = fn (int $hour) => Appointment::query()->create([
        'service_id' => $service->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id,
        'starts_at' => now()->addDay()->setTime($hour, 0)->toDateTimeString(),
        'ends_at' => now()->addDay()->setTime($hour, 30)->toDateTimeString(),
        'status' => Appointment::STATUS_BOOKED, 'source' => 'staff',
    ]);

    $telehealth = app(TelehealthService::class);

    $scheduled = $telehealth->createSessionFromAppointment($appointment(9), $practitioner, $actor);

    $joined = $telehealth->createSessionFromAppointment($appointment(10), $practitioner, $actor);
    $telehealth->recordJoin($joined, TelehealthParticipant::TYPE_STAFF, (string) $actor->id);

    $ended = $telehealth->createSessionFromAppointment($appointment(11), $practitioner, $actor);
    $leg = $telehealth->recordJoin($ended, TelehealthParticipant::TYPE_STAFF, (string) $actor->id);
    $telehealth->recordLeave($leg);
    $telehealth->endSession($ended, $actor);

    return [
        'tenant' => $tenant, 'actor' => $actor, 'practitioner' => $practitioner,
        'scheduled' => $scheduled->refresh(), 'joined' => $joined->refresh(), 'ended' => $ended->refresh(),
    ];
}

/** Every scalar leaf of a payload, so a scan cannot miss a nested key. */
function thpLeaves(mixed $value, string $prefix = ''): array
{
    if (! is_array($value)) {
        return [$prefix => $value];
    }

    $out = [];
    foreach ($value as $key => $child) {
        $out += thpLeaves($child, $prefix === '' ? (string) $key : $prefix.'.'.$key);
    }

    return $out;
}

/* ------------------------------------------------------------------- the list over real rows */

test('the list shows REAL sessions with their recorded joins, and derives each state from rows', function () {
    $f = thpFixture();

    $response = $this->actingAs($f['actor'])->get(route('telehealth.index'));
    $response->assertOk();

    $props = $response->viewData('page')['props'];
    $byId = collect($props['sessions'])->keyBy('id');

    // D-174: a non-empty subject, so every "absent" assertion below means something.
    expect($props['sessions'])->toHaveCount(3);

    expect($byId[$f['scheduled']->id]['state'])->toBe(TelehealthSessionReader::STATE_SCHEDULED)
        ->and($byId[$f['scheduled']->id]['participants'])->toBe([])
        ->and($byId[$f['joined']->id]['state'])->toBe(TelehealthSessionReader::STATE_JOINED)
        ->and($byId[$f['ended']->id]['state'])->toBe(TelehealthSessionReader::STATE_ENDED);

    // The join time is the REAL recorded one, not a rendering of "now".
    $leg = $byId[$f['joined']->id]['participants'][0];
    $recorded = TelehealthParticipant::query()->where('session_id', $f['joined']->id)->firstOrFail();

    expect($leg['joinedAt'])->toBe($recorded->joined_at->toIso8601String())
        // Nobody reported leaving this one, which the payload states as null — never as "connected".
        ->and($leg['leftAt'])->toBeNull();

    // The linked appointment's own time travels; it is not invented from the session.
    expect($byId[$f['scheduled']->id]['appointmentAt'])->not->toBeNull();

    /*
     * Counts come from the RECORD, not from the page's list — and the fixture must make those two
     * numbers differ, or a hardcoded triple would satisfy the assertion. A mutation proved exactly
     * that: with one session in each state, `[1, 1, 1]` passed. So a FOURTH session is opened here,
     * making the distribution asymmetric (D-189, the third time this shape has appeared).
     */
    expect($props['counts'])->toBe(['scheduled' => 1, 'joined' => 1, 'ended' => 1]);

    $extra = app(TelehealthService::class)->createSessionFromAppointment(
        Appointment::query()->firstOrFail(), $f['practitioner'], $f['actor'],
    );

    $after = $this->actingAs($f['actor'])->get(route('telehealth.index'))->viewData('page')['props'];

    expect($after['counts'])->toBe(['scheduled' => 2, 'joined' => 1, 'ended' => 1])
        ->and($after['sessions'])->toHaveCount(4)
        ->and(collect($after['sessions'])->pluck('id'))->toContain($extra->id);
});

test('the filters narrow over real state, and an empty result says so honestly', function () {
    $f = thpFixture();

    foreach ([
        TelehealthSessionReader::STATE_SCHEDULED => $f['scheduled']->id,
        TelehealthSessionReader::STATE_JOINED => $f['joined']->id,
        TelehealthSessionReader::STATE_ENDED => $f['ended']->id,
    ] as $state => $expectedId) {
        $rows = $this->actingAs($f['actor'])
            ->get(route('telehealth.index', ['state' => $state]))
            ->viewData('page')['props']['sessions'];

        expect(collect($rows)->pluck('id')->all())->toBe([$expectedId]);
    }

    // A clinician with no sessions gets an empty list — not someone else's.
    $other = thpUser($f['tenant'], ['encounter.manage'], 'other_clinician');
    $rows = $this->actingAs($other)->get(route('telehealth.index'))->viewData('page')['props']['sessions'];

    expect($rows)->toBe([]);
});

/* ----------------------------------------------------------------------- the token is never kept */

test('the join token is NEVER persisted — not in a column, not in the audit trail', function () {
    $f = thpFixture();

    $response = $this->actingAs($f['actor'])->post(route('telehealth.token', $f['scheduled']->id));
    $response->assertOk();

    $token = $response->json('token');

    // POSITIVE CONTROL (D-182): a real, non-empty token was actually minted, so the scan below is
    // searching for something that genuinely exists.
    expect($token)->toBeString()->not->toBeEmpty();

    // No column anywhere could hold it...
    foreach (['telehealth_sessions', 'telehealth_participants'] as $table) {
        $columns = collect(DB::select("SHOW COLUMNS FROM {$table}"))->pluck('Field');
        expect($columns)->not->toBeEmpty("the scanned table {$table} must exist");

        foreach ($columns as $column) {
            expect(preg_match('/token|jwt|secret/i', (string) $column))
                ->toBe(0, "{$table}.{$column} could persist a join token");
        }
    }

    // ...and the value does not appear in any audit row's context, which is where a careless
    // "record what we issued" would put it.
    $contexts = DB::table('audit_events')->pluck('context')->filter()->implode(' ');
    expect($contexts)->not->toBeEmpty();
    expect(str_contains($contexts, (string) $token))->toBeFalse('the token leaked into the audit trail');

    // The issuance IS recorded — just without the secret.
    expect(DB::table('audit_events')->where('action', 'telehealth.token_issued')->count())->toBe(1);
});

/* ------------------------------------------------------------- participant rows stay append-only */

test('participant rows cannot be updated or deleted — pinned at the MODEL, not by a controller', function () {
    $f = thpFixture();

    $leg = TelehealthParticipant::query()->where('session_id', $f['joined']->id)->firstOrFail();

    // D-183: called on the model directly, so no controller or route answers first.
    expect(fn () => $leg->forceFill(['participant_type' => TelehealthParticipant::TYPE_PATIENT])->save())
        ->toThrow(LogicException::class);

    expect(fn () => TelehealthParticipant::query()->where('session_id', $f['joined']->id)->firstOrFail()->delete())
        ->toThrow(LogicException::class);

    // A leave may be set exactly ONCE — the one legal transition.
    $fresh = TelehealthParticipant::query()->whereKey($leg->id)->firstOrFail();
    app(TelehealthService::class)->recordLeave($fresh);

    expect(fn () => TelehealthParticipant::query()->whereKey($leg->id)->firstOrFail()
        ->forceFill(['left_at' => now()->addHour()])->save())
        ->toThrow(LogicException::class);

    // And the surface itself never mutates one: rendering the page leaves the rows untouched.
    $snapshot = fn (): array => TelehealthParticipant::query()->orderBy('id')->get()
        ->map(fn (TelehealthParticipant $p): string => implode('|', [
            $p->id, $p->participant_type, $p->joined_at->toIso8601String(), (string) $p->left_at?->toIso8601String(),
        ]))->all();

    $before = $snapshot();
    $this->actingAs($f['actor'])->get(route('telehealth.index'))->assertOk();
    $this->actingAs($f['actor'])->get(route('telehealth.show', $f['joined']->id))->assertOk();
    $after = $snapshot();

    expect($before)->not->toBeEmpty();
    expect($after)->toBe($before);
});

/* -------------------------------------------------------- the pre-check gates nothing server-side */

test('a forged "pre-check passed" claim changes nothing — the check gates nothing server-side', function () {
    $f = thpFixture();

    // Claiming a perfect check...
    $claimed = $this->actingAs($f['actor'])->post(route('telehealth.token', $f['scheduled']->id), [
        'precheck' => 'passed', 'camera' => true, 'microphone' => true, 'quality' => 'excellent',
    ]);

    // ...and claiming a failed one produce the SAME server behaviour, because neither is read.
    $denied = $this->actingAs($f['actor'])->post(route('telehealth.token', $f['scheduled']->id), [
        'precheck' => 'failed', 'camera' => false, 'microphone' => false,
    ]);

    $claimed->assertOk();
    $denied->assertOk();
    expect($claimed->json('room'))->toBe($denied->json('room'))
        ->and($claimed->json('role'))->toBe('staff');

    // Nothing about a client's claim was recorded anywhere.
    $contexts = DB::table('audit_events')->pluck('context')->filter()->implode(' ');
    foreach (['precheck', 'camera', 'microphone', 'quality'] as $claim) {
        expect(str_contains(strtolower($contexts), $claim))->toBeFalse("a client claim '{$claim}' was recorded");
    }
});

/* ------------------------------------------------------------------------------ the fence scan */

test('no recording, transcript, summary, quality or live-presence key in the payload', function () {
    $f = thpFixture();

    $list = $this->actingAs($f['actor'])->get(route('telehealth.index'))->viewData('page')['props'];
    $join = $this->actingAs($f['actor'])->get(route('telehealth.show', $f['joined']->id))->viewData('page')['props'];

    expect($list['sessions'])->not->toBeEmpty();
    expect($join['session'])->not->toBeNull();

    $forbidden = ['record', 'transcript', 'summary', 'quality', 'live', 'presence', 'connected',
        'waiting', 'bandwidth', 'signal', 'score'];

    foreach ([$list, $join] as $payload) {
        $paths = array_keys(thpLeaves($payload));
        expect($paths)->not->toBeEmpty();

        foreach ($paths as $path) {
            foreach ($forbidden as $needle) {
                expect(str_contains(strtolower($path), $needle))
                    ->toBeFalse("telehealth payload must not carry a '{$needle}' key; found at {$path}");
            }
        }
    }
});

test('neither component draws a recording control or a quality grade', function () {
    // The scan resolves its subjects first, so it cannot go silent if a file moves (D-173).
    $files = [
        resource_path('js/pages/Telehealth/Sessions.vue'),
        resource_path('js/pages/Telehealth/Join.vue'),
    ];

    foreach ($files as $file) {
        expect(file_exists($file))->toBeTrue("the scanned component must exist at {$file}");
        $source = (string) file_get_contents($file);
        expect(strlen($source))->toBeGreaterThan(500);

        // A record BUTTON or a recording state — the omission copy legitimately says "recording",
        // so this looks for the affordance, not the word.
        foreach (['startRecording', 'stopRecording', 'isRecording', 'recordingUrl', 'transcript(', 'qualityScore', 'signalBars'] as $affordance) {
            expect(str_contains($source, $affordance))
                ->toBeFalse("{$file} must not carry the affordance {$affordance}");
        }
    }
});

/* ------------------------------------------------------------------- permission + tenant scoping */

test('the surfaces are permission-gated and tenant-scoped fail-closed', function () {
    $f = thpFixture();

    // Refused without encounter.manage — with a positive control that the permission is the only
    // thing in the way.
    $stranger = thpUser($f['tenant'], ['patient.view'], 'no_encounter');
    $this->actingAs($stranger)->get(route('telehealth.index'))->assertForbidden();
    $this->actingAs($stranger)->get(route('telehealth.show', $f['joined']->id))->assertForbidden();
    $this->actingAs($stranger)->post(route('telehealth.token', $f['joined']->id))->assertForbidden();

    $this->actingAs($f['actor'])->get(route('telehealth.index'))->assertOk();
    $this->actingAs($f['actor'])->get(route('telehealth.show', $f['joined']->id))->assertOk();

    // A second tenant's session is a 404 — the surface cannot even confirm the id exists.
    $otherTenant = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta', 'region' => 'eu', 'status' => 'active']);
    thpCtx()->forget();
    thpCtx()->set($otherTenant);
    $otherActor = User::factory()->forTenant($otherTenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $otherActor->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    $this->actingAs($otherActor)->get(route('telehealth.show', $f['joined']->id))->assertNotFound();
    $this->actingAs($otherActor)->post(route('telehealth.token', $f['joined']->id))->assertNotFound();
});

/* ----------------------------------------------------------------------- honesty about presence */

test('an unconfigured provider is stated up front rather than discovered at the Join', function () {
    $f = thpFixture();

    // The real default from the deploy template: livekit.invalid with no credentials.
    config()->set('telehealth.provider', 'livekit');
    config()->set('telehealth.providers.livekit.api_key', '');
    config()->set('telehealth.providers.livekit.api_secret', '');

    $props = $this->actingAs($f['actor'])->get(route('telehealth.index'))->viewData('page')['props'];
    expect($props['providerConfigured'])->toBeFalse();

    // POSITIVE CONTROL: configured credentials flip it, so the flag tracks the config rather than
    // being hardcoded either way.
    config()->set('telehealth.providers.livekit.api_key', 'key');
    config()->set('telehealth.providers.livekit.api_secret', 'secret');

    $props = $this->actingAs($f['actor'])->get(route('telehealth.index'))->viewData('page')['props'];
    expect($props['providerConfigured'])->toBeTrue();
});

test('the presence and omission statements are RENDERED, not merely present in the copy', function () {
    // GOV.P3's lesson: assert the keys the component iterates, then that each resolves to real copy.
    $source = (string) file_get_contents(resource_path('js/pages/Telehealth/Sessions.vue'));

    expect($source)->toContain("const omittedKeys = ['recording', 'transcript', 'quality', 'presence']")
        ->and($source)->toContain('staffTelehealth.omitted.${key}')
        ->and($source)->toContain("t('staffTelehealth.presence.tracked')")
        ->and($source)->toContain("t('staffTelehealth.presence.notTracked')");

    $copy = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);
    $omitted = $copy['staffTelehealth']['omitted'];

    foreach (['recording', 'transcript', 'quality', 'presence'] as $key) {
        expect($omitted)->toHaveKey($key);
        expect(strlen((string) $omitted[$key]))->toBeGreaterThan(60);
    }

    // The statements must actually name what is missing.
    expect(strtolower($omitted['recording']))->toContain('recording')
        ->and(strtolower($omitted['quality']))->toContain('quality')
        ->and(strtolower($copy['staffTelehealth']['presence']['notTracked']))->toContain('connected');
});

test('a room is created with recording disabled, and no token carries a recording grant', function () {
    $fake = thpFake();
    $f = thpFixture();

    // Re-resolve: thpFixture rebinds the fake, so read the instance the service actually used.
    $fake = app(TelehealthProvider::class);

    expect($fake->createdRooms)->not->toBeEmpty();
    foreach ($fake->createdRooms as $room) {
        expect($room['options']['recording_disabled'])->toBeTrue();
    }

    $token = app(TelehealthService::class)->joinTokenForStaff($f['scheduled'], $f['actor']);

    expect($token->grants['roomRecord'])->toBeFalse()
        ->and($token->grants['roomAdmin'])->toBeFalse()
        ->and($token->grants['recorder'])->toBeFalse();
});
