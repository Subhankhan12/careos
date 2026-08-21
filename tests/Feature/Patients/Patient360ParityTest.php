<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Clinical\Models\Allergy;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Models\PatientCoverage;
use Modules\Patients\Models\PatientIdentifier;
use Modules\Patients\Services\ConsentService;
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
 * PC.P3 — Patient 360 visual parity, carried by the SHARED clinical header.
 *
 * Four properties are under test:
 *
 *  1. ONE COMPONENT, TWO SURFACES. S1 was EXTENDED, not forked: `status`, `links`, `variant`
 *     and `initials` are all OPTIONAL, `compact` is the default, and the hero's additions are
 *     absolutely-positioned decoration — so the dental callers' markup is untouched. (Verified
 *     in a real browser before and after: the dental band's root class string, its `text-2xl`
 *     name, and the absence of avatar and watermark are all unchanged.)
 *
 *  2. THE TAB COUNTS ARE SERVER-COMPUTED. They count real rows rather than measuring a Vue
 *     array's length — the defect PC.P2 found on the chart, where a capped list silently
 *     under-reported the record.
 *
 *  3. THE FLAG CHIP IS ABSENT, AND HONESTLY SO. The wireframe draws a "⚑ Flag" chip beside the
 *     name. `patients` HAS NO FLAG COLUMN and nothing in CareOS records one, so the chip that
 *     shipped was a hardcoded span rendered for EVERY patient — it asserted a documented fact
 *     that does not exist. It is removed rather than backfilled: a flag must be a
 *     CLINICIAN-RECORDED fact before it can be shown, and deriving one from the record would
 *     make it a computed risk marker (the fence). The gap is recorded in the diff doc.
 *
 *  4. THE FENCE HOLDS ON THE FILES THAT MOVED (D-173): the extended header, the 360 page and
 *     the app-layer controller carry no risk score, acuity, priority or severity-keyed styling.
 *
 * Every absence assertion below scans a NON-EMPTY, REPRESENTATIVE payload and proves so first
 * (D-174) — including the tempting data: a SEVERE allergy, an inactive one, and a full set of
 * countable rows.
 */

function p360Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function p360User(Tenant $tenant, string $role): User
{
    p360Ctx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * A REPRESENTATIVE patient: every tab has rows, and the header has a severe recorded allergy
 * to display. A fence that scans an empty page proves nothing (D-174).
 *
 * @return array{tenant: Tenant, clinician: User, patient: Patient}
 */
function p360Fixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    p360Ctx()->set($tenant);

    $clinician = p360User($tenant, 'doctor');
    $patient = app(PatientService::class)->create([
        'first_name' => 'Erika',
        'last_name' => 'Baumgartner',
        'date_of_birth' => '1954-03-12',
        'sex' => 'female',
    ]);

    foreach ([['email', 'erika@example.test', true], ['phone', '+41 44 000 00 00', false], ['address', 'Bahnhofstrasse 1', false]] as [$type, $value, $primary]) {
        PatientContact::query()->create([
            'patient_id' => $patient->id,
            'type' => $type,
            'value' => $value,
            'is_primary' => $primary,
        ]);
    }

    PatientCoverage::query()->create([
        'patient_id' => $patient->id,
        'payer_name' => 'Helvetia Care',
        'member_id' => 'HC-4471',
        'plan' => 'Standard',
        'coverage_type' => 'insurance',
        'priority' => 1,
    ]);

    PatientIdentifier::query()->create([
        'patient_id' => $patient->id,
        'system' => 'ahv',
        'value' => '756.1234.5678.97',
    ]);

    $branch = Branch::query()->firstOrCreate(['code' => 'MAIN'], ['name' => 'Main', 'timezone' => 'Europe/Zurich']);
    StaffProfile::query()->firstOrCreate(
        ['user_id' => $clinician->id],
        ['first_name' => 'Paula', 'last_name' => 'Practitioner', 'display_name' => 'Paula Practitioner', 'profession' => 'doctor', 'primary_branch_id' => $branch->id],
    );

    foreach (['portal', 'research'] as $key) {
        ConsentTemplate::query()->create([
            'key' => $key,
            'title' => ucfirst($key).' consent',
            'body' => 'Consent body for '.$key,
            'version' => 1,
            'scope_keys' => [$key.'.access'],
            'is_active' => true,
        ]);
        app(ConsentService::class)->grant($patient, $key, 'Erika Baumgartner', $clinician);
    }

    return compact('tenant', 'clinician', 'patient');
}

function p360Allergy(Patient $patient, User $actor, string $substance, string $severity, string $status = Allergy::STATUS_ACTIVE): Allergy
{
    return Allergy::query()->create([
        'patient_id' => $patient->id,
        'substance' => $substance,
        'substance_key' => strtolower($substance),
        'reaction' => $substance.' reaction as documented.',
        'severity' => $severity,
        'status' => $status,
        'recorded_by' => StaffProfile::query()->where('user_id', $actor->id)->firstOrFail()->id,
        'recorded_at' => now(),
    ]);
}

/** Strip comments so the scans test AFFORDANCES, not the prose documenting their absence. */
function p360Strip(string $source): string
{
    $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;
    $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;

    return strtolower(preg_replace('~(^|\s)//[^\n]*~m', '$1 ', $source) ?? $source);
}

test('S1 was EXTENDED for the hero, not forked — the 360 props are optional and compact stays the default', function () {
    $path = base_path('resources/js/Components/Clinical/PatientClinicalHeader.vue');
    expect(file_exists($path))->toBeTrue('the shared header is missing — this whole suite would scan nothing');

    $source = (string) file_get_contents($path);
    $code = p360Strip($source);

    // There is exactly ONE header component. A `Patients/` or `Dental/` copy would drift.
    foreach (['resources/js/Components/Patients/PatientClinicalHeader.vue', 'resources/js/Components/Dental/PatientClinicalHeader.vue'] as $fork) {
        expect(file_exists(base_path($fork)))->toBeFalse("the header was forked into {$fork}");
    }

    // Every 360 prop is OPTIONAL — that is what leaves the dental callers untouched.
    foreach (['status?:', 'links?:', 'variant?:', 'initials?:'] as $optional) {
        expect($code)->toContain(strtolower($optional));
    }

    // `compact` is the default: the hero renders only when a caller ASKS for it.
    expect(substr_count($code, "variant === 'hero'"))->toBeGreaterThan(0)
        ->and($code)->not->toContain("variant === 'compact'");

    // The hero's additions are ADDITIVE decoration — the root's static class string is the one
    // the compact band always had, with hero classes applied through a binding instead.
    expect($source)->toContain('class="euca-tile-dark rounded-2xl p-5 text-white"');

    // Still purely presentational (P0D.GU): it displays, it does not derive.
    foreach (['new date', 'computed(', 'math.', '.tofixed', 'parseint('] as $derivation) {
        expect(str_contains($code, $derivation))->toBeFalse("the shared header now derives something: '{$derivation}'");
    }
});

test('the dental callers pass no hero prop, so their band renders exactly as before', function () {
    $callers = ['Odontogram', 'PerioChart', 'TreatmentPlans', 'Imaging'];

    foreach ($callers as $page) {
        $path = base_path("resources/js/pages/Dental/{$page}.vue");
        expect(file_exists($path))->toBeTrue("{$page}.vue is missing — this check would scan nothing");

        $source = (string) file_get_contents($path);
        // POSITIVE CONTROL: the file really does render the shared header (D-174) — otherwise
        // "passes no hero prop" would be true of a page that renders no header at all.
        expect($source)->toContain('<PatientClinicalHeader');

        foreach (['variant=', ':variant=', 'initials=', ':initials=', ':status='] as $heroProp) {
            expect(str_contains($source, $heroProp))
                ->toBeFalse("{$page}.vue now passes '{$heroProp}' — the dental band would stop being the compact one");
        }
    }
});

test('Patient 360 renders the hero through the SHARED header, with the recorded status and the dental link', function () {
    $source = (string) file_get_contents(base_path('resources/js/pages/Patients/Show.vue'));

    expect($source)->toContain('@/Components/Clinical/PatientClinicalHeader.vue')
        ->and($source)->toContain('variant="hero"')
        ->and($source)->toContain(':status="patient.status"')
        ->and($source)->toContain(':allergies="allergies"')
        ->and($source)->toContain(':links="headerLinks"');

    // The page no longer hand-rolls its own dark band — that was the divergence this gate closed.
    $code = p360Strip($source);
    expect(substr_count($code, 'euca-tile-dark'))->toBe(0, 'Patient 360 still hand-rolls a header band beside the shared one');

    // The dental link stays permission-gated by the CALLER, exactly as the old markup was.
    expect($code)->toContain('candental');
});

test('the tab counts are SERVER-computed from real rows, not measured from a Vue array', function () {
    $fx = p360Fixture();
    p360Allergy($fx['patient'], $fx['clinician'], 'Penicillin', Allergy::SEVERITY_SEVERE);
    p360Allergy($fx['patient'], $fx['clinician'], 'Latex', Allergy::SEVERITY_MILD, Allergy::STATUS_INACTIVE);

    p360Ctx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('patients.show', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Show')
            // POSITIVE CONTROL: representative, non-empty (D-174). Every figure below is a real
            // row this test created, so a count that stopped counting would fail loudly.
            ->where('counts.contacts', 3)
            ->where('counts.coverages', 1)
            ->where('counts.consents', 2)
            ->where('counts.identifiers', 1)
            // ACTIVE allergies only — the inactive row is not a current fact.
            ->where('counts.allergies', 1)
            ->where('counts.accessLog', fn ($count) => $count >= 1)
        );

    // The page reads the server figures; nothing on screen measures `.length` of a payload list.
    $code = p360Strip((string) file_get_contents(base_path('resources/js/pages/Patients/Show.vue')));
    expect($code)->toContain('props.counts.contacts')
        ->and($code)->toContain('props.counts.accesslog');
    foreach (['props.patient.contacts.length', 'props.patient.coverages.length', 'props.patient.consents.length', 'props.accesslog.length'] as $measured) {
        expect(str_contains($code, $measured))->toBeFalse("a tab count is still measured page-side: '{$measured}'");
    }
});

test('THE FLAG CHIP IS ABSENT: nothing records a patient flag, so nothing displays one', function () {
    // The model has no flag to display — this is the fact the whole omission rests on.
    $fillable = (new Patient)->getFillable();
    expect($fillable)->not->toContain('flag')
        ->and($fillable)->not->toContain('flags')
        // POSITIVE CONTROL: the fillable list really was read (D-174).
        ->and($fillable)->toContain('mrn');

    expect(Schema::hasColumn('patients', 'flag'))->toBeFalse()
        ->and(Schema::hasColumn('patients', 'flags'))->toBeFalse()
        ->and(Schema::hasColumn('patients', 'mrn'))->toBeTrue();

    // So no surface may draw one. The old hardcoded span and its string are both gone.
    $show = (string) file_get_contents(base_path('resources/js/pages/Patients/Show.vue'));
    $header = (string) file_get_contents(base_path('resources/js/Components/Clinical/PatientClinicalHeader.vue'));
    expect(strlen($show))->toBeGreaterThan(500)
        ->and(strlen($header))->toBeGreaterThan(500);

    foreach (['show' => $show, 'header' => $header] as $name => $source) {
        expect(str_contains($source, '⚑'))->toBeFalse("{$name} still draws a flag glyph");
        $code = p360Strip($source);
        expect(str_contains($code, 'headerflag'))->toBeFalse("{$name} still renders the flag chip");
        // Nor may the header GROW one: a `flag` prop would invite a caller to compute it.
        expect(preg_match('~\bflag(s|ged)?\b~', $code))->toBe(0, "{$name} reintroduced a flag affordance");
    }

    // The orphaned string is deleted too — a live key is an invitation to render it again.
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    expect($en['patients']['show'])->toBeArray()
        ->and($en['patients']['show'])->not->toHaveKey('headerFlag')
        // POSITIVE CONTROL: the right block was read.
        ->and($en['patients']['show'])->toHaveKey('allergiesNone');
});

test('the hero displays RECORDED allergies as facts, with styling that ranks nothing', function () {
    $fx = p360Fixture();
    p360Allergy($fx['patient'], $fx['clinician'], 'Penicillin', Allergy::SEVERITY_SEVERE);
    p360Allergy($fx['patient'], $fx['clinician'], 'Ibuprofen', Allergy::SEVERITY_MODERATE);

    p360Ctx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('patients.show', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('allergies', 2)
            // Ordered by SUBSTANCE, never by severity: the list asserts no priority even though
            // one of these rows is SEVERE — the tempting case is present on purpose (D-174).
            ->where('allergies.0.substance', 'Ibuprofen')
            ->where('allergies.1.substance', 'Penicillin')
            ->where('allergies.1.severity', 'severe')
        );

    /*
     * D-169 — the rule lives in the STYLING. The recorded severity is DISPLAYED as text, and no
     * class or style binding anywhere in the header is keyed to it or to any threshold.
     */
    $header = p360Strip((string) file_get_contents(base_path('resources/js/Components/Clinical/PatientClinicalHeader.vue')));
    expect($header)->toContain('{{ allergy.severity }}');

    preg_match_all('~:(?:class|style)="([^"]*)"~', $header, $bindings);
    // POSITIVE CONTROL: there ARE bindings to inspect (the hero adds one) — an empty match set
    // would make this loop vacuous.
    expect($bindings[1])->not->toBeEmpty('no class bindings were found to inspect');
    foreach ($bindings[1] as $binding) {
        foreach (['severity', 'risk', 'score', 'tint', 'band', 'allergy'] as $needle) {
            expect(str_contains($binding, $needle))->toBeFalse("the header styles from a clinical value: {$binding}");
        }
        expect(preg_match('~[<>]=?\s*\d~', $binding))->toBe(0, "the header styles by a threshold: {$binding}");
    }

    // The chip's own classes are a CONSTANT string — the same chip for mild and for severe.
    expect($header)->toContain('rounded-full bg-white/15 px-3 py-1 text-xs text-white');
});

test('THE FENCE holds on every file this gate moved (D-173)', function () {
    $files = [
        base_path('resources/js/Components/Clinical/PatientClinicalHeader.vue'),
        base_path('resources/js/pages/Patients/Show.vue'),
        base_path('app/Http/Controllers/PatientShowController.php'),
    ];

    $forbidden = [
        'riskscore', 'acuity', 'triage', 'ews', 'deterioration', 'readmission', 'fallrisk',
        'prognosis', 'crossreact', 'contraindication', 'severityband', 'severityscore',
        'severityrank', 'severitytone', 'severitycolour', 'severitycolor', 'trendarrow',
        'autoproblem', 'highrisk', 'alertlevel', 'prioritypatient',
    ];

    foreach ($files as $path) {
        expect(file_exists($path))->toBeTrue(basename($path).' is missing — this fence would scan nothing');

        $stripped = p360Strip((string) file_get_contents($path));
        // POSITIVE CONTROL: the file has real content after comment-stripping (D-174).
        expect(strlen(trim($stripped)))->toBeGreaterThan(400, basename($path).' stripped to almost nothing');

        // Compound-phrase pass: `severityTone` survives a word-boundary scan, so squash first.
        $squashed = preg_replace('~[^a-z0-9]~', '', $stripped) ?? '';
        foreach ($forbidden as $token) {
            expect(str_contains($squashed, $token))
                ->toBeFalse("fence token '{$token}' appears in ".basename($path));
        }
    }
});
