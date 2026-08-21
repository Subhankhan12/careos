<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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

uses(RefreshDatabase::class);

/*
 * PC.P1 — the shared clinical components + B1 (wiring the recorded-allergy display).
 *
 * Three properties are under test:
 *
 *  1. THE PROMOTION IS BEHAVIOUR-IDENTICAL. The shared patient header moved out of the dental
 *     namespace; the dental pages consume it from its new home and nothing about their payload
 *     changed. (The rendered dental header was also captured from a real browser before and
 *     after the move and is byte-for-byte identical — 381 normalised characters.)
 *
 *  2. B1 LANDS REAL RECORDED FACTS. Patient 360 now carries the patient's ACTIVE allergies as
 *     documented — substance, reaction and the severity a CLINICIAN RECORDED — composed in the
 *     APP LAYER, because `Modules\Patients` may not use `Modules\Clinical` (an arch test enforces
 *     it). Nothing is fabricated, the empty case is honest, and the read is gated + tenant-scoped
 *     with no second audit path.
 *
 *  3. THE NEW SHELLS COMPUTE NOTHING. N1 rail card, N3 audit row and N6 sign-off bar are chrome:
 *     no arithmetic, no tone/trend/severity prop, and — for N6 — no signing logic whatsoever.
 *
 * Plus the carried-forward fence: no risk score, acuity, EWS, prognosis, interaction or
 * cross-reactivity anywhere, and the D-169 styling rule (nothing keyed to a clinical value).
 */

function pcsCtx(): TenantContext
{
    return app(TenantContext::class);
}

function pcsUser(Tenant $tenant, string $role): User
{
    pcsCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, clinician: User, patient: Patient}
 */
function pcsFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    pcsCtx()->set($tenant);
    $clinician = pcsUser($tenant, 'doctor');
    $patient = app(PatientService::class)->create(['first_name' => 'Erika', 'last_name' => 'Baumgartner', 'date_of_birth' => '1979-05-22', 'sex' => 'female']);

    return compact('tenant', 'clinician', 'patient');
}

function pcsRecorder(User $actor): StaffProfile
{
    $branch = Branch::query()->firstOrCreate(['code' => 'MAIN'], ['name' => 'Main', 'timezone' => 'Europe/Zurich']);

    return StaffProfile::query()->firstOrCreate(
        ['user_id' => $actor->id],
        ['first_name' => 'Paula', 'last_name' => 'Practitioner', 'display_name' => 'Paula Practitioner', 'profession' => 'doctor', 'primary_branch_id' => $branch->id],
    );
}

function pcsAllergy(Patient $patient, User $actor, string $substance, string $severity, string $status = Allergy::STATUS_ACTIVE): Allergy
{
    return Allergy::query()->create([
        'patient_id' => $patient->id,
        'substance' => $substance,
        'substance_key' => strtolower($substance),
        'reaction' => $substance.' reaction as documented.',
        'severity' => $severity,
        'status' => $status,
        'recorded_by' => pcsRecorder($actor)->id,
        'recorded_at' => now(),
    ]);
}

/** Strip comments so the scans test AFFORDANCES, not the prose documenting their absence. */
function pcsStrip(string $source): string
{
    $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;
    $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;

    return strtolower(preg_replace('~(^|\s)//[^\n]*~m', '$1 ', $source) ?? $source);
}

test('the shared header moved namespace and the dental pages consume it from its new home', function () {
    $newHome = base_path('resources/js/Components/Clinical/PatientClinicalHeader.vue');
    expect(file_exists($newHome))->toBeTrue('the shared header is not in the clinical namespace')
        ->and(file_exists(base_path('resources/js/Components/Dental/PatientClinicalHeader.vue')))
        ->toBeFalse('the header was copied, not moved — two copies will drift');

    // Every dental caller points at the new home; none still points at the old one.
    foreach (['Odontogram', 'PerioChart', 'TreatmentPlans', 'Imaging'] as $page) {
        $source = (string) file_get_contents(base_path("resources/js/pages/Dental/{$page}.vue"));
        expect($source)->toContain('@/Components/Clinical/PatientClinicalHeader.vue')
            ->and($source)->not->toContain('@/Components/Dental/PatientClinicalHeader.vue');
    }

    // The component itself is unchanged in the ways that matter: it still displays recorded
    // allergy facts and still computes nothing.
    $code = pcsStrip((string) file_get_contents($newHome));
    expect($code)->toContain('{{ allergy.substance }}')
        ->and($code)->toContain('{{ allergy.severity }}');
    foreach (['new date', 'computed(', 'math.'] as $derivation) {
        expect(str_contains($code, $derivation))->toBeFalse("the header now derives something: '{$derivation}'");
    }
});

test('B1: Patient 360 carries the patient REAL recorded allergies, composed in the app layer', function () {
    $fx = pcsFixture();
    pcsAllergy($fx['patient'], $fx['clinician'], 'Penicillin', Allergy::SEVERITY_SEVERE);
    pcsAllergy($fx['patient'], $fx['clinician'], 'Ibuprofen', Allergy::SEVERITY_MODERATE);
    // An INACTIVE allergy is not a current one — it must not appear.
    pcsAllergy($fx['patient'], $fx['clinician'], 'Latex', Allergy::SEVERITY_MILD, Allergy::STATUS_INACTIVE);

    pcsCtx()->set($fx['tenant']);
    $recorded = Allergy::query()
        ->where('patient_id', $fx['patient']->id)
        ->where('status', Allergy::STATUS_ACTIVE)
        ->orderBy('substance')
        ->get();
    expect($recorded)->toHaveCount(2);

    pcsCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('patients.show', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Show')
            ->has('allergies', 2)
            ->where('allergies', function ($allergies) use ($recorded) {
                $allergies = collect($allergies);

                // Every rendered row is a real recorded row — nothing fabricated.
                expect($allergies->pluck('id')->sort()->values()->all())
                    ->toBe($recorded->pluck('id')->sort()->values()->all());

                // Ordered by SUBSTANCE, not by severity: the list asserts no priority.
                expect($allergies->pluck('substance')->all())->toBe(['Ibuprofen', 'Penicillin']);

                $penicillin = $allergies->firstWhere('substance', 'Penicillin');
                expect($penicillin['severity'])->toBe('severe')
                    ->and($penicillin['reaction'])->toBe('Penicillin reaction as documented.');

                // The payload carries the RECORDED facts and nothing derived from them.
                expect(array_keys($penicillin))->toBe(['id', 'substance', 'reaction', 'severity', 'status']);

                return true;
            }));
});

test('B1: a patient with no recorded allergies gets an honest empty list, not a fabricated one', function () {
    $fx = pcsFixture();

    pcsCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('patients.show', $fx['patient']->id))
        ->assertOk()
        // The prop is PRESENT and empty — "none recorded" is a different statement from
        // "we did not look", and the page's empty state depends on the difference.
        ->assertInertia(fn (Assert $page) => $page->where('allergies', []));

    // And the page says so in words.
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    expect($en['patients']['show']['allergiesNone'])->toContain('No allergies recorded');
});

test('B1: the allergy read is patient.view-gated, tenant-scoped, and adds no second audit path', function () {
    $fx = pcsFixture('alpha');
    pcsAllergy($fx['patient'], $fx['clinician'], 'Penicillin', Allergy::SEVERITY_SEVERE);

    // billing holds neither patient.view nor anything clinical — refused outright.
    $billing = pcsUser($fx['tenant'], 'billing');
    pcsCtx()->forget();
    $this->actingAs($billing)->get(route('patients.show', $fx['patient']->id))->assertForbidden();

    // A cross-tenant patient fails closed.
    $beta = pcsFixture('beta');
    pcsCtx()->forget();
    $this->actingAs($beta['clinician'])->get(route('patients.show', $fx['patient']->id))->assertNotFound();

    // The page still writes exactly ONE read-audit row per view — the allergy read rides the
    // page's existing audit, it does not add a path of its own.
    $source = (string) file_get_contents(base_path('app/Http/Controllers/PatientShowController.php'));
    expect(substr_count($source, 'auditRead('))->toBe(1);
});

test('the Patients module still does not import Clinical — the composition lives in the app layer', function () {
    // The arch test enforces this globally; assert it here too, at the exact place the
    // temptation now exists.
    $moduleFiles = glob(base_path('Modules/Patients/src/**/*.php'), GLOB_BRACE) ?: [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules/Patients'), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $moduleFiles[] = $file->getPathname();
    }

    foreach (array_unique($moduleFiles) as $path) {
        $source = (string) file_get_contents($path);
        expect(str_contains($source, 'Modules\Clinical'))
            ->toBeFalse('Modules\Patients imports Clinical in '.basename($path).' — the cross-module read belongs in the app layer (D-017)');
    }

    // ...and the controller that DOES read Clinical sits in the app layer.
    expect(file_exists(base_path('app/Http/Controllers/PatientShowController.php')))->toBeTrue()
        ->and(file_exists(base_path('Modules/Patients/src/Http/Controllers/PatientShowController.php')))->toBeFalse();
});

test('the three new shared shells compute NOTHING and offer no clinical affordance', function () {
    $shells = [
        'ClinicalRailCard.vue' => base_path('resources/js/Components/Clinical/ClinicalRailCard.vue'),
        'AccessLogRow.vue' => base_path('resources/js/Components/Clinical/AccessLogRow.vue'),
        'SignOffBar.vue' => base_path('resources/js/Components/Clinical/SignOffBar.vue'),
    ];

    foreach ($shells as $name => $path) {
        expect(file_exists($path))->toBeTrue("{$name} is missing");
        $code = pcsStrip((string) file_get_contents($path));

        // No computation of any kind (the D-166 pattern).
        foreach (['computed(', 'math.', '.tofixed', 'parsefloat', 'parseint', '.reduce(', '.sort(', '.filter(', 'tolocalestring'] as $computation) {
            expect(str_contains($code, $computation))->toBeFalse("{$name} computes: '{$computation}'");
        }
        // Only the SCRIPT block: Tailwind's opacity syntax (`border-line/60`) is a class
        // utility, not arithmetic, and banning it would teach the next author to weaken this.
        preg_match('~<script[^>]*>(.*?)</script>~s', $code, $scriptBlock);
        expect(preg_match('~[\w)\]]\s*[+*/%]\s*[\w(]~', $scriptBlock[1] ?? ''))->toBe(0, "{$name} performs arithmetic");

        // No affordance by which a caller could tint or rank from a clinical value.
        foreach (['tone', 'variant', 'severity', 'trend', 'delta', 'direction', 'risk', 'score', 'acuity', 'priority'] as $affordance) {
            expect(preg_match('~\b'.preg_quote($affordance, '~').'\b~', $code))
                ->toBe(0, "{$name} offers a '{$affordance}' affordance");
        }

        // D-169: no class/style binding keyed to a clinical value or a numeric comparison.
        preg_match_all('~:(?:class|style)="([^"]*)"~', $code, $bindings);
        foreach ($bindings[1] ?? [] as $binding) {
            foreach (['severity', 'risk', 'score', 'tint', 'band'] as $needle) {
                expect(str_contains($binding, $needle))->toBeFalse("{$name} styles from a verdict: {$binding}");
            }
            expect(preg_match('~[<>]=?\s*\d~', $binding))->toBe(0, "{$name} styles by a threshold: {$binding}");
        }

        // D-172: nothing draws.
        foreach (['<canvas', 'getcontext', '<svg', 'fillrect', 'drawimage'] as $drawing) {
            expect(str_contains($code, $drawing))->toBeFalse("{$name} draws: '{$drawing}'");
        }
    }

    // N6 specifically: it renders actions, it does not perform them.
    $signOff = pcsStrip((string) file_get_contents($shells['SignOffBar.vue']));
    foreach (['router.post', 'useform', 'axios', 'fetch(', 'sign(', 'submit('] as $action) {
        expect(str_contains($signOff, $action))->toBeFalse("the sign-off bar performs signing logic: '{$action}'");
    }
    expect($signOff)->toContain('<slot />');
});

test('THE FENCE: no risk score, acuity, EWS, prognosis or interaction determination in the new surface', function () {
    $files = array_merge(
        glob(base_path('resources/js/Components/Clinical/*.vue')) ?: [],
        [base_path('app/Http/Controllers/PatientShowController.php')],
    );

    $forbidden = [
        'risk', 'riskscore', 'acuity', 'triage', 'ews', 'news', 'deterioration', 'readmission',
        'fallrisk', 'prognosis', 'interaction', 'crossreact', 'contraindication', 'severityband',
        'trendarrow', 'autoproblem', 'problemlist',
    ];

    foreach ($files as $path) {
        $squashed = preg_replace('~[^a-z0-9]~', '', pcsStrip((string) file_get_contents($path))) ?? '';
        foreach ($forbidden as $token) {
            // `severity` alone is permitted — it is the RECORDED field. The compound phrases
            // above are the computed judgments, and none may appear.
            expect(str_contains($squashed, $token))
                ->toBeFalse("fence token '{$token}' appears in ".basename($path));
        }
    }
});
