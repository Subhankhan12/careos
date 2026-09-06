<?php

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\NoteTemplate;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
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
use Modules\Scheduling\Services\AppointmentService;
use Modules\Scheduling\Services\BookingService;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| QA-FIX.2b — writing a note does not assert that the patient attended
|--------------------------------------------------------------------------
| Closes `P2-H1`. One click on "Document" used to fire
| appointment.confirmed → arrived → in_progress at the same instant, while
| checked_in_at and check_in_source stayed NULL: the record asserted attendance
| with no evidence behind it (D-179). Same shape as `P1-M1` from another entry
| point — cross-phase pattern 3.
|
| EVERY TEST HERE IS D-182-SHAPED: it starts from a BOOKED appointment, which is
| exactly the state that used to be swept to in_progress. Without the fix these
| assertions do not merely fail — the old code SUCCEEDS at reaching in_progress,
| so the guard is what the test is measuring.
*/

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-07-13 00:30:00'));
});

afterEach(fn () => Carbon::setTestNow());

// Self-contained fixtures. Every test file in this repo defines its own prefixed helpers (d7*, c8*,
// g5*); Pest does not share them across files, so these follow the same convention.

function da_tenant(string $slug = 'alpha'): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function da_user(Tenant $tenant, string $role = 'doctor'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

function da_profile(Branch $branch, User $user): StaffProfile
{
    return StaffProfile::query()->create([
        'user_id' => $user->id,
        'first_name' => 'Anna',
        'last_name' => 'Clinician',
        'display_name' => 'Anna Clinician',
        'profession' => 'doctor',
        'primary_branch_id' => $branch->id,
    ]);
}

function da_resource(Branch $branch, StaffProfile $practitioner): BookableResource
{
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Practitioner',
        'staff_profile_id' => $practitioner->id,
        'branch_id' => $branch->id,
        'active' => true,
    ]);

    ResourceAvailability::query()->create([
        'resource_id' => $resource->id,
        'weekday' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    return $resource;
}

/**
 * A booked appointment plus the clinician who will document it.
 *
 * @return array{doctor: User, appointment: Appointment}
 */
function da_fixture(): array
{
    $tenant = da_tenant();
    app(TenantContext::class)->set($tenant);

    $doctor = da_user($tenant, 'doctor');
    $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Clara',
        'last_name' => 'Clinical',
        'date_of_birth' => '1984-01-02',
        'sex' => 'female',
    ]);
    $practitioner = da_profile($branch, $doctor);

    $service = Service::query()->create([
        'name' => 'Consult',
        'code' => 'CONS',
        'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => false,
        'active' => true,
    ]);

    $resource = da_resource($branch, $practitioner);

    $appointment = app(BookingService::class)->book(
        $service->id,
        $patient->id,
        $branch->id,
        '2026-07-13 10:00:00',
        [$resource->id],
        $doctor,
    );

    NoteTemplate::query()->create([
        'name' => 'Standard SOAP',
        'default_subjective' => 'Template subjective',
        'required_sections' => [],
        'active' => true,
    ]);

    return ['doctor' => $doctor, 'appointment' => $appointment];
}

test('documenting a BOOKED appointment does not silently mark the patient arrived or start the visit', function () {
    $fx = da_fixture();

    expect($fx['appointment']->status)->toBe(Appointment::STATUS_BOOKED);

    $this->actingAs($fx['doctor'])
        ->post(route('scheduling.day-board.open-encounter'), ['appointment_id' => $fx['appointment']->id])
        ->assertRedirect();

    $after = $fx['appointment']->refresh();

    // Without the fix this reads in_progress — the old code composed confirm→arrive→start.
    expect($after->status)->toBe(Appointment::STATUS_BOOKED)
        ->and($after->status)->not->toBe(Appointment::STATUS_IN_PROGRESS)
        ->and($after->status)->not->toBe(Appointment::STATUS_ARRIVED);
});

test('nothing is left asserting a check-in that never happened', function () {
    $fx = da_fixture();

    $this->actingAs($fx['doctor'])
        ->post(route('scheduling.day-board.open-encounter'), ['appointment_id' => $fx['appointment']->id])
        ->assertRedirect();

    $after = $fx['appointment']->refresh();

    // P1-M1's point, asserted explicitly: the pair must stay consistent. `checked_in_at` is written
    // only by a real check-in (FrontDesk CheckInService), so documentation must leave it alone AND
    // must not move the status to one that implies it.
    expect($after->checked_in_at)->toBeNull()
        ->and($after->check_in_source)->toBeNull()
        ->and($after->status)->toBe(Appointment::STATUS_BOOKED);
});

test('no phantom attendance transitions are written to the audit trail', function () {
    $fx = da_fixture();

    $this->actingAs($fx['doctor'])
        ->post(route('scheduling.day-board.open-encounter'), ['appointment_id' => $fx['appointment']->id])
        ->assertRedirect();

    $actions = DB::table('audit_events')->pluck('action')->all();

    expect($actions)->not->toContain('appointment.confirmed')
        ->and($actions)->not->toContain('appointment.arrived')
        ->and($actions)->not->toContain('appointment.in_progress')
        // ...while the thing the clinician actually did IS recorded
        ->and($actions)->toContain('encounter.opened');
});

test('the note is still created — documentation is never blocked', function () {
    $fx = da_fixture();

    $this->actingAs($fx['doctor'])
        ->post(route('scheduling.day-board.open-encounter'), ['appointment_id' => $fx['appointment']->id])
        ->assertRedirect();

    $encounter = Encounter::query()->firstOrFail();
    $note = ClinicalNote::query()->firstOrFail();

    expect($encounter->appointment_id)->toBe($fx['appointment']->id)
        ->and($encounter->status)->toBe(Encounter::STATUS_OPEN)
        ->and($note->encounter_id)->toBe($encounter->id)
        ->and($note->status)->toBe(ClinicalNote::STATUS_DRAFT)
        ->and($note->subjective)->toBe('Template subjective');
});

test('documenting an ARRIVED appointment DOES start the visit — the unambiguous case still works', function () {
    $fx = da_fixture();

    // Reception records the arrival through the controls that mean it. This is the D-156 compose.
    $appointments = app(AppointmentService::class);
    $confirmed = $appointments->confirm($fx['appointment'], $fx['doctor']);
    $arrived = $appointments->arrive($confirmed, $fx['doctor']);

    expect($arrived->refresh()->status)->toBe(Appointment::STATUS_ARRIVED);

    $this->actingAs($fx['doctor'])
        ->post(route('scheduling.day-board.open-encounter'), ['appointment_id' => $fx['appointment']->id])
        ->assertRedirect();

    // POSITIVE CONTROL: the fix is a guard, not a removal. From `arrived` the visit still starts,
    // so a test suite that only asserted "nothing transitions" would be passing vacuously.
    expect($fx['appointment']->refresh()->status)->toBe(Appointment::STATUS_IN_PROGRESS);
});

test('documenting an already in-progress appointment is a no-op and still attaches the note', function () {
    $fx = da_fixture();

    $appointments = app(AppointmentService::class);
    $appointments->start($appointments->arrive($appointments->confirm($fx['appointment'], $fx['doctor']), $fx['doctor']), $fx['doctor']);

    expect($fx['appointment']->refresh()->status)->toBe(Appointment::STATUS_IN_PROGRESS);

    $this->actingAs($fx['doctor'])
        ->post(route('scheduling.day-board.open-encounter'), ['appointment_id' => $fx['appointment']->id])
        ->assertRedirect();

    expect($fx['appointment']->refresh()->status)->toBe(Appointment::STATUS_IN_PROGRESS)
        ->and(ClinicalNote::query()->count())->toBe(1);
});

test('the D-156 day-board compose is untouched: Arrive still composes confirm then arrive', function () {
    $fx = da_fixture();

    expect($fx['appointment']->status)->toBe(Appointment::STATUS_BOOKED);

    $this->actingAs($fx['doctor'])
        ->post(route('scheduling.day-board.transition'), [
            'appointment_id' => $fx['appointment']->id,
            'action' => 'arrive',
        ])
        ->assertRedirect();

    // Pressing a button that MEANS "the patient is here" still walks booked → confirmed → arrived,
    // two legal edges, each audited. This gate narrowed the documentation path, not this one.
    expect($fx['appointment']->refresh()->status)->toBe(Appointment::STATUS_ARRIVED);

    $actions = DB::table('audit_events')->pluck('action')->all();
    expect($actions)->toContain('appointment.confirmed')
        ->and($actions)->toContain('appointment.arrived');
});
