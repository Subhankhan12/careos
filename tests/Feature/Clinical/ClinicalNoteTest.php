<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\NoteTemplate;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\Clinical\Services\EncounterService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function d2Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function d2Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function d2Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function d2User(Tenant $tenant, string $role = 'doctor'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    if ($role !== '') {
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => d2Role($role)->id,
        ]);
    }

    return $user;
}

function d2Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function d2Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Nora',
        'last_name' => 'Notes',
        'date_of_birth' => '1981-02-03',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function d2Practitioner(Branch $branch, array $overrides = []): StaffProfile
{
    return StaffProfile::query()->create([
        'first_name' => 'Casey',
        'last_name' => 'Clinician',
        'display_name' => 'Casey Clinician',
        'profession' => 'doctor',
        'primary_branch_id' => $branch->id,
        ...$overrides,
    ]);
}

function d2Encounter(User $user, ?Patient $patient = null, ?StaffProfile $practitioner = null, ?Branch $branch = null): Encounter
{
    $branch ??= d2Branch();
    $patient ??= d2Patient();
    $practitioner ??= d2Practitioner($branch);

    return app(EncounterService::class)->open(
        $patient,
        $practitioner,
        $branch,
        null,
        Encounter::TYPE_CONSULTATION,
        $user,
    );
}

function d2Template(array $overrides = []): NoteTemplate
{
    return NoteTemplate::query()->create([
        'name' => 'SOAP Standard',
        'specialty' => null,
        'default_subjective' => null,
        'default_objective' => null,
        'default_assessment' => null,
        'default_plan' => null,
        'required_sections' => [],
        'active' => true,
        ...$overrides,
    ]);
}

function d2Draft(User $user, ?Encounter $encounter = null, ?StaffProfile $author = null, array $sections = [], ?NoteTemplate $template = null): ClinicalNote
{
    $encounter ??= d2Encounter($user);
    $author ??= $encounter->practitioner;

    return app(ClinicalNoteService::class)->saveDraft(
        $encounter,
        $author,
        [
            'subjective' => 'Subjective text',
            'objective' => 'Objective text',
            'assessment' => 'Assessment text',
            'plan' => 'Plan text',
            ...$sections,
        ],
        $user,
        null,
        $template,
    );
}

function d2AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

function d2Snapshot(ClinicalNote $note): array
{
    $note = $note->refresh();

    return [
        'subjective' => $note->subjective,
        'objective' => $note->objective,
        'assessment' => $note->assessment,
        'plan' => $note->plan,
        'status' => $note->status,
        'signed_at' => $note->signed_at?->format('Y-m-d H:i:s'),
        'signed_by' => $note->signed_by,
        'version' => $note->version,
        'supersedes_id' => $note->supersedes_id,
        'amendment_reason' => $note->amendment_reason,
        'updated_at' => $note->updated_at?->format('Y-m-d H:i:s'),
    ];
}

test('draft clinical notes are editable', function () {
    $tenant = d2Tenant('alpha');
    d2Ctx()->set($tenant);
    $user = d2User($tenant);
    $note = d2Draft($user);

    $updated = app(ClinicalNoteService::class)->saveDraft(
        $note->encounter,
        $note->author,
        ['subjective' => 'Edited subjective'],
        $user,
        $note,
    );

    expect($updated->subjective)->toBe('Edited subjective')
        ->and($updated->status)->toBe(ClinicalNote::STATUS_DRAFT);
});

test('signed notes cannot be updated or deleted at the model level', function () {
    $tenant = d2Tenant('alpha');
    d2Ctx()->set($tenant);
    $user = d2User($tenant);
    $signed = app(ClinicalNoteService::class)->sign(d2Draft($user), $user);

    $signed->subjective = 'Illegal edit';

    expect(fn () => $signed->save())->toThrow(LogicException::class)
        ->and(fn () => $signed->refresh()->delete())->toThrow(LogicException::class);
});

test('database triggers block signed raw updates and deletes but allow draft updates', function () {
    $tenant = d2Tenant('alpha');
    d2Ctx()->set($tenant);
    $user = d2User($tenant);
    $draft = d2Draft($user);

    expect(DB::update(
        'update clinical_notes set subjective = ? where id = ?',
        ['Raw draft edit', $draft->id],
    ))->toBe(1);

    expect($draft->refresh()->subjective)->toBe('Raw draft edit');

    $signed = app(ClinicalNoteService::class)->sign($draft, $user);

    expect(fn () => DB::update(
        'update clinical_notes set subjective = ? where id = ?',
        ['Raw signed edit', $signed->id],
    ))->toThrow(QueryException::class)
        ->and(fn () => DB::delete(
            'delete from clinical_notes where id = ?',
            [$signed->id],
        ))->toThrow(QueryException::class)
        ->and($signed->refresh()->subjective)->toBe('Raw draft edit');
});

test('amend requires a reason creates a new version and leaves the original unchanged', function () {
    $tenant = d2Tenant('alpha');
    d2Ctx()->set($tenant);
    $user = d2User($tenant);
    $signed = app(ClinicalNoteService::class)->sign(d2Draft($user), $user);
    $originalSnapshot = d2Snapshot($signed);

    expect(fn () => app(ClinicalNoteService::class)->amend(
        $signed,
        ['plan' => 'Updated plan'],
        '',
        $signed->author,
        $user,
    ))->toThrow(InvalidArgumentException::class);

    $amendment = app(ClinicalNoteService::class)->amend(
        $signed,
        ['plan' => 'Updated plan'],
        'Correct plan wording',
        $signed->author,
        $user,
    );
    $signedAmendment = app(ClinicalNoteService::class)->sign($amendment, $user);

    expect($amendment->id)->not->toBe($signed->id)
        ->and($amendment->supersedes_id)->toBe($signed->id)
        ->and($amendment->version)->toBe(2)
        ->and($amendment->amendment_reason)->toBe('Correct plan wording')
        ->and($signedAmendment->status)->toBe(ClinicalNote::STATUS_SIGNED)
        ->and(d2Snapshot($signed))->toBe($originalSnapshot);
});

test('versionsFor returns the full ordered amendment chain', function () {
    $tenant = d2Tenant('alpha');
    d2Ctx()->set($tenant);
    $user = d2User($tenant);
    $service = app(ClinicalNoteService::class);
    $first = $service->sign(d2Draft($user), $user);
    $second = $service->sign($service->amend($first, ['plan' => 'Version two'], 'First amendment', $first->author, $user), $user);
    $third = $service->amend($second, ['plan' => 'Version three'], 'Second amendment', $second->author, $user);

    expect($service->versionsFor($third)->pluck('id')->all())->toBe([
        $first->id,
        $second->id,
        $third->id,
    ]);
});

test('sign enforces template required SOAP sections', function () {
    $tenant = d2Tenant('alpha');
    d2Ctx()->set($tenant);
    $user = d2User($tenant);
    $template = d2Template([
        'default_subjective' => 'Template subjective',
        'required_sections' => ['subjective', 'objective', 'assessment', 'plan'],
    ]);
    $encounter = d2Encounter($user);
    $note = app(ClinicalNoteService::class)->saveDraft(
        $encounter,
        $encounter->practitioner,
        [
            'objective' => 'Objective only',
            'assessment' => '',
            'plan' => '',
        ],
        $user,
        null,
        $template,
    );

    expect($note->subjective)->toBe('Template subjective')
        ->and(fn () => app(ClinicalNoteService::class)->sign($note, $user))
        ->toThrow(InvalidArgumentException::class);

    $completed = app(ClinicalNoteService::class)->saveDraft(
        $note->encounter,
        $note->author,
        [
            'subjective' => $note->subjective,
            'objective' => $note->objective,
            'assessment' => 'Assessment now present',
            'plan' => 'Plan now present',
        ],
        $user,
        $note,
        $template,
    );

    expect(app(ClinicalNoteService::class)->sign($completed, $user)->status)
        ->toBe(ClinicalNote::STATUS_SIGNED);
});

test('note signed and amended events are audited and the chain verifies', function () {
    $tenant = d2Tenant('alpha');
    d2Ctx()->set($tenant);
    $user = d2User($tenant);
    $service = app(ClinicalNoteService::class);
    $signed = $service->sign(d2Draft($user), $user);
    $amendment = $service->amend($signed, ['objective' => 'Clarified objective'], 'Clarify objective', $signed->author, $user);

    expect(d2AuditRows($tenant->id, 'note.signed'))->toHaveCount(1)
        ->and(d2AuditRows($tenant->id, 'note.amended'))->toHaveCount(1)
        ->and(d2AuditRows($tenant->id, 'note.amended')->first()->resource_id)->toBe($amendment->id)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('reading a clinical note writes a patient scoped read audit row', function () {
    $tenant = d2Tenant('alpha');
    d2Ctx()->set($tenant);
    $user = d2User($tenant);
    $note = d2Draft($user);

    $this->actingAs($user)
        ->getJson(route('clinical.notes.show', $note->id))
        ->assertOk()
        ->assertJsonPath('note.id', $note->id);

    $rows = d2AuditRows($tenant->id, 'read');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->resource_type)->toBe('clinical_note')
        ->and($rows[0]->resource_id)->toBe($note->id)
        ->and($rows[0]->patient_id)->toBe($note->patient_id);
});

test('clinical notes and templates are tenant isolated and fail closed', function () {
    $alpha = d2Tenant('alpha');
    $beta = d2Tenant('beta');

    d2Ctx()->set($alpha);
    $alphaUser = d2User($alpha);
    $alphaNote = d2Draft($alphaUser);
    d2Template(['name' => 'Alpha Template']);

    d2Ctx()->set($beta);
    $betaUser = d2User($beta);
    d2Draft($betaUser);
    d2Template(['name' => 'Beta Template']);

    d2Ctx()->set($alpha);

    expect(ClinicalNote::query()->count())->toBe(1)
        ->and(ClinicalNote::query()->first()->is($alphaNote))->toBeTrue()
        ->and(NoteTemplate::query()->pluck('name')->all())->toBe(['Alpha Template']);

    d2Ctx()->forget();

    expect(fn () => ClinicalNote::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => NoteTemplate::query()->count())->toThrow(TenantContextMissingException::class);
});

test('note write and sign permissions are clinician gated', function () {
    $tenant = d2Tenant('alpha');
    d2Ctx()->set($tenant);
    $doctor = d2User($tenant, 'doctor');
    $nurse = d2User($tenant, 'nurse');
    $reception = d2User($tenant, 'reception');
    $encounter = d2Encounter($doctor);
    $author = $encounter->practitioner;
    $note = d2Draft($doctor, $encounter, $author);

    expect(Gate::forUser($doctor)->allows('note.write'))->toBeTrue()
        ->and(Gate::forUser($doctor)->allows('note.sign'))->toBeTrue()
        ->and(Gate::forUser($nurse)->allows('note.write'))->toBeTrue()
        ->and(Gate::forUser($nurse)->allows('note.sign'))->toBeTrue()
        ->and(Gate::forUser($reception)->allows('note.write'))->toBeFalse()
        ->and(Gate::forUser($reception)->allows('note.sign'))->toBeFalse()
        ->and(fn () => app(ClinicalNoteService::class)->saveDraft(
            $encounter,
            $author,
            ['subjective' => 'Nope'],
            $reception,
        ))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ClinicalNoteService::class)->sign($note, $reception))
        ->toThrow(AuthorizationException::class);
});
