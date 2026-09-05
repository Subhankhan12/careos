<?php

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\NoteTemplate;
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
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\BookingService;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| QA-FIX.2a — a clinical note is authored by the clinician who WROTE it
|--------------------------------------------------------------------------
| Closes `P2-C1`. The Phase-2 audit logged in as Dr. Brunner, clicked "Document"
| on an appointment whose practitioner resource was Dr. Keller, typed the note and
| signed it — and the record read "Signed · Dr. med. Sofia Keller".
|
| EVERY FIXTURE HERE MAKES THE ACTOR AND THE APPOINTMENT'S PRACTITIONER DIFFERENT
| PEOPLE, and asserts that they really are (D-174). The pre-existing tests in
| ClinicalUiTest use d7Practitioner($branch, $doctor) — the practitioner IS the acting
| doctor — so A == B and they passed either way. That vacuity is precisely why this
| defect survived to production, so the control is asserted explicitly below.
*/

// The clock: the booking helper books a fixed 2026-07-13 10:00 slot, which must be in
// the future for the past-start guard added by QA-FIX.1b (D-194) to allow it.
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-07-13 00:30:00'));
});

afterEach(fn () => Carbon::setTestNow());

function na_tenant(string $slug = 'alpha'): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function na_ctx(): TenantContext
{
    return app(TenantContext::class);
}

function na_user(Tenant $tenant, string $role = 'doctor'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

function na_profile(Branch $branch, ?User $user, string $name): StaffProfile
{
    return StaffProfile::query()->create([
        'user_id' => $user?->id,
        'first_name' => $name,
        'last_name' => 'Clinician',
        'display_name' => $name.' Clinician',
        'profession' => 'doctor',
        'primary_branch_id' => $branch->id,
    ]);
}

function na_branch(): Branch
{
    return Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN']);
}

function na_patient(): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Clara',
        'last_name' => 'Clinical',
        'date_of_birth' => '1984-01-02',
        'sex' => 'female',
    ]);
}

function na_resource(Branch $branch, StaffProfile $practitioner): BookableResource
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

function na_template(): NoteTemplate
{
    return NoteTemplate::query()->create([
        'name' => 'Standard SOAP',
        'default_subjective' => 'Template subjective',
        'required_sections' => [],
        'active' => true,
    ]);
}

/**
 * The full P2-C1 fixture: an appointment whose practitioner is SOMEBODY ELSE than the
 * clinician who will act on it.
 *
 * @return array{actor: User, actorProfile: StaffProfile, otherProfile: StaffProfile, appointment: Appointment, patient: Patient, branch: Branch}
 */
function na_fixture(): array
{
    $tenant = na_tenant();
    na_ctx()->set($tenant);

    $branch = na_branch();
    $patient = na_patient();

    // A = the clinician who will click Document and write the note.
    $actor = na_user($tenant, 'doctor');
    $actorProfile = na_profile($branch, $actor, 'Anna');

    // B = the clinician the APPOINTMENT is with. A different person, with their own user.
    $otherUser = na_user($tenant, 'doctor');
    $otherProfile = na_profile($branch, $otherUser, 'Bruno');

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

    $resource = na_resource($branch, $otherProfile);

    $appointment = app(BookingService::class)->book(
        $service->id,
        $patient->id,
        $branch->id,
        '2026-07-13 10:00:00',
        [$resource->id],
        $actor,
    );

    na_template();

    return compact('actor', 'actorProfile', 'otherProfile', 'appointment', 'patient', 'branch');
}

test('the note is authored by the clinician who wrote it, not the appointment practitioner', function () {
    $fx = na_fixture();

    // POSITIVE CONTROL (D-174): without this the test is vacuous. Prove the appointment really
    // is with a DIFFERENT clinician, so "authored by the actor" is a claim that can fail.
    $practitionerIds = DB::table('appointment_resources')
        ->where('appointment_id', $fx['appointment']->id)
        ->join('resources', 'resources.id', '=', 'appointment_resources.resource_id')
        ->whereNotNull('resources.staff_profile_id')
        ->pluck('resources.staff_profile_id');

    expect($practitionerIds)->toContain($fx['otherProfile']->id)
        ->and($fx['otherProfile']->id)->not->toBe($fx['actorProfile']->id)
        ->and($fx['otherProfile']->user_id)->not->toBe($fx['actor']->id);

    $this->actingAs($fx['actor'])
        ->post(route('scheduling.day-board.open-encounter'), ['appointment_id' => $fx['appointment']->id])
        ->assertRedirect();

    $note = ClinicalNote::query()->firstOrFail();

    expect($note->author_id)->toBe($fx['actorProfile']->id)
        ->and($note->author_id)->not->toBe($fx['otherProfile']->id);
});

test('the encounter still records the appointment practitioner — the fix is surgical', function () {
    $fx = na_fixture();

    $this->actingAs($fx['actor'])
        ->post(route('scheduling.day-board.open-encounter'), ['appointment_id' => $fx['appointment']->id])
        ->assertRedirect();

    $encounter = Encounter::query()->firstOrFail();

    // The ENCOUNTER is the visit, and the visit is with the booked clinician. Only the NOTE's
    // authorship moved. If this ever flips, the fix stopped being surgical.
    expect($encounter->practitioner_id)->toBe($fx['otherProfile']->id)
        ->and($encounter->appointment_id)->toBe($fx['appointment']->id);
});

test('the audit trail still records the acting user as the actor', function () {
    $fx = na_fixture();

    $this->actingAs($fx['actor'])
        ->post(route('scheduling.day-board.open-encounter'), ['appointment_id' => $fx['appointment']->id])
        ->assertRedirect();

    $opened = DB::table('audit_events')->where('action', 'encounter.opened')->latest('occurred_at')->first();

    expect($opened)->not->toBeNull()
        ->and((string) $opened->actor_id)->toBe((string) $fx['actor']->id);
});

test('the signed note names the SIGNATORY, and author equals signatory when one clinician does both', function () {
    $fx = na_fixture();

    $this->actingAs($fx['actor'])
        ->post(route('scheduling.day-board.open-encounter'), ['appointment_id' => $fx['appointment']->id])
        ->assertRedirect();

    $note = ClinicalNote::query()->firstOrFail();

    $this->actingAs($fx['actor'])
        ->post(route('clinical.notes.sign', $note->id))
        ->assertRedirect();

    $this->actingAs($fx['actor'])
        ->get(route('clinical.notes.edit', $note->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinical/NoteEditor')
            ->where('note.signed_by_name', $fx['actorProfile']->display_name)
            ->where('note.signed_by_is_author', true)
            // and it is NOT the appointment's practitioner
            ->where('note.author_name', $fx['actorProfile']->display_name));
});

test('a note drafted by one clinician and signed by another names BOTH, distinctly', function () {
    $fx = na_fixture();

    $this->actingAs($fx['actor'])
        ->post(route('scheduling.day-board.open-encounter'), ['appointment_id' => $fx['appointment']->id])
        ->assertRedirect();

    $note = ClinicalNote::query()->firstOrFail();

    // The OTHER clinician signs what the actor drafted — a real, legitimate state (the seeded
    // radiology reports are exactly this shape: authored by the radiologist, signed by another).
    $signer = User::query()->whereKey($fx['otherProfile']->user_id)->firstOrFail();

    $this->actingAs($signer)
        ->post(route('clinical.notes.sign', $note->id))
        ->assertRedirect();

    $this->actingAs($fx['actor'])
        ->get(route('clinical.notes.edit', $note->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinical/NoteEditor')
            // author stays the person who wrote it...
            ->where('note.author_name', $fx['actorProfile']->display_name)
            // ...the signatory is named separately, and the view is told they differ so it
            // renders both instead of presenting one as the other.
            ->where('note.signed_by_name', $fx['otherProfile']->display_name)
            ->where('note.signed_by_is_author', false));

    expect(ClinicalNote::query()->findOrFail($note->id)->author_id)->toBe($fx['actorProfile']->id);
});

test('an amendment is authored by the clinician who wrote the amendment, not the original author', function () {
    $fx = na_fixture();

    $this->actingAs($fx['actor'])
        ->post(route('scheduling.day-board.open-encounter'), ['appointment_id' => $fx['appointment']->id])
        ->assertRedirect();

    $note = ClinicalNote::query()->firstOrFail();

    $this->actingAs($fx['actor'])
        ->post(route('clinical.notes.sign', $note->id))
        ->assertRedirect();

    // A DIFFERENT clinician corrects it. The amendment is a new version and it is their writing.
    $amender = User::query()->whereKey($fx['otherProfile']->user_id)->firstOrFail();

    $this->actingAs($amender)
        ->post(route('clinical.notes.amend', $note->id), ['reason' => 'Weight omitted in error.'])
        ->assertRedirect();

    $amendment = ClinicalNote::query()->where('supersedes_id', $note->id)->firstOrFail();

    expect($amendment->author_id)->toBe($fx['otherProfile']->id)
        // the ORIGINAL keeps its own author — the chain records who wrote each version
        ->and(ClinicalNote::query()->findOrFail($note->id)->author_id)->toBe($fx['actorProfile']->id);
});

test('a user with no staff profile is refused rather than having the note attributed to somebody else', function () {
    $fx = na_fixture();

    // A clinician with note.write but no staff profile: we cannot record who wrote it, so we
    // must not write it. Guessing an author is the defect this whole gate closes.
    $ghost = na_user($fx['actor']->tenant, 'doctor');

    expect(StaffProfile::forUser($ghost))->toBeNull();

    $this->actingAs($ghost)
        ->post(route('scheduling.day-board.open-encounter'), ['appointment_id' => $fx['appointment']->id])
        ->assertSessionHasErrors('appointment_id');

    expect(ClinicalNote::query()->count())->toBe(0);
});

test('StaffProfile::forUser resolves the acting user and never guesses', function () {
    $fx = na_fixture();

    expect(StaffProfile::forUser($fx['actor'])?->id)->toBe($fx['actorProfile']->id)
        ->and(StaffProfile::forUser(null))->toBeNull();
});
