<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Radiology\Models\RadiologyOrder;
use Modules\Radiology\Services\ImagingStudyService;
use Modules\Radiology\Services\RadiologyCatalogService;
use Modules\Radiology\Services\RadiologyOrderService;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| QA-FIX.8b — P8-C2: a clinical report's author is the actor, never a guess
|--------------------------------------------------------------------------
| `ImagingReportController::resolve()` ended in
|     ?? StaffProfile::query()->orderBy('display_name')->firstOrFail()
| so when the acting user had no linked StaffProfile the report was authored by
| whoever sorted FIRST ALPHABETICALLY in the tenant. Driven in Phase 8: a report
| written by `miriam.lang` was stored as **Beat Suter**, a coordinator — the same
| person both Phase-7 criticals landed on, and the third time in this programme.
|
| THIS IS THE FINDING THAT REWROTE CROSS-PHASE PATTERN 7. Seven phases described
| the pattern as "attribution by dropdown default". THIS MODULE HAS NO DROPDOWN.
| The substitution was server-side and invisible, on a SIGNED clinical report, so
| QA-FIX.7a's remedy (remove the default, make it an explicit choice) would not
| have touched it. The real shape is RESOLVING A PERSON BY CONVENIENCE WHEN THE
| IDENTITY IS UNKNOWN.
|
| THE PRINCIPLE ALREADY EXISTED: `StaffProfile::forUser()` returns null rather
| than guessing (QA-FIX.2a, D-195), and two Clinical controllers already refuse
| on it in as many words — "Refuse rather than guess. Authoring a clinical note
| to somebody who did not write it is precisely the defect being closed."
|
| IT WAS WORSE THAN ONE WRONG COLUMN: the resolved profile is also passed to
| `reportEncounter()`, so a substituted author ALSO became the report encounter's
| practitioner. Two records wrong, not one.
|
| SIGNING IS DELIBERATELY UNAFFECTED. A signature is the acting USER
| (`clinical_notes.signed_by`), so `sign()` needs no profile and stays reachable
| for an account that has none. Only AUTHORING refuses.
*/

/** @return array{tenant: Tenant, radiologist: User, order: RadiologyOrder, alphabeticallyFirst: StaffProfile, ownProfile: StaffProfile} */
function rraFixture(string $slug): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);

    $radiologist = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $radiologist->id, 'role_id' => Role::where('key', 'radiologist')->firstOrFail()->id]);

    // THE FIXTURE MUST MAKE THE ACTOR ≠ THE ALPHABETICALLY FIRST PROFILE, AND ASSERT IT. That is the
    // reason P2-C1, P6-C2 and P7-C1 all survived their own suites: every fixture had one plausible person,
    // so a substitution was invisible. `Aaron Aardvark` sorts first and is nobody's account.
    $alphabeticallyFirst = StaffProfile::query()->create([
        'first_name' => 'Aaron', 'last_name' => 'Aardvark', 'display_name' => 'Aaron Aardvark',
        'profession' => 'coordinator', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $ownProfile = StaffProfile::query()->create([
        'first_name' => 'Rita', 'last_name' => 'Roentgen', 'display_name' => 'Dr Rita Roentgen',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
        'user_id' => $radiologist->id,
    ]);

    // The precondition the whole finding rests on, asserted rather than assumed.
    expect(StaffProfile::query()->orderBy('display_name')->firstOrFail()->id)->toBe($alphabeticallyFirst->id)
        ->and($alphabeticallyFirst->id)->not->toBe($ownProfile->id);

    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $exam = app(RadiologyCatalogService::class)->authorExam($radiologist, 'RAD-CXR', 'Chest X-ray', 'Röntgen', 'Thorax');
    $order = app(RadiologyOrderService::class)->place($radiologist, $patient, $exam, RadiologyOrder::PRIORITY_ROUTINE)['radiologyOrder'];
    $study = app(ImagingStudyService::class)->acquire($radiologist, $order);
    expect($study)->not->toBeNull();

    return compact('tenant', 'radiologist', 'order', 'alphabeticallyFirst', 'ownProfile');
}

// ------------------------------------------------------------ THE PROPERTY ----

test('P8-C2: a report records the ACTOR as its author — not the alphabetically first profile', function () {
    $fx = rraFixture('rra-actor');
    app(TenantContext::class)->forget();

    test()->actingAs($fx['radiologist'])
        ->post('/radiology/orders/'.$fx['order']->id.'/report', [
            'findings' => 'Lungenfelder frei.',
            'impression' => 'Unauffällig.',
        ])->assertRedirect();

    $note = ClinicalNote::query()->firstOrFail();

    expect($note->author_id)->toBe($fx['ownProfile']->id)
        // THE ASSERTION THAT WOULD HAVE CAUGHT IT. Before the fix this passed only because the fixture
        // had a single profile; with a distinct alphabetical-first present, the old code stored THAT one.
        ->and($note->author_id)->not->toBe($fx['alphabeticallyFirst']->id);
});

test('P8-C2: the report ENCOUNTER is attributed to the actor too — the second record the guess corrupted', function () {
    $fx = rraFixture('rra-encounter');
    app(TenantContext::class)->forget();

    test()->actingAs($fx['radiologist'])
        ->post('/radiology/orders/'.$fx['order']->id.'/report', ['findings' => 'Frei.', 'impression' => 'Unauffällig.'])
        ->assertRedirect();

    // `reportEncounter()` passes the SAME resolved profile to EncounterService::open, so a substituted
    // author also became the encounter's practitioner. One guess, two wrong records.
    $note = ClinicalNote::query()->firstOrFail();
    $encounter = Encounter::query()->findOrFail($note->encounter_id);

    expect($encounter->practitioner_id)->toBe($fx['ownProfile']->id)
        ->and($encounter->practitioner_id)->not->toBe($fx['alphabeticallyFirst']->id);
});

test('P8-C2: an unidentifiable actor is REFUSED, and no report is written in anyone else\'s name', function () {
    $fx = rraFixture('rra-refuse');

    // The state the fallback existed for: an account with no linked StaffProfile. No product surface
    // creates or removes that link, so it is arranged here — it is the DEFAULT state of a newly
    // provisioned user, since role assignment does not create a profile.
    StaffProfile::query()->whereKey($fx['ownProfile']->id)->update(['user_id' => null]);
    app(TenantContext::class)->forget();

    $response = test()->actingAs($fx['radiologist'])
        ->from('/radiology/orders/'.$fx['order']->id.'/report')
        ->post('/radiology/orders/'.$fx['order']->id.'/report', ['findings' => 'Frei.', 'impression' => 'Unauffällig.']);

    // THE PROPERTY (D-182): before the fix this wrote a note authored by `Aaron Aardvark`.
    expect(ClinicalNote::query()->count())->toBe(0);
    $response->assertRedirect()->assertSessionHasErrors('radiology_report');
});

test('P8-C2: SIGNING still works for an account with no staff profile — a signature is the USER', function () {
    // The legitimate path the refusal must NOT break. `sign()` sets `clinical_notes.signed_by` from the
    // acting user and needs no profile at all, so blocking it would have been an over-correction.
    $fx = rraFixture('rra-sign');
    app(TenantContext::class)->forget();

    // Author first (while identifiable), then drop the link and sign.
    test()->actingAs($fx['radiologist'])
        ->post('/radiology/orders/'.$fx['order']->id.'/report', ['findings' => 'Frei.', 'impression' => 'Unauffällig.'])
        ->assertRedirect();

    app(TenantContext::class)->set($fx['tenant']);
    StaffProfile::query()->whereKey($fx['ownProfile']->id)->update(['user_id' => null]);
    app(TenantContext::class)->forget();

    test()->actingAs($fx['radiologist'])
        ->post('/radiology/orders/'.$fx['order']->id.'/report/sign')
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $note = ClinicalNote::query()->firstOrFail();
    expect($note->status)->toBe(ClinicalNote::STATUS_SIGNED)
        ->and($note->signed_by)->toBe($fx['radiologist']->id);
});

test('P8-C2: the author / signatory split still works where the two are legitimately different people', function () {
    // Phase 2 found the seeded reports authored by Dr. Lang and signed by Dr. Berg — a REAL two-person
    // shape this fix must preserve. The author is the acting clinician's profile; the signatory is
    // whichever USER signs, and they are different id spaces that never stand in for one another (D-195).
    $fx = rraFixture('rra-split');

    $other = User::factory()->forTenant($fx['tenant'])->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $other->id, 'role_id' => Role::where('key', 'radiologist')->firstOrFail()->id]);
    app(TenantContext::class)->forget();

    test()->actingAs($fx['radiologist'])
        ->post('/radiology/orders/'.$fx['order']->id.'/report', ['findings' => 'Frei.', 'impression' => 'Unauffällig.'])
        ->assertRedirect();

    test()->actingAs($other)
        ->post('/radiology/orders/'.$fx['order']->id.'/report/sign')
        ->assertRedirect();

    $note = ClinicalNote::query()->firstOrFail();
    expect($note->author_id)->toBe($fx['ownProfile']->id)   // authored by the radiologist who wrote it
        ->and($note->signed_by)->toBe($other->id)           // signed by whoever signed
        ->and($note->signed_by)->not->toBe($fx['radiologist']->id);
});

// ------------------------------------------------------- STRUCTURAL GUARD ----

test('P8-C2 STRUCTURAL GUARD: no person is resolved by convenience anywhere in Lab or Radiology', function () {
    // Mutation-checked: restoring the `?? StaffProfile::query()->orderBy(...)->firstOrFail()` fallback
    // reddens this. Comments are stripped first — the fixed controller explains the old defect and quotes
    // it, and a fence its own rationale trips is a fence that gets deleted (the QA-FIX.7b lesson).
    $strip = function (string $src): string {
        $src = preg_replace('~/\*.*?\*/~s', ' ', $src) ?? $src;

        return preg_replace('~(?<![:\'"])//[^\n]*~', ' ', $src) ?? $src;
    };

    $files = collect(File::allFiles(base_path('Modules/Radiology/src')))
        ->merge(File::allFiles(base_path('Modules/Lab/src')))
        ->filter(fn ($f): bool => $f->getExtension() === 'php');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $code = $strip((string) File::get($file->getPathname()));

        // A SINGLE person chosen by sort order. Option LISTS (`->get()`) are a different thing and stay
        // legal — they present choices; they do not resolve an identity.
        expect(preg_match('~StaffProfile::query\(\)[^;]*orderBy\([^;]*->(first|firstOrFail)\(\)~s', $code))
            ->toBe(0, "a person must not be resolved by convenience — {$file->getRelativePathname()}");
    }

    // And the module uses the principle that already existed.
    $controller = (string) File::get(base_path('Modules/Radiology/src/Http/Controllers/ImagingReportController.php'));
    expect($controller)->toContain('StaffProfile::forUser(');
});

test('P8-C2: the refusal is VISIBLE — the report page renders the error bag', function () {
    // A refusal nobody can see is the P6-C3 defect. This part INTRODUCES a refusal on this page, so the
    // page must be able to show one; P8-H1 (the other eleven Lab/Radiology pages) stays open.
    $vue = (string) file_get_contents(resource_path('js/pages/Radiology/Report.vue'));

    expect($vue)->toContain('<RefusalNotice />')
        ->and($vue)->toContain("import RefusalNotice from '@/Components/RefusalNotice.vue';");
});
