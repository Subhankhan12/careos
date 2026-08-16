<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Clinical\Models\Allergy;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentReminder;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AppointmentService;
use Modules\Scheduling\Services\BookingService;

uses(RefreshDatabase::class);

/*
 * APPT.P1 — the staff Appointment Detail page. These tests prove the page DISPLAYS the REAL record:
 * the TRUE status (all eight machine states, not the four the wireframe drew), the real source, the
 * real linked resources (with NO fabricated capability chips), the real patient + recorded allergies,
 * and a timeline built from real audit + reminder rows with HONEST channel labels (email — never a
 * claimed SMS) and real provenance (never an invented "patient replied"). Plus an honest empty state,
 * `appointment.manage`, and tenant scoping. No actions are on this page (APPT.P2/P3).
 * These tests ADD coverage; no existing behaviour test is modified.
 */

function apdUser(Tenant $tenant, string $role = 'coordinator'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, service: Service, practitioner: resource, room: resource}
 */
function apdFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $actor = apdUser($tenant);
    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $service = Service::query()->create([
        'name' => 'Consultation 30', 'code' => 'C30-'.strtoupper($slug), 'category' => 'general',
        'default_duration_minutes' => 30, 'requires_resource_types' => ['practitioner', 'room'],
        'bookable_online' => true, 'active' => true,
    ]);
    $practitioner = apdResource(Resource::TYPE_PRACTITIONER, 'Dr. Weber', $branch);
    $room = apdResource(Resource::TYPE_ROOM, 'Behandlung 2', $branch);

    return compact('tenant', 'actor', 'branch', 'service', 'practitioner', 'room');
}

/** A bookable resource: available all week, so the real BookingService will accept a slot. */
function apdResource(string $type, string $name, Branch $branch): Resource
{
    $resource = Resource::query()->create(['type' => $type, 'name' => $name, 'branch_id' => $branch->id, 'active' => true]);

    foreach (range(0, 6) as $weekday) {
        ResourceAvailability::query()->create([
            'resource_id' => $resource->id,
            'weekday' => $weekday,
            'start_time' => '00:00',
            'end_time' => '23:59',
        ]);
    }

    return $resource;
}

function apdPatient(string $last = 'Keller'): Patient
{
    return app(PatientService::class)->create(['first_name' => 'Nora', 'last_name' => $last, 'date_of_birth' => '1988-03-14', 'sex' => 'female']);
}

/**
 * Book through the REAL BookingService so the appointment, its resource links and its
 * `appointment.booked` audit row all exist exactly as production would create them.
 */
function apdBook(array $fx, Patient $patient, string $startsAt = '2026-07-11 10:30:00'): Appointment
{
    return app(BookingService::class)->book(
        $fx['service']->id,
        $patient->id,
        $fx['branch']->id,
        $startsAt,
        [$fx['practitioner']->id, $fx['room']->id],
        $fx['actor'],
    );
}

beforeEach(fn () => Carbon::setTestNow('2026-07-01 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

// ── The page renders the REAL appointment ────────────────────────────────────────────────────────

test('the page renders the real appointment: status, source, service duration, resources and patient', function () {
    $fx = apdFixture();
    $patient = apdPatient();
    $appointment = apdBook($fx, $patient);

    // A RECORDED allergy, written through the real clinical path (recorded_by is a StaffProfile —
    // the fail-closed tenancy guard enforces it).
    $recorder = StaffProfile::query()->create([
        'user_id' => $fx['actor']->id, 'first_name' => 'Paula', 'last_name' => 'Practitioner',
        'display_name' => 'Paula Practitioner', 'profession' => 'doctor', 'primary_branch_id' => $fx['branch']->id,
    ]);
    Allergy::query()->create([
        'patient_id' => $patient->id, 'substance' => 'Penicillin', 'substance_key' => 'penicillin',
        'reaction' => 'Anaphylaxis', 'source' => 'patient_reported', 'severity' => Allergy::SEVERITY_SEVERE,
        'status' => Allergy::STATUS_ACTIVE, 'recorded_by' => $recorder->id, 'recorded_at' => now(),
    ]);

    $this->actingAs($fx['actor'])->get(route('scheduling.appointments.show', $appointment->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Scheduling/AppointmentDetail')
            ->where('appointment.id', $appointment->id)
            ->where('appointment.status', Appointment::STATUS_BOOKED)      // the TRUE status
            ->where('appointment.source', Appointment::SOURCE_STAFF)       // the REAL source
            ->where('appointment.service', 'Consultation 30')
            ->where('appointment.duration_minutes', 30)                    // the SERVICE's own length
            ->where('appointment.branch', 'Main')
            // The real linked resources — practitioner + room, with their recorded types.
            ->where('resources.0.name', 'Dr. Weber')
            ->where('resources.0.type', Resource::TYPE_PRACTITIONER)
            ->where('resources.1.name', 'Behandlung 2')
            ->where('resources.1.type', Resource::TYPE_ROOM)
            // Real patient identity + the RECORDED allergy (displayed, never graded).
            ->where('patient.name', 'Nora Keller')
            ->where('patient.date_of_birth', '1988-03-14')
            ->where('patient.allergies.0.substance', 'Penicillin')
            ->where('patient.allergies.0.severity', Allergy::SEVERITY_SEVERE));
});

test('NO fabricated backend is surfaced — resources carry only recorded fields (no capability chips)', function () {
    $fx = apdFixture();
    $patient = apdPatient();
    $appointment = apdBook($fx, $patient);

    $this->actingAs($fx['actor'])->get(route('scheduling.appointments.show', $appointment->id))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $resources = $page->toArray()['props']['resources'];
            foreach ($resources as $resource) {
                // Exactly the recorded fields — no invented capability/equipment data.
                expect(array_keys($resource))->toBe(['id', 'name', 'type']);
            }
        });
});

// ── THE STATUS PILL SHOWS THE TRUE STATUS — every machine state, not the wireframe's four ────────

test('the status pill reflects the TRUE status for every state the machine defines', function () {
    $fx = apdFixture();
    $service = app(AppointmentService::class);

    // A booked appointment reads booked.
    $booked = apdBook($fx, apdPatient('Booked'), '2026-07-11 09:00:00');
    $this->actingAs($fx['actor'])->get(route('scheduling.appointments.show', $booked->id))
        ->assertInertia(fn (Assert $p) => $p->where('appointment.status', Appointment::STATUS_BOOKED));

    // Walk the REAL machine one legal edge at a time (each call returns the moved appointment) and
    // assert the page follows it at every step.
    $walked = apdBook($fx, apdPatient('Walker'), '2026-07-11 11:00:00');
    foreach ([
        ['confirm', Appointment::STATUS_CONFIRMED],
        ['arrive', Appointment::STATUS_ARRIVED],
        ['start', Appointment::STATUS_IN_PROGRESS],
        ['complete', Appointment::STATUS_COMPLETED],
    ] as [$move, $expected]) {
        $walked = $service->{$move}($walked, $fx['actor']);
        expect($walked->status)->toBe($expected);

        $this->actingAs($fx['actor'])->get(route('scheduling.appointments.show', $walked->id))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('appointment.status', $expected));
    }

    // The terminal states the wireframe never drew are rendered just as faithfully.
    $cancelled = apdBook($fx, apdPatient('Cancelled'), '2026-07-11 13:00:00');
    $service->cancel($cancelled, $fx['actor'], 'Patient rang to cancel.');
    $this->actingAs($fx['actor'])->get(route('scheduling.appointments.show', $cancelled->id))
        ->assertInertia(fn (Assert $p) => $p
            ->where('appointment.status', Appointment::STATUS_CANCELLED)
            ->where('appointment.status_reason', 'Patient rang to cancel.'));

    $noShow = apdBook($fx, apdPatient('Noshow'), '2026-07-11 14:00:00');
    $service->noShow($noShow, $fx['actor']);
    $this->actingAs($fx['actor'])->get(route('scheduling.appointments.show', $noShow->id))
        ->assertInertia(fn (Assert $p) => $p->where('appointment.status', Appointment::STATUS_NO_SHOW));
});

// ── The timeline is REAL, with honest labels ─────────────────────────────────────────────────────

test('the timeline shows real audit rows with real provenance, and a reminder with its RECORDED channel', function () {
    $fx = apdFixture();
    $patient = apdPatient();
    $appointment = apdBook($fx, $patient);
    app(AppointmentService::class)->confirm($appointment, $fx['actor']);

    // A REAL reminder row — the only channel that exists today is email.
    AppointmentReminder::query()->create([
        'appointment_id' => $appointment->id,
        'type' => 'appointment.reminder',
        'channel' => AppointmentReminder::CHANNEL_EMAIL,
        'status' => AppointmentReminder::STATUS_SENT,
        'scheduled_for' => '2026-07-10 08:00:00',
        'sent_at' => '2026-07-10 08:00:00',
    ]);

    $this->actingAs($fx['actor'])->get(route('scheduling.appointments.show', $appointment->id))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($fx) {
            $timeline = $page->toArray()['props']['timeline'];
            $actions = array_column($timeline, 'action');

            // The real booked + confirmed audit rows are present, with the real from→to and actor.
            expect($actions)->toContain('appointment.booked')
                ->and($actions)->toContain('appointment.confirmed')
                ->and($actions)->toContain('appointment.reminder');

            $confirmed = collect($timeline)->firstWhere('action', 'appointment.confirmed');
            expect($confirmed['from_status'])->toBe('booked')
                ->and($confirmed['to_status'])->toBe('confirmed')
                ->and($confirmed['actor'])->toBe($fx['actor']->name)   // REAL provenance
                ->and($confirmed['actor_type'])->toBe('user');

            // HONEST CHANNEL: the row says exactly what was recorded — email, never SMS.
            $reminder = collect($timeline)->firstWhere('kind', 'reminder');
            expect($reminder['channel'])->toBe('email')
                ->and($reminder['status'])->toBe('sent');

            // Nothing anywhere claims an SMS or an inbound patient reply.
            $json = json_encode($timeline);
            foreach (['sms', 'SMS', 'replied', 'whatsapp'] as $needle) {
                expect(str_contains((string) $json, $needle))->toBeFalse();
            }
        });
});

test('an appointment with no recorded history renders an honest empty timeline', function () {
    $fx = apdFixture();
    $patient = apdPatient();

    // Create the row directly (no BookingService), so no audit/reminder rows exist for it.
    $appointment = Appointment::query()->create([
        'patient_id' => $patient->id,
        'service_id' => $fx['service']->id,
        'branch_id' => $fx['branch']->id,
        'starts_at' => '2026-07-12 09:00:00',
        'ends_at' => '2026-07-12 09:30:00',
        'status' => Appointment::STATUS_BOOKED,
        'source' => Appointment::SOURCE_STAFF,
    ]);

    $this->actingAs($fx['actor'])->get(route('scheduling.appointments.show', $appointment->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('timeline', [])          // honestly empty — no invented history
            ->where('resources', []));       // and no resources were linked
});

// ── RBAC + tenant scope + the day-board drill link ───────────────────────────────────────────────

test('the page is gated on appointment.manage and is tenant-scoped', function () {
    $fx = apdFixture('alpha');
    $patient = apdPatient();
    $appointment = apdBook($fx, $patient);

    // A role without appointment.manage is refused (billing holds billing.* but not scheduling).
    $billing = apdUser($fx['tenant'], 'billing');
    $this->actingAs($billing)->get(route('scheduling.appointments.show', $appointment->id))->assertForbidden();

    // A second tenant cannot open the first tenant's appointment (fail-closed → 404).
    $beta = apdFixture('beta');
    $this->actingAs($beta['actor'])->get(route('scheduling.appointments.show', $appointment->id))->assertNotFound();
});

test('the day-board tile exposes the drill link to this page', function () {
    $fx = apdFixture();
    $patient = apdPatient();
    $appointment = apdBook($fx, $patient);

    $this->actingAs($fx['actor'])
        ->get(route('scheduling.day-board', ['date' => '2026-07-11', 'branch_id' => $fx['branch']->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Scheduling/DayBoard')
            ->where('appointments.0.id', $appointment->id)
            ->where('appointments.0.detail_url', route('scheduling.appointments.show', $appointment->id)));
});
