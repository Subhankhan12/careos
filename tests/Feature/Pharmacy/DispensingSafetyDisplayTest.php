<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Models\AuditEvent;
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
|--------------------------------------------------------------------------
| QA-FIX.5a — the dispensing surface shows recorded allergies + the seam (P5-C1, D-206)
|--------------------------------------------------------------------------
| Phase 5 drove the dispensing screen for a patient with a RECORDED SEVERE
| PENICILLIN allergy ("Anaphylaxis requiring adrenaline and hospital
| admission") holding an ACTIVE AMOXICILLIN order. The screen showed the
| order, the stock and the history — and nothing else.
|
| THE FENCE ITSELF HELD: nothing claimed a check had happened. The defect was
| the OMISSION of a recorded, life-threatening fact at the point the drug is
| released, while the clinical chart displayed it two clicks away.
|
| THE EMPTY CASE IS THE DANGEROUS ONE. An allergy panel whose empty state
| reads "no recorded allergies" can be read as "checked and clear" — the D-179
| breach this product avoids everywhere else. So the seam statement renders
| WITH allergies AND WITHOUT, and these tests pin the WITHOUT case hardest.
*/

/**
 * PHRASES that would turn a record display into a safety verdict. None may appear.
 *
 * These are deliberately PHRASES, not morphemes. A first version of this list contained the bare word
 * "safe" and failed — because it matches "Automated medication-safety checking", which is the honest
 * NAME of the seam, not a claim about the patient. Scanning for "safe" would forbid the product from
 * naming the thing it is being honest about; the risk is an asserted RESULT, so the assertion is on
 * result phrases.
 */
const DSD_CLEARANCE_WORDS = [
    'no interactions', 'no interaction found', 'no known interactions',
    'safe to dispense', 'safe to administer', 'safe to give', 'is safe',
    'cleared', 'all clear', 'clear to dispense',
    'no conflicts', 'no contraindications', 'no allergies found',
    'no issues found', 'verified safe', 'check passed', 'checks passed',
];

function dsdFixture(string $slug = 'safety'): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Klinik '.$slug, 'slug' => 'klinik-'.$slug, 'region' => 'eu', 'status' => 'active',
    ]);
    app(TenantContext::class)->set($tenant);

    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);

    $make = function (string $email, string $roleKey) use ($tenant, $branch): User {
        $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create(['email' => $email]);
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
        ]);
        StaffProfile::query()->create([
            'user_id' => $user->id, 'first_name' => 'Staff', 'last_name' => ucfirst($roleKey),
            'display_name' => 'Staff '.$roleKey, 'profession' => $roleKey, 'primary_branch_id' => $branch->id,
        ]);

        return $user;
    };

    return [
        'tenant' => $tenant,
        'branch' => $branch,
        'pharmacist' => $make('pharmacist@'.$slug.'.test', 'pharmacist'),
        'technician' => $make('technician@'.$slug.'.test', 'pharmacy_technician'),
        'recorder' => $make('doctor@'.$slug.'.test', 'doctor'),
    ];
}

function dsdPatient(string $last = 'Zimmermann'): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Greta', 'last_name' => $last, 'date_of_birth' => '1949-05-05', 'sex' => 'female',
    ]);
}

function dsdAllergy(array $fx, Patient $patient, string $substance, string $severity, ?string $reaction = null): Allergy
{
    return Allergy::query()->create([
        'patient_id' => $patient->id,
        'substance' => $substance,
        'substance_key' => mb_strtolower($substance),
        'reaction' => $reaction,
        'source' => 'patient_reported',
        'severity' => $severity,
        'status' => Allergy::STATUS_ACTIVE,
        'recorded_by' => StaffProfile::query()->where('user_id', $fx['recorder']->id)->firstOrFail()->id,
        'recorded_at' => now(),
    ]);
}

/** Every pharmacy surface that puts a medication action in front of a human. */
function dsdSurfaces(Patient $patient): array
{
    return [
        'dispensing' => ['/pharmacy/patients/'.$patient->id.'/dispensing', 'Pharmacy/Dispensing'],
        'medications' => ['/pharmacy/patients/'.$patient->id.'/medications', 'Pharmacy/MedicationOrders'],
        'emar' => ['/pharmacy/patients/'.$patient->id.'/emar', 'Pharmacy/Emar'],
    ];
}

test('the dispensing screen carries the patient REAL recorded allergies — including a severe one', function () {
    $fx = dsdFixture('sev');
    $patient = dsdPatient();
    dsdAllergy($fx, $patient, 'Penicillin', 'severe', 'Anaphylaxis requiring adrenaline and hospital admission');

    $this->actingAs($fx['pharmacist'])
        ->get('/pharmacy/patients/'.$patient->id.'/dispensing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pharmacy/Dispensing')
            ->has('allergies', 1)
            // The fixture uses a SEVERE allergy deliberately: a mild one would pass a weaker assertion
            // and prove nothing about the case that matters (D-174).
            ->where('allergies.0.substance', 'Penicillin')
            ->where('allergies.0.severity', 'severe')
            ->where('allergies.0.reaction', 'Anaphylaxis requiring adrenaline and hospital admission')
            ->where('allergies.0.status', Allergy::STATUS_ACTIVE)
            ->has('medicationSafety'));
});

test('ALL THREE medication-action surfaces carry the allergies and the seam, for BOTH pharmacy roles', function () {
    $fx = dsdFixture('all');
    $patient = dsdPatient();
    dsdAllergy($fx, $patient, 'Penicillin', 'severe', 'Anaphylaxis');

    foreach (['pharmacist', 'technician'] as $role) {
        foreach (dsdSurfaces($patient) as $name => [$url, $component]) {
            $this->actingAs($fx[$role])
                ->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component)
                    ->has('allergies', 1)
                    ->where('medicationSafety.providerConfigured', false));
        }
    }
});

test('THE EMPTY CASE — with NO recorded allergy the seam still renders, and nothing reads as a clearance', function () {
    $fx = dsdFixture('empty');
    $patient = dsdPatient('NoAllergies');

    // This is the case that can kill someone by omission: a pharmacist sees an empty panel and reads
    // it as "checked and clear". The payload must still carry the seam state so the screen can say
    // that nothing was checked.
    foreach (dsdSurfaces($patient) as [$url, $component]) {
        $this->actingAs($fx['pharmacist'])
            ->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component($component)
                ->has('allergies', 0)
                ->has('medicationSafety')
                ->where('medicationSafety.providerConfigured', false));
    }
});

test('THE EMPTY CASE IS PINNED IN THE TEMPLATE, because a payload assertion cannot see rendering', function () {
    // HONEST NOTE, measured by mutation rather than assumed. The test above asserts the CONTROLLER
    // passes `allergies: []` and `medicationSafety`. That payload is identical whether the panel then
    // renders the seam or hides itself — so changing the panel's own condition back to
    // `v-if="active.length > 0"` (making it vanish exactly when nothing is recorded, the D-179 shape
    // this fix exists to prevent) leaves the payload test GREEN. It did, when driven.
    //
    // With no @vue/test-utils in this repo (QA-FIX.4d), the guard is structural and the BROWSER
    // verification in the gate report is the proof. Do not delete this thinking the payload test
    // covers it — it does not.
    $panel = (string) file_get_contents(resource_path('js/Components/AllergyRecordPanel.vue'));

    // The panel must render when there are allergies OR when a medication-action screen opts in.
    expect($panel)->toContain('v-if="active.length > 0 || alwaysShowSeam"')
        // …and it must carry an explicit empty-state line that denies being a check.
        ->and($panel)->toContain("t('allergyAlert.noneRecorded')")
        ->and($panel)->toContain('v-if="active.length === 0"');

    // Every medication-action screen must opt in. A screen that forgets `always-show-seam` silently
    // reverts to the dangerous behaviour for patients with no recorded allergy.
    foreach (['Dispensing', 'MedicationOrders', 'Emar'] as $page) {
        $vue = (string) file_get_contents(resource_path("js/pages/Pharmacy/{$page}.vue"));
        expect($vue)->toContain('<AllergyRecordPanel')
            ->and($vue)->toContain('always-show-seam');
    }
});

test('POSITIVE CONTROL — the clinical chart does NOT opt in, so its behaviour is unchanged', function () {
    // The chart is Phase 2's surface and is out of scope here. `alwaysShowSeam` defaults to false, and
    // the chart must not pass it, so its rendering is byte-identical to before this fix.
    $chart = (string) file_get_contents(resource_path('js/pages/Clinical/Chart.vue'));

    expect($chart)->toContain('<AllergyRecordPanel')
        ->and($chart)->not->toContain('always-show-seam');

    $panel = (string) file_get_contents(resource_path('js/Components/AllergyRecordPanel.vue'));
    expect($panel)->toContain('{ alwaysShowSeam: false }');
});

test('the seam statement and the empty-state wording say what they do NOT do', function () {
    $lang = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);
    $seam = $lang['allergyAlert']['seam'];

    // The honest state: names the absent function, attributes it to a certified partner, and promises
    // only advisory behaviour if one is ever connected.
    expect($seam['notConfigured'])
        ->toContain('No automated medication-safety checking is configured')
        ->toContain('cross-reactivity')
        ->toContain('is not performed here');

    // The empty state must state the RECORD, and immediately deny being a check.
    expect($lang['allergyAlert']['noneRecorded'])
        ->toContain('No allergies are recorded')
        ->toContain('not the result of a check');

    expect($lang['allergyAlert']['subtitle'])->toContain('it does not compute drug-allergy conflicts');
});

test('NO CLEARANCE LANGUAGE appears in the rendered payload — scanned over a NON-EMPTY render', function () {
    $fx = dsdFixture('words');
    $patient = dsdPatient();
    dsdAllergy($fx, $patient, 'Penicillin', 'severe', 'Anaphylaxis');

    // GOV.P3: scan what the page actually RENDERS, not a source file, and scan a render that has
    // content — a scan over an empty page would pass trivially.
    $html = $this->actingAs($fx['pharmacist'])
        ->get('/pharmacy/patients/'.$patient->id.'/dispensing')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Penicillin'); // the scan is over real content

    $lang = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);
    $rendered = mb_strtolower($html.' '.json_encode($lang['allergyAlert']));

    foreach (DSD_CLEARANCE_WORDS as $word) {
        expect($rendered)->not->toContain($word);
    }
});

test('D-169 — severity is a recorded fact, never an ordering or a grade', function () {
    $fx = dsdFixture('d169');
    $patient = dsdPatient();
    dsdAllergy($fx, $patient, 'Penicillin', 'severe', 'Anaphylaxis');
    dsdAllergy($fx, $patient, 'Latex', 'mild', 'Contact rash');

    $this->actingAs($fx['pharmacist'])
        ->get('/pharmacy/patients/'.$patient->id.'/dispensing')
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            // Ordered by SUBSTANCE, never by severity — the severe allergy is not floated to the top.
            $page->component('Pharmacy/Dispensing')
                ->has('allergies', 2)
                ->where('allergies.0.substance', 'Latex')
                ->where('allergies.1.substance', 'Penicillin');

            // Severity travels as the recorded word and nothing else: no rank, no score, no colour.
            $allergies = $page->toArray()['props']['allergies'];
            foreach ($allergies as $allergy) {
                expect(array_keys($allergy))->not->toContain('rank')
                    ->and(array_keys($allergy))->not->toContain('score')
                    ->and(array_keys($allergy))->not->toContain('level')
                    ->and(array_keys($allergy))->not->toContain('colour')
                    ->and(array_keys($allergy))->not->toContain('color');
            }
        });
});

test('POSITIVE CONTROL — the read is permission-gated and tenant-scoped fail-closed', function () {
    $fx = dsdFixture('gate');
    $patient = dsdPatient();
    dsdAllergy($fx, $patient, 'Penicillin', 'severe');

    // A user WITHOUT patient.view is refused (D-183: the guard behind the guard).
    $outsider = User::factory()->forTenant($fx['tenant'])->twoFactorEnabled()->create();
    $this->actingAs($outsider)
        ->get('/pharmacy/patients/'.$patient->id.'/dispensing')
        ->assertForbidden();

    // A patient in ANOTHER tenant is not reachable at all.
    $other = dsdFixture('other');
    $otherPatient = dsdPatient('Elsewhere');
    app(TenantContext::class)->forget();
    app(TenantContext::class)->set($fx['tenant']);

    $this->actingAs($fx['pharmacist'])
        ->get('/pharmacy/patients/'.$otherPatient->id.'/dispensing')
        ->assertNotFound();
});

test('POSITIVE CONTROL — showing the allergies adds NO second read-audit row', function () {
    $fx = dsdFixture('audit');
    $patient = dsdPatient();
    dsdAllergy($fx, $patient, 'Penicillin', 'severe');

    $before = AuditEvent::query()->count();

    $this->actingAs($fx['pharmacist'])
        ->get('/pharmacy/patients/'.$patient->id.'/dispensing')
        ->assertOk();

    $after = AuditEvent::query()->count();

    // The screen already logged the patient read once. The safety record must not add a second path
    // (the PC.P1 / P5 count rule) — exactly one new row per render.
    expect($after - $before)->toBe(1);
});
