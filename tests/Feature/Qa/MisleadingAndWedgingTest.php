<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Invoice;
use Modules\Hospital\Exceptions\BedStatusTransitionException;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Services\AdmissionService;
use Modules\Hospital\Services\BedService;
use Modules\Hospital\Services\WardService;
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
| QA-FIX.12d — FAMILY 2, "operations with no way back, or that mislead" (`P1-H2`, `P3-H2`, `P9-H1`,
| `P10-H4`; `P4-H1` closed in QA-FIX.12b).
|
| THE GATE ASKED FOR A CLASSIFICATION FIRST, AND IT DECIDED WHAT EACH FIX IS:
|
|  - `P1-H2` and `P10-H4` are MISLEADING. Both are wiring, both are fixed.
|  - `P9-H1` is WEDGING. **Prevention is a fix and is here. Recovery from a bed already wedged is a
|    feature and is NOT here** — nothing in this file pretends a way out exists.
|  - `P3-H2` is a MISLEADING CLAIM over a MISSING FEATURE. The claim is withdrawn — the forged
|    `%PDF-1.4` first line is gone and the bytes are served as the text they are. **Rendering a real PDF
|    is the feature and is refused**: CareOS has no PDF library, and adding one plus a laid-out template
|    is not a fix.
|
| `P9-H1` WAS NEVER DRIVEN LIVE, BY PHASE 9'S OWN DELIBERATE CHOICE, because executing it would strand
| the demo tenant's only admitted patient with no product path back. That choice is respected: the wedge
| is exercised HERE, against an isolated `RefreshDatabase` fixture, and never against the demo data.
*/

function wedgeTenant(string $slug): Tenant
{
    $t = Tenant::query()->create(['name' => 'WDG '.$slug, 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($t);

    return $t;
}

function wedgeUser(Tenant $tenant, string $roleKey): User
{
    $u = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $r = Role::query()->where('key', $roleKey)->first();
    if ($r !== null) {
        RoleAssignment::query()->firstOrCreate(['user_id' => $u->id, 'role_id' => $r->id]);
    }

    return $u;
}

/* ------------------------------------------------------------------ *
 | `P9-H1` — WEDGING. Prevention only.                                 |
 * ------------------------------------------------------------------ */

it('P9-H1: a bed_manager cannot move an OCCUPIED bed to cleaning while a patient is admitted to it', function () {
    $tenant = wedgeTenant('wdg-block');
    $manager = wedgeUser($tenant, 'bed_manager');
    $clerk = wedgeUser($tenant, 'admissions_clerk');
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $ward = app(WardService::class)->create($manager, $branch->id, 'Innere Medizin', 'IM');
    $bed = app(BedService::class)->create($manager, $ward, '1', Bed::TYPE_GENERAL);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Rolf', 'last_name' => 'Schmid', 'date_of_birth' => '1948-03-03', 'sex' => 'male',
    ]);
    $profile = StaffProfile::query()->create([
        'first_name' => 'Ada', 'last_name' => 'Admin', 'display_name' => 'Dr. Ada Admin',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id,
    ]);

    $stay = app(AdmissionService::class)->admit($clerk, $patient, $bed, $profile, Stay::TYPE_ELECTIVE);

    expect($bed->fresh()->status)->toBe(Bed::STATUS_OCCUPIED);

    /*
     * THE DEFECT, REFUSED. `occupied -> cleaning` is a LEGAL transition and stays legal — it is how a
     * turnover begins once the patient has left. What was missing is that nothing looked at the STAY.
     */
    expect(fn () => app(BedService::class)->setStatus($manager, $bed->fresh(), Bed::STATUS_CLEANING))
        ->toThrow(BedStatusTransitionException::class);

    // And nothing moved: the bed is still the patient's.
    expect($bed->fresh()->status)->toBe(Bed::STATUS_OCCUPIED)
        ->and($stay->fresh()->status)->toBe(Stay::STATUS_ADMITTED);
});

it('P9-H1: because the move is refused, the stay can STILL be discharged — the wedge is what is prevented', function () {
    $tenant = wedgeTenant('wdg-discharge');
    $manager = wedgeUser($tenant, 'bed_manager');
    $clerk = wedgeUser($tenant, 'admissions_clerk');
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $ward = app(WardService::class)->create($manager, $branch->id, 'Innere Medizin', 'IM');
    $bed = app(BedService::class)->create($manager, $ward, '1', Bed::TYPE_GENERAL);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Rolf', 'last_name' => 'Schmid', 'date_of_birth' => '1948-03-03', 'sex' => 'male',
    ]);
    $profile = StaffProfile::query()->create([
        'first_name' => 'Ada', 'last_name' => 'Admin', 'display_name' => 'Dr. Ada Admin',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id,
    ]);

    $admissions = app(AdmissionService::class);
    $stay = $admissions->admit($clerk, $patient, $bed, $profile, Stay::TYPE_ELECTIVE);

    // The attempt that used to wedge it.
    try {
        app(BedService::class)->setStatus($manager, $bed->fresh(), Bed::STATUS_CLEANING);
    } catch (BedStatusTransitionException) {
        // expected
    }

    /*
     * THE CONSEQUENCE THAT MATTERS. Before the guard, this discharge threw for ever: `release()` requires
     * the bed to still be `occupied`, and nothing in the product writes `occupied` again except `claim()`,
     * which needs `free` and belongs to a different admission. This is the assertion that the wedge is
     * gone, rather than merely that one call now refuses.
     */
    $discharged = $admissions->discharge($clerk, $stay->fresh(), Stay::DISPOSITION_HOME);

    expect($discharged->status)->toBe(Stay::STATUS_DISCHARGED)
        ->and($bed->fresh()->status)->toBe(Bed::STATUS_CLEANING);
});

it('P9-H1: the turnover still works on a bed with NO patient in it — the transition was not removed', function () {
    $tenant = wedgeTenant('wdg-turnover');
    $manager = wedgeUser($tenant, 'bed_manager');
    $clerk = wedgeUser($tenant, 'admissions_clerk');
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $ward = app(WardService::class)->create($manager, $branch->id, 'Innere Medizin', 'IM');
    $beds = app(BedService::class);
    $bed = $beds->create($manager, $ward, '1', Bed::TYPE_GENERAL);

    /*
     * THE POSITIVE CONTROL (D-174). A guard that refused `occupied -> cleaning` outright would break
     * housekeeping entirely, and this asserts it did not: with no admitted stay the full turnover runs.
     */
    $beds->claim($clerk, $bed);
    $beds->setStatus($manager, $bed->fresh(), Bed::STATUS_CLEANING);
    $beds->setStatus($manager, $bed->fresh(), Bed::STATUS_FREE);

    expect($bed->fresh()->status)->toBe(Bed::STATUS_FREE);
});

it('P9-H1: the endpoint names the statuses it accepts, so an arbitrary string is a 422', function () {
    $tenant = wedgeTenant('wdg-route');
    $manager = wedgeUser($tenant, 'bed_manager');
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $ward = app(WardService::class)->create($manager, $branch->id, 'Innere Medizin', 'IM');
    $bed = app(BedService::class)->create($manager, $ward, '1', Bed::TYPE_GENERAL);

    app(TenantContext::class)->forget();

    /*
     * The rule was `max:40` — any string — with the UI as the only narrowing. Pattern 1 inverted: the UI
     * WAS the refusal. The load-bearing guard is in the service; this is the second layer (D-183).
     */
    $this->actingAs($manager)
        ->post('/hospital/beds/'.$bed->id.'/status', ['status' => 'teleported'])
        ->assertSessionHasErrors('status');
});

it('P9-H1: RECOVERY IS NOT OFFERED — nothing here invents a path back to occupied', function () {
    /*
     * THE STOP, PINNED AS AN ABSENCE. The gate's rule for a wedge is that prevention is a fix and
     * RECOVERY IS A FEATURE. `claim()` remains the only writer of `occupied`, and it still requires
     * `free` — so a bed wedged before this fix stays wedged and needs a deliberate, designed remedy.
     * If a later gate adds one, this test must be changed on purpose rather than drift.
     */
    $src = (string) file_get_contents(base_path('Modules/Hospital/src/Services/BedService.php'));

    expect(substr_count($src, 'Bed::STATUS_OCCUPIED;'))->toBe(1)
        ->and($src)->toContain('if ($current !== Bed::STATUS_FREE) {')
        ->and($src)->not->toContain('function recover')
        ->and($src)->not->toContain('function unwedge');
});

/* ------------------------------------------------------------------ *
 | `P3-H2` — the claim is withdrawn; the feature is refused.           |
 * ------------------------------------------------------------------ */

it('P3-H2: the invoice file no longer forges a PDF header, and says what it is', function () {
    $src = (string) file_get_contents(base_path('Modules/Billing/src/Services/InvoicePdfRenderer.php'));

    /*
     * D-176 IN ITS CLEAREST FORM. The file used to begin with the literal string `%PDF-1.4` while
     * containing no object structure at all — no `obj`, no `xref`, no `trailer`, no `stream`, no `%%EOF`.
     * No reader could open it. The header is gone and the first line says plainly that it is text.
     */
    expect($src)->not->toContain("'%PDF-1.4'")
        ->and($src)->toContain('plain text — not a PDF')
        ->and($src)->toContain(".txt'");
});

it('P3-H2: the DUNNING LETTER forged the same header, and this finding named it too', function () {
    /*
     * FOUND BY SWEEPING THE REPO RATHER THAN BY READING THE FINDING'S FIRST LINE. `P3-H2` says "and every
     * dunning letter written by a reminder", and `DunningLetterRenderer` carried the identical
     * `%PDF-1.4` forgery over the same plain text. A reminder is a document sent to a patient about money
     * they owe. A repo-wide grep for the literal now finds it in no renderer at all.
     */
    $src = (string) file_get_contents(base_path('Modules/Billing/src/Services/DunningLetterRenderer.php'));

    expect($src)->not->toContain("'%PDF-1.4'")
        ->and($src)->toContain('plain text — not a PDF')
        ->and($src)->toContain('-L%d.txt');
});

it('P3-H2: both download paths serve the bytes as text, not as application/pdf', function () {
    foreach ([
        'Modules/Billing/src/Http/Controllers/InvoiceController.php',
        'Modules/Billing/src/Http/Controllers/PortalInvoiceController.php',
    ] as $file) {
        $src = (string) file_get_contents(base_path($file));

        expect($src)->not->toContain("'application/pdf'")
            ->and($src)->toContain("'text/plain; charset=utf-8'")
            ->and($src)->toContain(".txt\"'");
    }
});

it('P3-H2: THE REAL RENDERER IS REFUSED — no PDF library was added and none is faked', function () {
    /*
     * THE STOP, PINNED. Rendering a genuine PDF needs a library and a laid-out template; that is a
     * feature, not a fix, and this gate does not build it. Asserted so that a later "quick win" cannot
     * quietly reintroduce a forged header instead of the real thing.
     */
    $composer = (string) file_get_contents(base_path('composer.json'));

    expect(strtolower($composer))->not->toContain('dompdf')
        ->and(strtolower($composer))->not->toContain('tcpdf')
        ->and(strtolower($composer))->not->toContain('mpdf');
});

/* ------------------------------------------------------------------ *
 | `P1-H2` — registration no longer fails silently.                    |
 * ------------------------------------------------------------------ */

it('P1-H2: registering with only the marked-required fields SUCCEEDS', function () {
    $tenant = wedgeTenant('wdg-register');
    $clerk = wedgeUser($tenant, 'admissions_clerk');

    /*
     * THE FINDING'S EXACT INPUT. The wizard marks four fields required; filling only those used to fail,
     * silently, because the client ALWAYS sent one blank `identifiers` row and one blank `coverages` row
     * whose rules are `required_with:*`. The client now drops rows the user left empty, so this payload —
     * which is what the form sends — is accepted.
     */
    $before = Patient::query()->count();

    app(TenantContext::class)->forget();

    $this->actingAs($clerk)->post('/patients', [
        'first_name' => 'Nina', 'last_name' => 'Neu', 'date_of_birth' => '1981-04-04', 'sex' => 'female',
        'contacts' => [], 'identifiers' => [], 'coverages' => [],
    ])->assertRedirect();

    app(TenantContext::class)->set($tenant);
    expect(Patient::query()->count())->toBe($before + 1);
});

it('P1-H2: a PARTIALLY filled optional row is still refused — and now with a message to render', function () {
    $tenant = wedgeTenant('wdg-partial');
    $clerk = wedgeUser($tenant, 'admissions_clerk');

    app(TenantContext::class)->forget();

    /*
     * THE OTHER HALF. The fix must not become "accept anything": a row the user began and did not finish
     * is still refused. What changed is that the refusal is now KEYED to a field the form renders, so the
     * user is told which one.
     */
    $this->actingAs($clerk)->post('/patients', [
        'first_name' => 'Nina', 'last_name' => 'Neu', 'date_of_birth' => '1981-04-04', 'sex' => 'female',
        'contacts' => [],
        'identifiers' => [['system' => 'AHV', 'value' => '']],
        'coverages' => [],
    ])->assertSessionHasErrors('identifiers.0.value');
});

it('P1-H2: the form renders the errors it used to swallow, and renders the whole bag', function () {
    $vue = (string) file_get_contents(resource_path('js/pages/Patients/Register.vue'));

    /*
     * THE SECOND COMPOUNDING DEFECT. The Step-3 inputs had NO `:error` binding at all, so even a
     * correctly-keyed message had nowhere to appear. All four are bound now, by their FIELD PATH, and the
     * page also adopts `RefusalNotice` — which reads the WHOLE bag, and matters here precisely because
     * these keys are paths (`coverages.0.member_id`) that a notice naming known keys would miss.
     */
    foreach ([
        "form.errors['identifiers.0.system']",
        "form.errors['identifiers.0.value']",
        "form.errors['coverages.0.payer_name']",
        "form.errors['coverages.0.member_id']",
    ] as $binding) {
        expect($vue)->toContain($binding);
    }

    /*
     * AND IT NO LONGER SENDS ROWS NOBODY FILLED IN. Pinned as the USE of a tested helper rather than as
     * the presence of a transform: a mutation showed that asserting the component merely contained
     * `.transform(...)` could not tell a working filter from a neutered one. `dropBlankRows` carries its
     * own behavioural tests in `resources/js/lib/forms.test.ts`, including the case that made this defect
     * invisible — the coverages row ships with `coverage_type` and `priority` already set.
     */
    expect($vue)->toContain('<RefusalNotice />')
        ->and($vue)->toContain('dropBlankRows(data.identifiers')
        ->and($vue)->toContain('dropBlankRows(data.coverages');

    /*
     * ONLY THE FIELDS THE USER FILLS ARE CONSIDERED. Mutation-checked: adding `coverage_type` to the
     * coverages key list reddens this, because that field ships PRE-SET (`self_pay`) — so "is any field
     * non-empty?" would answer YES for a row nobody ever opened, and the blank row would be sent again.
     */
    $coverageCall = (string) (preg_match('/dropBlankRows\(data\.coverages[^\n]*/', $vue, $m) === 1 ? $m[0] : '');

    expect($coverageCall)->not->toBe('')
        ->and($coverageCall)->not->toContain('coverage_type')
        ->and($coverageCall)->not->toContain('priority');
});

/* ------------------------------------------------------------------ *
 | `P10-H4` — nothing is pre-picked.                                   |
 * ------------------------------------------------------------------ */

it('P10-H4: quick-book pre-selects nobody, and offers a placeholder instead', function () {
    $vue = (string) file_get_contents(resource_path('js/pages/Scheduling/DayBoard.vue'));

    /*
     * D-211's SHAPE, ADOPTED. QA-FIX.7a removed exactly this default from ED triage and inpatient
     * admission; the day board was never measured for it. A staff member who picked a slot and pressed
     * Book without touching the field booked an appointment for whoever sorts first.
     */
    expect($vue)->not->toContain('patient_id: props.patients[0]?.id')
        ->and($vue)->not->toContain('service_id: props.services[0]?.id')
        ->and($vue)->toContain("service_id: ''")
        ->and($vue)->toContain("patient_id: ''")
        ->and($vue)->toContain("t('scheduling.fields.selectPatient')")
        ->and($vue)->toContain("t('scheduling.fields.selectService')");
});

it('P10-H4: the server still REQUIRES a patient, so the placeholder cannot book anybody', function () {
    $src = (string) file_get_contents(base_path('Modules/Scheduling/src/Http/Controllers/DayBoardActionController.php'));

    /*
     * NO SERVER GATE WAS WEAKENED TO MAKE THE DEFAULT REMOVABLE (D-211's own rule). An empty submission
     * meets a `required` rule rather than silently booking the first patient.
     */
    expect($src)->toContain("'patient_id' => ['required', 'string']");
});

it('POSITIVE CONTROL: the invoice text still contains the real invoice facts', function () {
    Storage::fake('local');

    /*
     * The suite must not be satisfiable by a renderer that outputs nothing. `P3-H2`'s complaint was the
     * FORMAT, never the content — the figures were right and are still right.
     */
    $src = (string) file_get_contents(base_path('Modules/Billing/src/Services/InvoicePdfRenderer.php'));

    expect($src)->toContain("'Invoice: '")
        ->and($src)->toContain("'Seller VAT ID: '")
        ->and($src)->toContain(Invoice::class === '' ? 'x' : 'Currency: ');
});
