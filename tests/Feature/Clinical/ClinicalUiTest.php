<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Models\Allergy;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\NoteTemplate;
use Modules\Clinical\Services\CarePlanService;
use Modules\Clinical\Services\ClinicalListService;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\Clinical\Services\EncounterService;
use Modules\Clinical\Services\MedicationService;
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

function d7Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function d7Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function d7Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function d7User(Tenant $tenant, string $role = 'doctor'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    if ($role !== '') {
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => d7Role($role)->id,
        ]);
    }

    return $user;
}

function d7Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function d7Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Clara',
        'last_name' => 'Clinical',
        'date_of_birth' => '1984-01-02',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function d7Practitioner(Branch $branch, ?User $user = null): StaffProfile
{
    return StaffProfile::query()->create([
        'user_id' => $user?->id,
        'first_name' => 'Paula',
        'last_name' => 'Practitioner',
        'display_name' => 'Paula Practitioner',
        'profession' => 'doctor',
        'primary_branch_id' => $branch->id,
    ]);
}

function d7Encounter(User $user, ?Patient $patient = null, ?StaffProfile $practitioner = null, ?Branch $branch = null): Encounter
{
    $branch ??= d7Branch();
    $patient ??= d7Patient();
    $practitioner ??= d7Practitioner($branch, $user);

    return app(EncounterService::class)->open(
        $patient,
        $practitioner,
        $branch,
        null,
        Encounter::TYPE_CONSULTATION,
        $user,
    );
}

function d7Template(array $overrides = []): NoteTemplate
{
    return NoteTemplate::query()->create([
        'name' => 'Standard SOAP',
        'default_subjective' => 'Template subjective',
        'default_objective' => 'Template objective',
        'default_assessment' => 'Template assessment',
        'default_plan' => 'Template plan',
        'required_sections' => [],
        'active' => true,
        ...$overrides,
    ]);
}

function d7Draft(User $user, ?Encounter $encounter = null, ?NoteTemplate $template = null, array $sections = []): ClinicalNote
{
    $encounter ??= d7Encounter($user);

    return app(ClinicalNoteService::class)->saveDraft(
        $encounter,
        $encounter->practitioner,
        [
            'subjective' => 'Subjective',
            'objective' => 'Objective',
            'assessment' => 'Assessment',
            'plan' => 'Plan',
            ...$sections,
        ],
        $user,
        null,
        $template,
    );
}

function d7Service(array $overrides = []): Service
{
    return Service::query()->create([
        'name' => 'Consult',
        'code' => 'CONS',
        'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => false,
        'active' => true,
        ...$overrides,
    ]);
}

function d7Resource(Branch $branch, StaffProfile $practitioner): BookableResource
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

function d7Book(Service $service, Patient $patient, Branch $branch, BookableResource $resource, User $user): Appointment
{
    return app(BookingService::class)->book(
        $service->id,
        $patient->id,
        $branch->id,
        '2026-07-13 10:00:00',
        [$resource->id],
        $user,
    );
}

function d7ReadRows(string $tenantId, string $patientId): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? AND patient_id = ? ORDER BY occurred_at ASC',
        [$tenantId, 'read', $patientId],
    ));
}

test('note editor is RBAC gated tenant scoped and renders the Inertia component', function () {
    $alpha = d7Tenant('alpha');
    $beta = d7Tenant('beta');

    d7Ctx()->set($alpha);
    $doctor = d7User($alpha, 'doctor');
    $template = d7Template(['required_sections' => ['subjective', 'objective']]);
    $note = d7Draft($doctor, null, $template);

    d7Ctx()->set($beta);
    $betaDoctor = d7User($beta, 'doctor');
    $betaNote = d7Draft($betaDoctor);

    d7Ctx()->set($alpha);

    $this->actingAs($doctor)
        ->get(route('clinical.notes.edit', $note->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinical/NoteEditor')
            ->where('note.id', $note->id)
            ->where('note.is_read_only', false)
            ->where('template.required_sections.0', 'subjective')
            ->where('actions.can_write', true)
            ->where('actions.can_sign', true)
            ->has('versions', 1));

    $unprivileged = d7User($alpha, '');
    $this->actingAs($unprivileged)->get(route('clinical.notes.edit', $note->id))->assertForbidden();
    $this->actingAs($doctor)->get(route('clinical.notes.edit', $betaNote->id))->assertNotFound();
});

test('signing locks the note and the server rejects later edits', function () {
    $tenant = d7Tenant('alpha');
    d7Ctx()->set($tenant);
    $doctor = d7User($tenant, 'doctor');
    $template = d7Template(['required_sections' => ['subjective', 'objective', 'assessment', 'plan']]);
    $note = d7Draft($doctor, null, $template);

    $this->actingAs($doctor)
        ->post(route('clinical.notes.sign', $note->id))
        ->assertRedirect(route('clinical.notes.edit', $note->id));

    $this->actingAs($doctor)
        ->get(route('clinical.notes.edit', $note->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinical/NoteEditor')
            ->where('note.status', ClinicalNote::STATUS_SIGNED)
            ->where('note.is_read_only', true));

    $this->actingAs($doctor)
        ->patchJson(route('clinical.notes.update', $note->id), ['subjective' => 'Illegal'])
        ->assertUnprocessable();

    expect($note->refresh()->subjective)->toBe('Subjective');
});

test('amendment flow requires a reason and returns the full version history', function () {
    $tenant = d7Tenant('alpha');
    d7Ctx()->set($tenant);
    $doctor = d7User($tenant, 'doctor');
    $signed = app(ClinicalNoteService::class)->sign(d7Draft($doctor), $doctor);

    $this->actingAs($doctor)
        ->postJson(route('clinical.notes.amend', $signed->id), ['reason' => ''])
        ->assertUnprocessable();

    $this->actingAs($doctor)
        ->post(route('clinical.notes.amend', $signed->id), ['reason' => 'Corrected wording'])
        ->assertRedirect();

    $amendment = ClinicalNote::query()->where('supersedes_id', $signed->id)->firstOrFail();

    expect($amendment->version)->toBe(2)
        ->and($amendment->amendment_reason)->toBe('Corrected wording')
        ->and($signed->refresh()->status)->toBe(ClinicalNote::STATUS_SIGNED)
        ->and($signed->supersedes_id)->toBeNull();

    $this->actingAs($doctor)
        ->get(route('clinical.notes.edit', $amendment->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinical/NoteEditor')
            ->has('versions', 2)
            ->where('versions.0.id', $signed->id)
            ->where('versions.1.id', $amendment->id)
            ->where('versions.1.amendment_reason', 'Corrected wording'));
});

test('patient chart is RBAC gated read logged and returns raw clinical sections', function () {
    $tenant = d7Tenant('alpha');
    d7Ctx()->set($tenant);
    $doctor = d7User($tenant, 'doctor');
    $patient = d7Patient();
    $encounter = d7Encounter($doctor, $patient);
    d7Draft($doctor, $encounter);
    app(CarePlanService::class)->create($patient, $encounter->practitioner, $doctor, [
        'title' => 'Recovery plan',
        'started_on' => '2026-07-09',
    ], [
        ['description' => 'Clinician-authored goal', 'target_date' => '2026-08-01'],
    ]);
    $lists = app(ClinicalListService::class);
    $lists->recordAllergy($patient, $encounter->practitioner, $doctor, [
        'substance' => 'Latex',
        'reaction' => 'Documented reaction',
        'severity' => Allergy::SEVERITY_UNKNOWN,
    ]);
    $lists->recordVital($patient, $encounter->practitioner, $doctor, [
        'recorded_at' => '2026-07-09 09:00:00',
        'systolic' => 120,
        'diastolic' => 80,
        'heart_rate' => 70,
        'temperature_c' => '36.8',
    ], $encounter);
    app(MedicationService::class)->record($patient, $encounter->practitioner, $doctor, [
        'name' => 'Metformin',
        'substance_key' => 'metformin',
        'dose_text' => 'Documented dose',
        'started_on' => '2026-07-09',
    ]);
    Document::query()->create([
        'patient_id' => $patient->id,
        'category' => Document::CATEGORY_RESULT,
        'title' => 'Result',
        'original_filename' => 'result.pdf',
        'storage_path' => 'tenants/'.$tenant->id.'/clinical-documents/'.$patient->id.'/result.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 10,
        'uploaded_by' => $doctor->id,
        'uploaded_at' => now(),
    ]);

    $this->actingAs($doctor)
        ->get(route('clinical.chart', $patient->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinical/Chart')
            ->where('patient.id', $patient->id)
            ->has('encounters', 1)
            ->has('notes', 1)
            ->has('allergies', 1)
            ->where('allergies.0.substance', 'Latex')
            ->where('vitals.0.systolic', 120)
            ->missing('vitals.0.flag')
            ->missing('vitals.0.score')
            ->missing('vitals.0.interpretation')
            ->has('documents', 1)
            ->has('carePlans', 1)
            ->where('carePlans.0.title', 'Recovery plan')
            ->where('carePlans.0.goals.0.description', 'Clinician-authored goal')
            ->has('referrals')
            ->has('recalls'));

    expect(d7ReadRows($tenant->id, $patient->id))->toHaveCount(2)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();

    $unprivileged = d7User($tenant, '');
    $this->actingAs($unprivileged)->get(route('clinical.chart', $patient->id))->assertForbidden();
});

test('day-board document action opens an encounter creates a draft note and redirects to the editor', function () {
    $tenant = d7Tenant('alpha');
    d7Ctx()->set($tenant);
    $doctor = d7User($tenant, 'doctor');
    $branch = d7Branch();
    $patient = d7Patient();
    $practitioner = d7Practitioner($branch, $doctor);
    $service = d7Service();
    $resource = d7Resource($branch, $practitioner);
    $appointment = d7Book($service, $patient, $branch, $resource, $doctor);
    d7Template(['name' => 'Default SOAP']);

    $this->actingAs($doctor)
        ->get(route('scheduling.day-board', ['branch_id' => $branch->id, 'date' => '2026-07-13']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Scheduling/DayBoard')
            ->has('actions.openEncounterUrl')
            ->where('appointments.0.patient_id', $patient->id));

    $this->actingAs($doctor)
        ->post(route('scheduling.day-board.open-encounter'), ['appointment_id' => $appointment->id])
        ->assertRedirect();

    $encounter = Encounter::query()->firstOrFail();
    $note = ClinicalNote::query()->firstOrFail();

    expect($encounter->appointment_id)->toBe($appointment->id)
        ->and($appointment->refresh()->status)->toBe(Appointment::STATUS_IN_PROGRESS)
        ->and($note->encounter_id)->toBe($encounter->id)
        ->and($note->status)->toBe(ClinicalNote::STATUS_DRAFT)
        ->and($note->subjective)->toBe('Template subjective');
});
