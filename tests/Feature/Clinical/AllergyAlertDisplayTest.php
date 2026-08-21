<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Clinical\Exceptions\AllergyConflictException;
use Modules\Clinical\Models\Allergy;
use Modules\Clinical\Services\AllergyGuard;
use Modules\Clinical\Services\ClinicalListService;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Pharmacy\Contracts\MedicationSafetyProvider;
use Modules\Pharmacy\Services\NullMedicationSafetyProvider;
use Modules\Pharmacy\Support\SafetyContext;
use Modules\Pharmacy\Support\SafetyResult;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * ALLERGY.P1 — the SAFE parts only: the recorded-allergy record display (clinician-recorded facts,
 * surfaced) + a display-only shell over the certified-partner MedicationSafetyProvider seam (the
 * null-object today → the honest "not configured" state). THE FENCE: CareOS computes NO drug-allergy
 * conflict / cross-reactivity / class-match / contraindication / auto-block / substitution — that is a
 * certified-partner medical-device function (a permanent non-goal). These tests ADD coverage; no
 * existing behaviour test is modified.
 */

function aaTenant(): Tenant
{
    $tenant = Tenant::create(['name' => 'Alpha Clinic', 'slug' => 'alpha', 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

function aaDoctor(Tenant $tenant): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', 'doctor')->firstOrFail()->id]);

    return $user;
}

function aaRecordedAllergy(Tenant $tenant, User $doctor): array
{
    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN']);
    $recorder = StaffProfile::query()->create([
        'user_id' => $doctor->id, 'first_name' => 'Paula', 'last_name' => 'Practitioner',
        'display_name' => 'Paula Practitioner', 'profession' => 'doctor', 'primary_branch_id' => $branch->id,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Clara', 'last_name' => 'Clinical', 'date_of_birth' => '1984-01-02', 'sex' => 'female']);

    app(ClinicalListService::class)->recordAllergy($patient, $recorder, $doctor, [
        'substance' => 'Penicillin',
        'reaction' => 'Anaphylaxis requiring adrenaline and admission.',
        'source' => 'A&E discharge summary, confirmed by the patient.',
        'severity' => Allergy::SEVERITY_SEVERE,
        'verified_at' => now()->subYear(),
    ]);

    return [$patient, $recorder];
}

/** Recursively scan a source dir; true if any .php file CONTAINS the needle (used for the fence proof). */
function aaSourceContains(string $absDir, string $needle): bool
{
    /*
     * POSITIVE CONTROL (FENCE-AUDIT / D-174). A missing directory used to return false, which
     * made the caller's `->toBeFalse()` pass having scanned NOTHING — the guard would go
     * quiet the moment its target moved. Fail loudly instead.
     */
    if (! is_dir($absDir)) {
        throw new RuntimeException("fence scan target does not exist: {$absDir} — the guard would otherwise pass having scanned nothing");
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), $needle)) {
            return true;
        }
    }

    return false;
}

// ── The record card surfaces CLINICIAN-RECORDED facts (severity shown, not computed) ────────────

test('the chart surfaces recorded allergy facts — severity/source/verification are recorded, not computed', function () {
    $tenant = aaTenant();
    $doctor = aaDoctor($tenant);
    [$patient] = aaRecordedAllergy($tenant, $doctor);

    $this->actingAs($doctor)
        ->get(route('clinical.chart', $patient->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('allergies', function ($allergies) {
            $a = collect($allergies)->firstWhere('substance', 'Penicillin');

            return $a !== null
                && $a['reaction'] === 'Anaphylaxis requiring adrenaline and admission.'
                && $a['source'] === 'A&E discharge summary, confirmed by the patient.'
                // The RECORDED severity, surfaced as a fact — CareOS did not grade it.
                && $a['severity'] === Allergy::SEVERITY_SEVERE
                && $a['verified_at'] !== null;
        }));
});

// ── The seam region shows the honest not-configured state (the null-object) ──────────────────────

test('the medication-safety seam renders the honest not-configured state today (null-object)', function () {
    $tenant = aaTenant();
    $doctor = aaDoctor($tenant);
    [$patient] = aaRecordedAllergy($tenant, $doctor);

    $this->actingAs($doctor)
        ->get(route('clinical.chart', $patient->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('medicationSafety.providerConfigured', false) // no certified partner bound
            ->where('medicationSafety.advisories', [])); // the seam returns SafetyResult::none()
});

// ── THE FENCE: the null-object is the only path; CareOS constructs no finding ────────────────────

test('THE FENCE: the bound MedicationSafetyProvider is the null-object and CareOS manufactures no finding', function () {
    // The only shipped implementation is the null-object (advisory + human-owned; incapable of auto-blocking).
    expect(app(MedicationSafetyProvider::class))->toBeInstanceOf(NullMedicationSafetyProvider::class);
    expect(app(MedicationSafetyProvider::class)->checkOrder(new SafetyContext('p'))->hasAlerts())->toBeFalse();

    // CareOS never CONSTRUCTS a SafetyAlert — a finding only ever comes FROM a certified partner.
    expect(aaSourceContains(base_path('Modules/Pharmacy/src'), 'new SafetyAlert('))->toBeFalse();
    expect(aaSourceContains(base_path('Modules/Clinical/src'), 'new SafetyAlert('))->toBeFalse();
});

test('THE FENCE: the allergy record has no computed-class column and the guard is exact-match only (no cross-reactivity)', function () {
    $tenant = aaTenant();
    $doctor = aaDoctor($tenant);
    [$patient] = aaRecordedAllergy($tenant, $doctor); // an ACTIVE Penicillin allergy (substance_key 'penicillin')

    // (a) The allergies table stores RECORDED FACTS only — there is NO drug-class / cross-reactivity /
    //     category column that a homemade engine would need. Class-matching is structurally impossible.
    $columns = Schema::getColumnListing('allergies');
    foreach (['class', 'category', 'cross_reactivity', 'interaction', 'drug_class'] as $forbidden) {
        expect($columns)->not->toContain($forbidden);
    }

    // (b) The ONLY allergy hard-stop, AllergyGuard, is a DETERMINISTIC EXACT-MATCH on the recorded
    //     substance — it does NOT cross-react by drug class. A patient allergic to Penicillin does NOT
    //     trip the guard for Amoxicillin (a same-class drug) — CareOS computes no cross-reactivity…
    $guard = app(AllergyGuard::class);
    $guard->check($patient, 'amoxicillin'); // no exception — no class-matching
    expect(true)->toBeTrue();

    // …but it DOES catch the exact recorded substance (the deterministic guard, unchanged).
    expect(fn () => $guard->check($patient, 'Penicillin'))
        ->toThrow(AllergyConflictException::class);
});

// ── The region is genuinely wired to the seam binding (a connected partner flips the flag) ───────

test('the seam region is wired to the real binding — a connected partner flips providerConfigured', function () {
    $tenant = aaTenant();
    $doctor = aaDoctor($tenant);
    [$patient] = aaRecordedAllergy($tenant, $doctor);

    // Bind a fake certified partner (as the seam is designed to be swapped) — its output is advisory.
    app()->bind(MedicationSafetyProvider::class, fn () => new class implements MedicationSafetyProvider
    {
        public function checkOrder(SafetyContext $context): SafetyResult
        {
            return SafetyResult::none();
        }

        public function checkAdministration(SafetyContext $context): SafetyResult
        {
            return SafetyResult::none();
        }
    });

    // The display reflects the REAL binding (not a hardcoded false) — proving it is genuinely seam-wired.
    $this->actingAs($doctor)
        ->get(route('clinical.chart', $patient->id))
        ->assertInertia(fn (Assert $page) => $page->where('medicationSafety.providerConfigured', true));
});

// ── RBAC + tenant scope (the chart is patient.view-gated, read-only) ─────────────────────────────

test('the chart (with the allergy record display) is patient.view-gated', function () {
    $tenant = aaTenant();
    $reception = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $reception->id, 'role_id' => Role::query()->where('key', 'reception')->firstOrFail()->id]);
    $doctor = aaDoctor($tenant);
    [$patient] = aaRecordedAllergy($tenant, $doctor);

    // reception holds patient.view (front desk) — allowed; the display is read-only (a GET, no block control).
    $this->actingAs($reception)->get(route('clinical.chart', $patient->id))->assertOk();
});
