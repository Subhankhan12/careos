<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\ED\Models\EdTriage;
use Modules\ED\Models\EdVisit;
use Modules\ED\Services\EdVisitService;
use Modules\ED\Services\TriageService;
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
|--------------------------------------------------------------------------
| QA-FIX.7b — P7-C3: the ED board orders by recorded acuity, not by string
|--------------------------------------------------------------------------
| `Board.vue:52` sorted with `localeCompare` on the level string. ESI and CTAS
| survived that by luck ('1'…'5' sort the way they read); MANCHESTER did not.
| Its levels are red · orange · yellow · green · blue, which sort alphabetically
| to blue · green · orange · red · yellow — so under a control labelled
| "Recorded acuity" the board showed the LEAST urgent patient first. Driven in
| Phase 7: MANCHESTER blue rendered above MANCHESTER red.
|
| THE FIX ORDERS BY THE LEVEL'S POSITION IN ITS OWN SCALE. That position is a
| transcription of what the scale publishes, read off `EdTriage::LEVELS` — the
| level is the nurse's judgment, the order is the scale's, and CareOS adds
| neither. It is NOT a computed priority, and the acuity fence is unchanged.
|
| NO CROSS-SCALE EQUIVALENCE EXISTS, deliberately (D-170). Positions compare
| only within one scale; mapping ESI 2 onto Manchester orange is a clinical
| claim this product has no basis to make, so the board GROUPS by scale and
| orders within each group.
|
| D-212 makes the ORDER of `EdTriage::LEVELS` load-bearing and states it in the
| constant's own docblock, because an ordering that is only conventional is not
| a fence (D-191) — an innocent "tidy up / alphabetise" would silently invert a
| clinical display. The first test below is that fence.
*/

function edaoCtx(): TenantContext
{
    return app(TenantContext::class);
}

/** @return array{tenant: Tenant, branch: Branch, staff: User, nurse: StaffProfile, red: EdVisit, blue: EdVisit} */
function edaoFixture(string $slug = 'edao'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' ED', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    edaoCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $staff = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $staff->id, 'role_id' => Role::where('key', 'ed_charge_nurse')->firstOrFail()->id]);

    $nurse = StaffProfile::query()->create([
        'first_name' => 'Nadia', 'last_name' => 'Nurse', 'display_name' => 'Nadia Nurse',
        'profession' => 'nurse', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);

    $visits = app(EdVisitService::class);
    $triage = app(TriageService::class);

    // The LEAST urgent patient arrives FIRST, so arrival order and acuity order disagree — otherwise the
    // assertion below could pass on the arrival sort and prove nothing.
    $pBlue = app(PatientService::class)->create(['first_name' => 'Bea', 'last_name' => 'Blue', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $blue = $visits->register($staff, $pBlue, $branch, EdVisit::ARRIVAL_WALK_IN, 'Sprained ankle', now()->subMinutes(40));
    $triage->record($staff, $blue, $nurse, 'Sprained ankle', EdTriage::SCALE_MANCHESTER, 'blue');

    $pRed = app(PatientService::class)->create(['first_name' => 'Rita', 'last_name' => 'Red', 'date_of_birth' => '1975-05-05', 'sex' => 'female']);
    $red = $visits->register($staff, $pRed, $branch, EdVisit::ARRIVAL_AMBULANCE, 'Cardiac arrest', now()->subMinutes(5));
    $triage->record($staff, $red, $nurse, 'Cardiac arrest', EdTriage::SCALE_MANCHESTER, 'red');

    return compact('tenant', 'branch', 'staff', 'nurse', 'red', 'blue');
}

// --------------------------------------------- THE ORDER IS THE FENCE (D-212) ----

test('D-212: each scale is declared in ITS OWN published order, most urgent first — pinned exactly', function () {
    // THIS IS THE FENCE, NOT A TAUTOLOGY. The board now orders by position in these lists, so their order
    // is load-bearing: alphabetising MANCHESTER (a plausible tidy-up) would silently invert a clinical
    // display with no other test noticing. D-191 — an ordering meaningful only by convention is not a fence.
    expect(EdTriage::LEVELS[EdTriage::SCALE_MANCHESTER])->toBe(['red', 'orange', 'yellow', 'green', 'blue'])
        ->and(EdTriage::LEVELS[EdTriage::SCALE_ESI])->toBe(['1', '2', '3', '4', '5'])
        ->and(EdTriage::LEVELS[EdTriage::SCALE_CTAS])->toBe(['1', '2', '3', '4', '5']);

    // And the docblock states it, so the next reader is not left to infer it from a test.
    $model = (string) file_get_contents(base_path('Modules/ED/src/Models/EdTriage.php'));
    expect($model)->toContain('THE ORDER OF EACH LIST IS LOAD-BEARING');
});

test('levelPosition reads the scale\'s declared order — and Manchester is the case that used to invert', function () {
    expect(EdTriage::levelPosition(EdTriage::SCALE_MANCHESTER, 'red'))->toBe(1)     // most urgent
        ->and(EdTriage::levelPosition(EdTriage::SCALE_MANCHESTER, 'blue'))->toBe(5) // least urgent
        ->and(EdTriage::levelPosition(EdTriage::SCALE_ESI, '1'))->toBe(1)
        ->and(EdTriage::levelPosition(EdTriage::SCALE_ESI, '5'))->toBe(5)
        ->and(EdTriage::levelPosition(EdTriage::SCALE_CTAS, '2'))->toBe(2);

    // THE PROPERTY, stated as the defect: alphabetically `blue` precedes `red`; by position it does not.
    expect('blue' < 'red')->toBeTrue()
        ->and(EdTriage::levelPosition(EdTriage::SCALE_MANCHESTER, 'blue'))
        ->toBeGreaterThan(EdTriage::levelPosition(EdTriage::SCALE_MANCHESTER, 'red'));

    // Unknown scale or level yields null — never a guessed position (the "refuse, do not guess" rule).
    expect(EdTriage::levelPosition('APACHE', '2'))->toBeNull()
        ->and(EdTriage::levelPosition(EdTriage::SCALE_MANCHESTER, 'puce'))->toBeNull()
        ->and(EdTriage::levelPosition(EdTriage::SCALE_ESI, '9'))->toBeNull();
});

// ------------------------------------------------------ THE BOARD PAYLOAD ----

test('P7-C3: the board sends each recorded acuity with its position in its own scale', function () {
    $fx = edaoFixture('edao-payload');
    edaoCtx()->forget();

    $this->actingAs($fx['staff'])->get('/ed/board')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ED/Board')
            ->has('visits', 2)
            // The server order is still ARRIVAL (a fact) — unchanged by this part.
            ->where('visits.0.patient', 'Bea Blue')
            ->where('visits.0.acuity.level', 'blue')
            ->where('visits.0.acuity.position', 5)   // least urgent
            ->where('visits.1.patient', 'Rita Red')
            ->where('visits.1.acuity.level', 'red')
            ->where('visits.1.acuity.position', 1)   // most urgent
            ->etc());
});

test('an untriaged visit carries no acuity and therefore no position', function () {
    $fx = edaoFixture('edao-untriaged');
    $p = app(PatientService::class)->create(['first_name' => 'Uma', 'last_name' => 'Untriaged', 'date_of_birth' => '1988-08-08', 'sex' => 'female']);
    app(EdVisitService::class)->register($fx['staff'], $p, $fx['branch'], EdVisit::ARRIVAL_WALK_IN, 'Cough', now()->subMinutes(1));
    edaoCtx()->forget();

    $this->actingAs($fx['staff'])->get('/ed/board')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ED/Board')
            ->has('visits', 3)
            ->where('visits.2.patient', 'Uma Untriaged')
            ->where('visits.2.acuity', null)
            ->etc());
});

// ------------------------------------------------- THE CLIENT SORT (structural) ----

test('P7-C3: the board sorts on the recorded POSITION, and the string compare is gone', function () {
    // A payload assertion cannot see how the client orders the tiles (the QA-FIX.5a lesson), so the client
    // half is structural here and driven in a real browser in the gate report.
    // Mutation-checked: restoring the localeCompare line reddens this.
    $vue = (string) file_get_contents(resource_path('js/pages/ED/Board.vue'));

    // THE DEFECT IS GONE: no lexicographic compare of an acuity level anywhere in the file.
    expect($vue)->not->toContain("(a.acuity?.level ?? '~')")
        ->and($vue)->not->toContain('acuity?.level ?? ')
        // …and the replacement orders by the server-sent position, grouped by scale.
        ->and($vue)->toContain('v.acuity.position')
        ->and($vue)->toContain('ka[2] - kb[2]');

    // The sentinel default is gone too — it sorted untriaged FIRST, the opposite of what its comment
    // claimed. The needle is the CODE (`?? '~'`), not the character: the replacement's comment explains
    // the old bug and naturally quotes it.
    expect($vue)->not->toContain("?? '~'");
});

// ------------------------------------------------------- FENCES + CONTROLS ----

test('D-170: NO cross-scale equivalence table exists — ESI is never mapped onto Manchester', function () {
    // Positions are comparable only within a scale. A table saying ESI 2 ranks with Manchester orange is a
    // clinical claim the product has no basis to make; its ABSENCE is the fence and is pinned here so a
    // later "make the mixed board sort nicely" pass cannot quietly add one.
    // COMMENTS ARE STRIPPED BEFORE THE SCAN (the NoteEditorParityTest / GovernanceWindowTest pattern).
    // The prose that FORBIDS a cross-scale mapping necessarily contains the words for it — this exact
    // scan first reddened on the docblock explaining why no equivalence table exists. A fence that its
    // own rationale trips is a fence that gets deleted, so it reads code.
    $strip = function (string $source): string {
        $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;   // block + docblocks
        $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;  // vue template comments

        return preg_replace('~(?<![:\'"])//[^\n]*~', ' ', $source) ?? $source; // line comments, not urls
    };

    $files = collect(File::allFiles(base_path('Modules/ED/src')))
        ->filter(fn ($f): bool => $f->getExtension() === 'php')
        ->map(fn ($f): string => (string) File::get($f->getPathname()))
        ->push((string) file_get_contents(resource_path('js/pages/ED/Board.vue')))
        ->map($strip);

    foreach (['equivalen', 'crossScale', 'cross_scale', 'scaleMap', 'normaliseAcuity', 'normalizeAcuity', 'universalAcuity'] as $needle) {
        foreach ($files as $source) {
            expect(str_contains($source, $needle))->toBeFalse("ED must not map one acuity scale onto another ({$needle})");
        }
    }

    // THE STRIP MUST NOT HAVE EATEN THE CODE (D-174) — otherwise the scan above passes on an empty string.
    expect($files->last())->toContain('acuity.position')
        ->and($files->implode("\n"))->toContain('levelPosition');
});

test('POSITIVE CONTROL — position is not a score: the fence columns and the null seam are untouched', function () {
    $fx = edaoFixture('edao-fence');

    // The recorded level is still exactly what the nurse assigned, and `position` says nothing about the
    // patient — only where that value sits in the list its own scale prints.
    $triage = EdTriage::query()->where('ed_visit_id', $fx['red']->id)->firstOrFail();
    expect($triage->acuity_level)->toBe('red')
        ->and(EdTriage::levelPosition($triage->acuity_scale, $triage->acuity_level))->toBe(1);

    // No computed-judgment column appeared, and no homemade acuity computation appeared with it.
    foreach (['suggested', 'computed', 'score', 'severity', 'priority', 'deterioration', 'risk', 'grade', 'rank'] as $word) {
        expect(Schema::getColumnListing('ed_triages'))->not->toContain($word);
    }
    $files = collect(File::allFiles(base_path('Modules/ED/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['computeAcuity', 'calculateAcuity', 'scoreAcuity', 'assignAcuity', 'triageScore', 'deriveAcuity'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("ED must not compute a triage acuity ({$needle})");
        }
    }
});

test('POSITIVE CONTROL — the board still defaults to ARRIVAL order, so acuity sorting stays opt-in', function () {
    // The board must not start life ranking patients by acuity: ordering is something staff ASK for. If
    // this failed, the fix would have turned a recorded fact into the board's default judgment.
    $vue = (string) file_get_contents(resource_path('js/pages/ED/Board.vue'));

    expect($vue)->toContain("sortBy = ref<'arrival' | 'acuity'>('arrival')")
        ->and($vue)->toContain("if (sortBy.value === 'acuity')");
});
