<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\ED\Exceptions\EdVisitException;
use Modules\ED\Models\EdVisit;
use Modules\ED\Models\EdVisitEncounter;
use Modules\ED\Services\EdDocumentationService;
use Modules\ED\Services\EdVisitService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * ED.G4 — ED clinical documentation: the visit's clinical record, REUSING Clinical (a treatment Encounter tied
 * to the visit ED-side, sign-and-lock ClinicalNote, raw Vital, structured Order) WITHOUT modifying Encounter.
 * Per docs/HOSPITAL-PHASE6-ED-MAP.md §2. FENCE carries through: raw vitals, no computed acuity/severity/score.
 */

function eddCtx(): TenantContext
{
    return app(TenantContext::class);
}

function eddUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, branch: Branch, physician: User, reception: User, physicianProfile: StaffProfile, patient: Patient, visit: EdVisit} */
function eddFixture(string $slug = 'eddoc'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' ED', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    eddCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $physician = eddUser($tenant, 'ed_physician');   // encounter.manage + note.write/sign + order.manage + ed.manage
    $reception = eddUser($tenant, 'reception');       // patient.view only (no encounter.manage)
    $physicianProfile = StaffProfile::query()->create([
        'first_name' => 'Ravi', 'last_name' => 'Rao', 'display_name' => 'Dr Ravi Rao',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $visit = app(EdVisitService::class)->register($physician, $patient, $branch, EdVisit::ARRIVAL_AMBULANCE, 'Chest pain');

    return compact('tenant', 'branch', 'physician', 'reception', 'physicianProfile', 'patient', 'visit');
}

test('an ED treatment encounter REUSES a Clinical Encounter tied to the visit; Encounter stays UNMODIFIED and its invariant holds', function () {
    $fx = eddFixture();
    $docs = app(EdDocumentationService::class);

    $result = $docs->startEncounter($fx['physician'], $fx['visit'], $fx['physicianProfile'], 'Assessment');
    $encounter = Encounter::query()->findOrFail($result['note']->encounter_id);

    expect($result['note'])->toBeInstanceOf(ClinicalNote::class)
        ->and($encounter->type)->toBe(Encounter::TYPE_CONSULTATION)   // a reused Clinical type (no ED type added)
        // The link is ED-side.
        ->and(EdVisitEncounter::query()->where('ed_visit_id', $fx['visit']->id)->where('encounter_id', $encounter->id)->count())->toBe(1);

    // ENCOUNTER UNMODIFIED: no ED column was added to Clinical's schema (the association is ED-side).
    $cols = Schema::getColumnListing('encounters');
    expect($cols)->not->toContain('ed_visit_id')->and($cols)->not->toContain('ed_triage_id');

    // THE INVARIANT HOLDS: the encounter is kept open; a SECOND concurrent encounter for the SAME patient +
    // practitioner is REFUSED by the reused EncounterService (one-open-per-practitioner) — unchanged for every vertical.
    expect(fn () => $docs->startEncounter($fx['physician'], $fx['visit'], $fx['physicianProfile']))
        ->toThrow(InvalidArgumentException::class);
});

test('an ED note goes through the EXISTING sign-and-lock flow (write → sign → read-only → amend → version) against the visit', function () {
    $fx = eddFixture();
    $docs = app(EdDocumentationService::class);
    $notes = app(ClinicalNoteService::class);

    $note = $docs->startEncounter($fx['physician'], $fx['visit'], $fx['physicianProfile'])['note'];
    $encounter = Encounter::query()->findOrFail($note->encounter_id);

    // Write sections then sign — the existing flow, unchanged.
    $notes->saveDraft($encounter, $fx['physicianProfile'], ['assessment' => 'Chest pain, ECG ordered.'], $fx['physician'], $note, null);
    $signed = $notes->sign($note->fresh(), $fx['physician']);
    expect($signed->status)->toBe(ClinicalNote::STATUS_SIGNED)
        // Signed = read-only (immutable at the model level).
        ->and(fn () => $signed->fresh()->update(['assessment' => 'tampered']))->toThrow(LogicException::class);

    // Amend creates a NEW superseding version (the original is never modified) — the existing append-only flow.
    $amended = $notes->amend($signed->fresh(), ['assessment' => 'Chest pain; troponin negative.'], 'Add result', $fx['physicianProfile'], $fx['physician']);
    expect($amended->id)->not->toBe($signed->id)
        ->and($amended->version)->toBeGreaterThan($signed->version);
});

test('a vital recorded during the visit is RAW and the forVisit read returns it (no bands/flags/scores)', function () {
    $fx = eddFixture();
    $docs = app(EdDocumentationService::class);

    // A vital needs a treatment encounter first (charting scopes to the visit's encounter).
    expect(fn () => $docs->recordVital($fx['physician'], $fx['visit'], ['heart_rate' => 96]))
        ->toThrow(EdVisitException::class);

    $docs->startEncounter($fx['physician'], $fx['visit'], $fx['physicianProfile']);
    $docs->recordVital($fx['physician'], $fx['visit'], ['systolic' => 138, 'diastolic' => 86, 'heart_rate' => 96, 'spo2' => 98]);

    $vitals = $docs->vitalsForVisit($fx['visit']);
    expect($vitals['metrics']['heart_rate'])->toHaveCount(1)           // the forVisit read returns it
        ->and($vitals['metrics']['heart_rate'][0]['value'])->toBe(96)  // RAW value
        ->and($vitals['metrics']['systolic'][0]['value'])->toBe(138);
});

test('FENCE: the ED clinical-record payload carries raw vitals + note status but NO computed acuity/severity/score', function () {
    $fx = eddFixture();
    $docs = app(EdDocumentationService::class);
    $docs->startEncounter($fx['physician'], $fx['visit'], $fx['physicianProfile']);
    $docs->recordVital($fx['physician'], $fx['visit'], ['heart_rate' => 92]);

    eddCtx()->forget();
    $this->actingAs($fx['physician'])->get('/ed/visits/'.$fx['visit']->id.'/record')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ED/Documentation')
            ->has('vitals.metrics.heart_rate', 1)  // raw vitals present
            ->has('encounters', 1)
            // NO computed-judgment field anywhere in the record payload.
            ->missing('acuity')
            ->missing('severity')
            ->missing('score')
            ->missing('deterioration')
            ->missing('early_warning')
            ->missing('vitals.acuity')
            ->etc());
});

test('RBAC: documentation reuses the existing clinical gates; reception (no encounter.manage) is refused', function () {
    $fx = eddFixture();
    $docs = app(EdDocumentationService::class);

    // reception holds patient.view (can VIEW the record) but NOT encounter.manage — starting an encounter is
    // refused by the reused EncounterService (which authorizes encounter.manage).
    expect(fn () => $docs->startEncounter($fx['reception'], $fx['visit'], $fx['physicianProfile']))
        ->toThrow(AuthorizationException::class);

    // The ED physician carries the clinical gates and can document.
    expect($docs->startEncounter($fx['physician'], $fx['visit'], $fx['physicianProfile'])['note'])->toBeInstanceOf(ClinicalNote::class);
});

test('cross-tenant is fail-closed: a tenant cannot document another tenant ED visit', function () {
    $fxA = eddFixture('alpha');
    $visitA = $fxA['visit'];

    $fxB = eddFixture('beta');
    eddCtx()->set($fxB['tenant']);

    // Under tenant B, tenant A's visit (and its patient) is invisible — documentation fails closed.
    expect(fn () => app(EdDocumentationService::class)->startEncounter($fxB['physician'], $visitA, $fxB['physicianProfile']))
        ->toThrow(ModelNotFoundException::class);
});
