<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Clinical\Models\Allergy;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\NoteTemplate;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\Clinical\Services\EncounterService;
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
 * PC.P4 — Note Editor visual parity.
 *
 * The backend properties (signed immutability at model + trigger level, the amend chain, required
 * sections, permissions, audit, tenant isolation) are ALREADY covered by `ClinicalNoteTest`, which
 * this gate does not touch. What is asserted here is the PARITY SURFACE and, above all, the fence:
 *
 *  1. A SIGNED NOTE IS NOT EDITABLE IN PLACE — asserted through the ROUTE a clinician would
 *     actually use, not only the model. The amend path creates a SUPERSEDING version and v1
 *     remains reachable and byte-for-byte unchanged.
 *
 *  2. SIGNING GOES THROUGH THE EXISTING PERMISSION and records signed_by/signed_at; an actor
 *     without `note.sign` is refused.
 *
 *  3. NO ASSIST/REPHRASE/AUTHORING TOOL EXISTS, AND NONE WAS CREATED (D-170). Two independent
 *     reasons this panel was omitted rather than built: no such capability exists among the ten
 *     AiCore tools, AND the wireframe itself draws no assist panel at all. Inventing one would
 *     have put a content-producing affordance beside a legal clinical record that neither the
 *     mock nor the backend asks for.
 *
 *  4. NOTHING IS AUTO-INSERTED AND NOTHING IS AUTO-SIGNED. The only insertion on the page is the
 *     clinician's own dot-phrase, behind an explicit click; the only sign call sits behind the
 *     type-to-confirm modal.
 *
 * Every absence assertion scans a NON-EMPTY, REPRESENTATIVE payload and proves so first (D-174):
 * the fixture carries a draft, a SIGNED note and an AMENDED chain, plus a recorded SEVERE allergy
 * — the data that would tempt an auto-sign, a silent edit or a computed judgment.
 */

function nepCtx(): TenantContext
{
    return app(TenantContext::class);
}

function nepUser(Tenant $tenant, string $role = 'doctor'): User
{
    nepCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    if ($role !== '') {
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
        ]);
    }

    return $user;
}

/**
 * A REPRESENTATIVE fixture: a draft, a SIGNED note, its AMENDMENT (a real chain), a template with
 * required sections, and a recorded SEVERE allergy.
 *
 * @return array{tenant: Tenant, clinician: User, patient: Patient, encounter: Encounter, staff: StaffProfile, template: NoteTemplate, draft: ClinicalNote, signed: ClinicalNote, amendment: ClinicalNote}
 */
function nepFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    nepCtx()->set($tenant);

    $clinician = nepUser($tenant, 'doctor');
    $branch = Branch::query()->create(['name' => strtoupper($slug).' Branch', 'code' => strtoupper(substr($slug, 0, 4))]);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Nora', 'last_name' => 'Keller', 'date_of_birth' => '1988-03-14', 'sex' => 'female',
    ]);
    $staff = StaffProfile::query()->create([
        'first_name' => 'Marc', 'last_name' => 'Brunner', 'display_name' => 'Dr. M. Brunner',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id,
    ]);
    $encounter = app(EncounterService::class)->open(
        $patient, $staff, $branch, null, Encounter::TYPE_CONSULTATION, $clinician,
    );

    $template = NoteTemplate::query()->create([
        'name' => 'SOAP standard',
        'required_sections' => ['subjective', 'objective', 'assessment'],
        'active' => true,
    ]);

    $notes = app(ClinicalNoteService::class);

    // A draft with ONE required section deliberately empty — the "2 of 3 filled" case.
    $draft = $notes->saveDraft($encounter, $staff, [
        'subjective' => 'Patient reports improved sleep since the last visit.',
        'objective' => 'BP 128/82 mmHg, HR 74 bpm as documented.',
        'assessment' => '',
        'plan' => 'Continue current therapy. Review in two weeks.',
    ], $clinician, null, $template);

    // A second note, SIGNED, then AMENDED — a real superseding chain.
    $second = $notes->saveDraft($encounter, $staff, [
        'subjective' => 'Follow-up after two weeks. Headaches now rare.',
        'objective' => 'BP 124/78 mmHg as documented.',
        'assessment' => 'Improving on current therapy, per the clinician.',
        'plan' => 'Continue current dose.',
    ], $clinician, null, $template);
    $signed = $notes->sign($second, $clinician);

    $amendment = $notes->amend(
        $signed,
        ['plan' => 'Continue current dose. Corrected: frequency was mis-transcribed.'],
        'Transcription error in the plan section.',
        $staff,
        $clinician,
    );

    // The tempting data: a SEVERE recorded allergy on the same patient.
    Allergy::query()->create([
        'patient_id' => $patient->id,
        'substance' => 'Penicillin',
        'substance_key' => 'penicillin',
        'reaction' => 'Anaphylaxis requiring adrenaline.',
        'severity' => Allergy::SEVERITY_SEVERE,
        'status' => Allergy::STATUS_ACTIVE,
        'recorded_by' => $staff->id,
        'recorded_at' => now(),
    ]);
    Allergy::query()->create([
        'patient_id' => $patient->id,
        'substance' => 'Amoxicillin',
        'substance_key' => 'amoxicillin',
        'reaction' => 'Rash.',
        'severity' => Allergy::SEVERITY_MILD,
        'status' => Allergy::STATUS_INACTIVE,
        'recorded_by' => $staff->id,
        'recorded_at' => now(),
    ]);

    return compact('tenant', 'clinician', 'patient', 'encounter', 'staff', 'template', 'draft', 'signed', 'amendment');
}

/** Strip comments so the scans test AFFORDANCES, not the prose documenting their absence. */
function nepStrip(string $source): string
{
    $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;
    $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;

    return strtolower(preg_replace('~(^|\s)//[^\n]*~m', '$1 ', $source) ?? $source);
}

test('a SIGNED note cannot be edited in place through the route a clinician would use', function () {
    $fx = nepFixture();
    $signed = $fx['signed'];

    // POSITIVE CONTROL: the note really is signed and really has content to protect (D-174).
    expect($signed->status)->toBe(ClinicalNote::STATUS_SIGNED)
        ->and($signed->signed_at)->not->toBeNull()
        ->and(trim((string) $signed->plan))->not->toBe('');

    /** Snapshot as SCALARS — comparing Carbon instances compares object identity, not the time. */
    $snapshot = fn (ClinicalNote $n): array => [
        'subjective' => $n->subjective,
        'objective' => $n->objective,
        'assessment' => $n->assessment,
        'plan' => $n->plan,
        'status' => $n->status,
        'signed_at' => $n->signed_at?->toDateTimeString(),
        'signed_by' => $n->signed_by,
        'version' => $n->version,
    ];
    $before = $snapshot($signed);

    nepCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->patch(route('clinical.notes.update', $signed->id), [
            'subjective' => 'REWRITTEN AFTER SIGNING',
            'objective' => 'REWRITTEN',
            'assessment' => 'REWRITTEN',
            'plan' => 'REWRITTEN',
        ])
        ->assertSessionHasErrors('note');

    nepCtx()->set($fx['tenant']);
    $after = ClinicalNote::query()->whereKey($signed->id)->firstOrFail();
    expect($snapshot($after))->toBe($before);

    // The payload itself tells the page the note is read-only.
    nepCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('clinical.notes.edit', $signed->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('note.is_read_only', true));
});

test('the amend path creates a SUPERSEDING version and v1 stays reachable and unchanged', function () {
    $fx = nepFixture();
    $signed = $fx['signed'];
    $amendment = $fx['amendment'];

    expect($amendment->id)->not->toBe($signed->id)
        ->and($amendment->version)->toBe($signed->version + 1)
        ->and($amendment->supersedes_id)->toBe($signed->id)
        ->and($amendment->status)->toBe(ClinicalNote::STATUS_DRAFT)
        ->and($amendment->amendment_reason)->toBe('Transcription error in the plan section.');

    // The original is untouched by its own amendment.
    nepCtx()->set($fx['tenant']);
    $original = ClinicalNote::query()->whereKey($signed->id)->firstOrFail();
    expect($original->status)->toBe(ClinicalNote::STATUS_SIGNED)
        ->and($original->plan)->toBe('Continue current dose.')
        ->and($original->plan)->not->toBe($amendment->plan);

    // v1 is still REACHABLE — the version rail links to it and the route serves it.
    nepCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('clinical.notes.edit', $signed->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('versions', 2)
            ->where('versions.0.version', 1)
            ->where('versions.1.version', 2)
            // Viewing v1: the page says a newer version exists and points at it.
            ->where('chain.is_superseded', true)
            ->where('chain.current.version', 2)
        );

    // Viewing the CURRENT version: not superseded.
    nepCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('clinical.notes.edit', $amendment->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('chain.is_superseded', false));
});

test('signing goes through the EXISTING permission and records signed_by and signed_at', function () {
    $fx = nepFixture();
    $notes = app(ClinicalNoteService::class);

    nepCtx()->set($fx['tenant']);
    $fresh = $notes->saveDraft($fx['encounter'], $fx['staff'], [
        'subjective' => 'S', 'objective' => 'O', 'assessment' => 'A', 'plan' => 'P',
    ], $fx['clinician'], null, $fx['template']);
    expect($fresh->status)->toBe(ClinicalNote::STATUS_DRAFT);

    // An actor WITHOUT note.sign is refused by the route.
    $reception = nepUser($fx['tenant'], 'reception');
    nepCtx()->forget();
    $this->actingAs($reception)->post(route('clinical.notes.sign', $fresh->id))->assertForbidden();

    nepCtx()->set($fx['tenant']);
    expect(ClinicalNote::query()->whereKey($fresh->id)->firstOrFail()->status)->toBe(ClinicalNote::STATUS_DRAFT);

    // The permitted clinician signs, and the record carries WHO and WHEN.
    nepCtx()->forget();
    $this->actingAs($fx['clinician'])->post(route('clinical.notes.sign', $fresh->id))->assertRedirect();

    nepCtx()->set($fx['tenant']);
    $after = ClinicalNote::query()->whereKey($fresh->id)->firstOrFail();
    expect($after->status)->toBe(ClinicalNote::STATUS_SIGNED)
        ->and($after->signed_by)->toBe($fx['clinician']->id)
        ->and($after->signed_at)->not->toBeNull();
});

test('D-170: NO rephrase or note-authoring agent tool exists, and this gate created none', function () {
    $tools = glob(base_path('app/AiCore/Tools/*.php')) ?: [];

    // POSITIVE CONTROL: the tool directory really resolved and holds the real fleet (D-174).
    expect($tools)->not->toBeEmpty('the AiCore tool scan resolved to no files');
    expect(count($tools))->toBeGreaterThanOrEqual(10);
    expect(array_map('basename', $tools))->toContain('ClinicalSummaryTool.php');

    /*
     * Not one of the ten tools authors, rephrases or completes note prose. The clinical one is
     * EXTRACTIVE at a SUGGEST ceiling and lives on the CHART, where PC.P2 wired it — it was
     * deliberately not duplicated onto the authoring surface.
     */
    foreach ($tools as $path) {
        $code = nepStrip((string) file_get_contents($path));
        foreach (['rephrase', 'reword', 'rewrite', 'paraphrase', 'polish', 'compose', 'ghostwrite', 'autocomplete'] as $authoring) {
            expect(str_contains($code, $authoring))
                ->toBeFalse("an authoring capability '{$authoring}' appeared in ".basename($path));
        }
        // No tool may target the note-editing surface at all.
        foreach (['note_editor', 'noteeditor', 'clinicalnoteservice', 'savedraft'] as $noteWrite) {
            expect(str_contains($code, $noteWrite))
                ->toBeFalse(basename($path).' reaches into the note-authoring path: '.$noteWrite);
        }
    }

    // The extractive summary tool's ceiling is untouched by this gate.
    $summary = (string) file_get_contents(base_path('app/AiCore/Tools/ClinicalSummaryTool.php'));
    expect($summary)->toContain('autonomyCeiling: AutonomyPolicy::SUGGEST')
        ->and($summary)->toContain('EXTRACTIVE');
});

test('the editor offers NO assist affordance: nothing is auto-inserted and nothing is auto-signed', function () {
    $page = (string) file_get_contents(base_path('resources/js/pages/Clinical/NoteEditor.vue'));
    $editor = (string) file_get_contents(base_path('resources/js/Components/SoapEditor.vue'));

    // POSITIVE CONTROL: both files are real and substantial (D-174).
    expect(strlen($page))->toBeGreaterThan(2000)
        ->and(strlen($editor))->toBeGreaterThan(500);

    $pageCode = nepStrip($page);
    $editorCode = nepStrip($editor);

    // No agent surface of any kind on the authoring page.
    foreach (['ai_', 'aicore', 'agent', 'assist', 'rephrase', 'reword', 'suggest(', 'summarize', 'autonomy', 'approvalqueue'] as $affordance) {
        expect(str_contains($pageCode, $affordance))->toBeFalse("the note editor grew an agent affordance: '{$affordance}'");
        expect(str_contains($editorCode, $affordance))->toBeFalse("the SOAP editor grew an agent affordance: '{$affordance}'");
    }

    // The SOAP editor writes nothing on its own: it emits what was typed and nothing else.
    foreach (['router.', 'axios', 'fetch(', 'usefetch', 'settimeout', 'setinterval', 'watch('] as $side) {
        expect(str_contains($editorCode, $side))->toBeFalse("the SOAP editor performs a side effect: '{$side}'");
    }

    /*
     * NOTHING IS AUTO-SIGNED. The sign endpoint is referenced exactly twice in the page: the
     * `confirmSign` definition that posts it, and nowhere else — and `confirmSign` is reachable
     * only from the modal's button, behind the typed confirmation.
     */
    expect(substr_count($pageCode, 'router.post(props.actions.sign_url'))
        ->toBe(1, 'the sign endpoint is posted from more than one place');
    /*
     * `confirmSign` appears EXACTLY twice — its definition and the modal button's @click. Two
     * occurrences that are both accounted for means nothing else can reach it: no watcher, no
     * timer, no lifecycle hook. (Counting is airtight where a scoped regex is not: a lazy match
     * from `watch(` runs on into the template and reports a handler that is only a click.)
     */
    expect(substr_count($pageCode, 'confirmsign'))->toBe(2, 'confirmSign is reachable from somewhere other than its one button');
    expect($pageCode)->toContain('function confirmsign()');
    expect($pageCode)->toContain('@click="confirmsign"');
    // ...and that button only enables once the clinician has typed the confirmation phrase.
    expect($pageCode)->toContain('signready');

    /*
     * NOTHING IS AUTO-INSERTED. The only insertion into a SOAP section is the clinician's own
     * dot-phrase, and it happens on an explicit click.
     */
    expect(substr_count($pageCode, 'insertsnippet'))->toBe(2, 'insertSnippet is reachable from somewhere other than its one button');
    expect($pageCode)->toContain('function insertsnippet()');
    expect($pageCode)->toContain('@click="insertsnippet"');

    /*
     * The ONLY thing the autosave watcher may reach is the DRAFT save — and there is only ONE
     * watcher. This is the assertion that matters: counting `insertSnippet` call sites does NOT
     * catch a NEW watcher that writes into a section directly, which is exactly how auto-authored
     * text would arrive. So the write surface itself is pinned: one watcher, and exactly one
     * assignment into a SOAP section anywhere in the page (the clinician's own snippet insert).
     */
    expect(substr_count($pageCode, 'autosavetimer = window.settimeout(savedraft'))->toBe(1);
    expect(substr_count($pageCode, 'window.settimeout('))->toBe(1, 'a second timer was added to the editor');
    expect(substr_count($pageCode, 'watch('))->toBe(1, 'a second watcher was added to the note editor');

    preg_match_all('~sections\s*(\.\w+|\[[^\]]+\])\s*=[^=]~', $pageCode, $writes);
    expect($writes[0])->toHaveCount(1, 'something other than the snippet insert writes into a SOAP section: '.json_encode($writes[0]));
    expect($writes[0][0])->toContain('snippettarget');
    // The editor component hands its text back through the one v-model channel, nothing else.
    expect(substr_count($pageCode, 'object.assign(sections'))->toBe(1);
});

test('THE FENCE: the editor payload and its components carry no clinical judgment', function () {
    $fx = nepFixture();

    nepCtx()->forget();
    $response = $this->actingAs($fx['clinician'])
        ->get(route('clinical.notes.edit', $fx['draft']->id))
        ->assertOk();

    $props = $response->viewData('page')['props'];

    // POSITIVE CONTROL: a representative, NON-EMPTY payload — the note has real content, the
    // chain is real and the patient has a SEVERE recorded allergy on screen (D-174).
    expect($props['note']['subjective'])->not->toBe('')
        ->and($props['versions'])->not->toBeEmpty()
        ->and($props['allergies'])->toHaveCount(1)
        ->and($props['allergies'][0]['severity'])->toBe('severe');

    $forbidden = [
        'riskscore', 'acuity', 'triage', 'ews', 'deterioration', 'prognosis', 'crossreact',
        'contraindication', 'interactioncheck', 'severityband', 'severityscore', 'severitytone',
        'autoproblem', 'generatedassessment', 'suggesteddiagnosis', 'differential', 'icdsuggest',
        'autosign', 'autoinsert',
    ];

    $squashed = preg_replace('~[^a-z0-9]~', '', strtolower(json_encode($props) ?: '')) ?? '';
    expect(strlen($squashed))->toBeGreaterThan(400, 'the payload squashed to almost nothing');
    foreach ($forbidden as $token) {
        expect(str_contains($squashed, $token))->toBeFalse("fence token '{$token}' appears in the note-editor payload");
    }

    /*
     * D-173 — the scan follows every file this gate touched, including the two shared components.
     * D-169 — no class or style binding is keyed to a clinical value or a numeric threshold.
     */
    $files = [
        base_path('resources/js/pages/Clinical/NoteEditor.vue'),
        base_path('resources/js/Components/SoapEditor.vue'),
        base_path('resources/js/Components/VersionHistory.vue'),
        base_path('resources/js/Components/Clinical/SignOffBar.vue'),
        base_path('Modules/Clinical/src/Http/Controllers/NoteEditorController.php'),
    ];

    foreach ($files as $path) {
        expect(file_exists($path))->toBeTrue(basename($path).' is missing — this fence would scan nothing');
        $code = nepStrip((string) file_get_contents($path));
        expect(strlen(trim($code)))->toBeGreaterThan(300, basename($path).' stripped to almost nothing');

        $squashedFile = preg_replace('~[^a-z0-9]~', '', $code) ?? '';
        foreach ($forbidden as $token) {
            expect(str_contains($squashedFile, $token))->toBeFalse("fence token '{$token}' appears in ".basename($path));
        }

        preg_match_all('~:(?:class|style)="([^"]*)"~', $code, $bindings);
        foreach ($bindings[1] ?? [] as $binding) {
            foreach (['severity', 'risk', 'score', 'tint', 'band', 'allergy', 'vital'] as $needle) {
                expect(str_contains($binding, $needle))->toBeFalse(basename($path)." styles from a clinical value: {$binding}");
            }
            expect(preg_match('~[<>]=?\s*\d~', $binding))->toBe(0, basename($path)." styles by a threshold: {$binding}");
        }
    }
});

test('the recorded allergies land as facts: active only, ordered by substance, never ranked', function () {
    $fx = nepFixture();

    nepCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('clinical.notes.edit', $fx['draft']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // The INACTIVE Amoxicillin row is not a current fact and must not appear...
            ->has('allergies', 1)
            ->where('allergies.0.substance', 'Penicillin')
        );

    // ...and the payload carries the recorded fields only — nothing derived.
    nepCtx()->forget();
    $props = $this->actingAs($fx['clinician'])
        ->get(route('clinical.notes.edit', $fx['draft']->id))
        ->assertOk()
        ->viewData('page')['props'];
    expect(array_keys($props['allergies'][0]))->toBe(['id', 'substance', 'reaction', 'severity']);

    // The allergy read rides the page's EXISTING audit row — it adds no second read-audit path
    // (the PC.P1 property, asserted here at the place the temptation now exists).
    $controller = (string) file_get_contents(base_path('Modules/Clinical/src/Http/Controllers/NoteEditorController.php'));
    expect(substr_count($controller, 'auditRead('))->toBe(1);

    // The banner's chip styling is a CONSTANT string — severe and mild render identically.
    $page = (string) file_get_contents(base_path('resources/js/pages/Clinical/NoteEditor.vue'));
    expect($page)->toContain('border-warning/50');
    preg_match_all('~:class="([^"]*)"~', nepStrip($page), $bindings);
    foreach ($bindings[1] ?? [] as $binding) {
        expect(str_contains($binding, 'severity'))->toBeFalse("the allergy banner is tinted by severity: {$binding}");
    }
});
