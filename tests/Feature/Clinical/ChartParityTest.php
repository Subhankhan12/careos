<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Medication;
use Modules\Clinical\Models\Problem;
use Modules\Clinical\Models\Recall;
use Modules\Clinical\Models\RecallRule;
use Modules\Clinical\Models\Vital;
use Modules\Clinical\Services\ClinicalNoteService;
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
 * PC.P2 — Patient Chart visual parity over the EXISTING backend.
 *
 * The two things the build already gets right, and which this gate must not reopen:
 *
 *  1. VITALS STAY TREND-FREE. Raw recorded values only — no band, flag, score, delta, trend,
 *     arrow or sparkline, and no styling keyed to a vital's value. The wireframe agrees with the
 *     build here: "a sparkline would already be interpretation."
 *  2. THE SUMMARY STAYS EXTRACTIVE at its SUGGEST ceiling, with per-line source chips and one
 *     explicit human insert. No new tool, no raised ceiling, no auto-insert.
 *
 * What the gate changed is counting: the band and tab chips now read SERVER-COMPUTED counts of
 * real rows instead of `array.length` in Vue — which mattered, because several of the lists are
 * deliberately partial.
 */

function cpCtx(): TenantContext
{
    return app(TenantContext::class);
}

function cpUser(Tenant $tenant, string $role): User
{
    cpCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, doctor: User, patient: Patient, staff: StaffProfile, branch: Branch}
 */
function cpFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    cpCtx()->set($tenant);
    $doctor = cpUser($tenant, 'doctor');
    $branch = Branch::query()->firstOrCreate(['code' => 'MAIN'], ['name' => 'Main', 'timezone' => 'Europe/Zurich']);
    $staff = StaffProfile::query()->create([
        'user_id' => $doctor->id, 'first_name' => 'Marco', 'last_name' => 'Brunner',
        'display_name' => 'Dr. M. Brunner', 'profession' => 'doctor', 'primary_branch_id' => $branch->id,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Nora', 'last_name' => 'Keller', 'date_of_birth' => '1988-03-14', 'sex' => 'female']);

    return compact('tenant', 'doctor', 'patient', 'staff', 'branch');
}

function cpEncounter(array $fx, string $type, string $startedAt): Encounter
{
    return Encounter::query()->create([
        'patient_id' => $fx['patient']->id,
        'branch_id' => $fx['branch']->id,
        'practitioner_id' => $fx['staff']->id,
        'type' => $type,
        'status' => 'closed',
        'started_at' => $startedAt,
    ]);
}

/** Strip comments so the scans test AFFORDANCES, not the prose documenting their absence. */
function cpStrip(string $source): string
{
    $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;
    $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;

    return strtolower(preg_replace('~(^|\s)//[^\n]*~m', '$1 ', $source) ?? $source);
}

test('the band and tab counts are SERVER-computed counts of real rows, not Vue array lengths', function () {
    $fx = cpFixture();
    cpEncounter($fx, 'consultation', '2026-07-09 08:32:00');
    cpEncounter($fx, 'telehealth', '2026-06-17 10:00:00');
    cpEncounter($fx, 'home_visit', '2026-05-12 09:00:00');

    Problem::query()->create(['patient_id' => $fx['patient']->id, 'description' => 'Hypertension', 'status' => 'active', 'recorded_by' => $fx['staff']->id, 'recorded_at' => now()]);
    Problem::query()->create(['patient_id' => $fx['patient']->id, 'description' => 'Migraine', 'status' => 'active', 'recorded_by' => $fx['staff']->id, 'recorded_at' => now()]);
    Medication::query()->create(['patient_id' => $fx['patient']->id, 'name' => 'Lisinopril', 'substance_key' => 'lisinopril', 'dose_text' => '10 mg', 'status' => 'active', 'started_on' => '2024-03-12', 'recorded_by' => $fx['staff']->id, 'recorded_at' => now()]);

    /*
     * A note plus an AMENDMENT. The notes LIST carries head versions only (the superseded v1 is
     * reachable through the version chain), so a Vue `notes.length` would say 1 while two note
     * rows exist. The count must mirror the list, and the version chain must still reach v1.
     */
    $notes = app(ClinicalNoteService::class);
    $encounter = Encounter::query()->where('patient_id', $fx['patient']->id)->orderByDesc('started_at')->firstOrFail();
    $note = $notes->saveDraft($encounter, $fx['staff'], ['subjective' => 'Improved sleep.'], $fx['doctor']);
    $signed = $notes->sign($note, $fx['doctor']);
    $notes->amend($signed, ['subjective' => 'Improved sleep; plan corrected.'], 'Transcription error in the plan section.', $fx['staff'], $fx['doctor']);

    $rule = RecallRule::query()->create(['name' => 'Blood pressure check', 'criteria' => ['active_problem_codes' => ['P-BP']], 'interval_months' => 6, 'active' => true]);
    Recall::query()->create(['patient_id' => $fx['patient']->id, 'rule_id' => $rule->id, 'due_on' => now()->addDays(66)->toDateString(), 'status' => 'due']);
    Recall::query()->create(['patient_id' => $fx['patient']->id, 'rule_id' => $rule->id, 'due_on' => now()->subDays(5)->toDateString(), 'status' => 'completed']);

    cpCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get(route('clinical.chart', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinical/Chart')
            ->where('counts.encounters', 3)
            ->where('counts.problems', 2)
            ->where('counts.medications', 1)
            // ONE head note, though two note rows exist — the count mirrors the list.
            ->where('counts.notes', 1)
            // Only the non-terminal recall is open.
            ->where('counts.openRecalls', 1)
            ->where('notes', fn ($notes) => count($notes) === 1)
            // ...and v1 is still reachable through the chain.
            ->where('notes.0.versions', function ($versions) {
                $versions = collect($versions);
                expect($versions)->toHaveCount(2);
                expect($versions->pluck('version')->sort()->values()->all())->toBe([1, 2]);
                $v1 = $versions->firstWhere('version', 1);
                expect($v1['status'])->toBe('signed')
                    ->and($v1['edit_url'])->toContain('/clinical/notes/');

                return true;
            }));
});

test('recall proximity is a plain calendar interval — no priority, no urgency, no tint', function () {
    $fx = cpFixture();
    $rule = RecallRule::query()->create(['name' => 'Blood pressure check', 'criteria' => ['active_problem_codes' => ['P-BP']], 'interval_months' => 6, 'active' => true]);
    Recall::query()->create(['patient_id' => $fx['patient']->id, 'rule_id' => $rule->id, 'due_on' => now()->addDays(66)->toDateString(), 'status' => 'due']);
    Recall::query()->create(['patient_id' => $fx['patient']->id, 'rule_id' => $rule->id, 'due_on' => now()->subDays(4)->toDateString(), 'status' => 'due']);

    cpCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get(route('clinical.chart', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('recalls', function ($recalls) {
                $recalls = collect($recalls);
                // Ordered by the recorded due date, and the interval is a signed day count.
                expect($recalls->pluck('due_in_days')->all())->toBe([-4, 66]);

                foreach ($recalls as $recall) {
                    // Facts only: no urgency/priority/level travels with a recall.
                    foreach (['urgency', 'priority', 'level', 'severity', 'risk', 'tone', 'colour', 'color'] as $judgment) {
                        expect(array_key_exists($judgment, $recall))->toBeFalse("recall carries a '{$judgment}'");
                    }
                }

                return true;
            }));

    // And nothing on the page is styled by the interval (D-169).
    $code = cpStrip((string) file_get_contents(base_path('resources/js/pages/Clinical/Chart.vue')));
    preg_match_all('~:(?:class|style)="([^"]*)"~', $code, $bindings);
    foreach ($bindings[1] ?? [] as $binding) {
        foreach (['due_in_days', 'due_on', 'overdue'] as $needle) {
            expect(str_contains($binding, $needle))->toBeFalse("the chart tints by recall proximity: {$binding}");
        }
    }
});

test('the agent panel wraps the EXISTING extractive tool at its SUGGEST ceiling and never auto-inserts', function () {
    // The tool itself is unchanged: extractive, suggest-ceiling.
    $tool = (string) file_get_contents(base_path('app/AiCore/Tools/ClinicalSummaryTool.php'));
    expect($tool)->toContain('autonomyCeiling: AutonomyPolicy::SUGGEST')
        ->and($tool)->toContain('ABSOLUTE CONSTRAINT')
        ->and($tool)->toContain('EXTRACTIVE');
    foreach (['AutonomyPolicy::AUTO', 'AutonomyPolicy::APPROVE'] as $raised) {
        expect(str_contains($tool, $raised))->toBeFalse("the summary tool's ceiling was raised to {$raised}");
    }

    // The page inserts ONLY from an explicit click — never on mount, never on refresh.
    $page = (string) file_get_contents(base_path('resources/js/pages/Clinical/Chart.vue'));
    expect($page)->toContain('@click="insertSummary"');
    $code = cpStrip($page);
    $squashed = str_replace(' ', '', $code);
    // The DEFINITION must exist; what is forbidden is invoking it automatically.
    foreach (['onmounted(()=>insertsummary', 'onmounted(insertsummary', 'watch(()=>props.aisummary', 'watcheffect(()=>insertsummary'] as $auto) {
        expect(str_contains($squashed, $auto))->toBeFalse("the chart auto-inserts the summary: '{$auto}'");
    }
    // ...and the only call site is the click handler.
    // Exactly two occurrences: the definition, and the @click that a HUMAN triggers.
    expect(substr_count($squashed, 'insertsummary'))
        ->toBe(2, 'insertSummary has a call site beyond its definition and the click handler');

    // No second summary tool was invented for this gate.
    $tools = array_map('basename', glob(base_path('app/AiCore/Tools/*.php')) ?: []);
    expect($tools)->toContain('ClinicalSummaryTool.php');
    foreach ($tools as $name) {
        expect(preg_match('~summary~i', $name) && $name !== 'ClinicalSummaryTool.php')
            ->toBeFalse("a second summary tool appeared: {$name}");
    }
});

test('THE RE-ASSERTION: vitals stay raw — no band, flag, score, delta, trend or sparkline', function () {
    $fx = cpFixture();

    /*
     * RECORD REAL VITALS FIRST. An absence assertion over an empty collection is vacuously
     * true — this test passed a `'band' => 'high'` mutation until these rows existed.
     */
    Vital::query()->create([
        'patient_id' => $fx['patient']->id,
        'recorded_at' => now()->subDays(2),
        'systolic' => 128, 'diastolic' => 82, 'heart_rate' => 74, 'spo2' => 98,
        'recorded_by' => $fx['staff']->id,
    ]);
    Vital::query()->create([
        'patient_id' => $fx['patient']->id,
        'recorded_at' => now(),
        'systolic' => 176, 'diastolic' => 104, 'heart_rate' => 96, 'spo2' => 91,
        'recorded_by' => $fx['staff']->id,
    ]);

    cpCtx()->forget();
    $response = $this->actingAs($fx['doctor'])->get(route('clinical.chart', $fx['patient']->id))->assertOk();
    $props = $response->viewData('page')['props'];

    // The vitals payload carries recorded values and nothing derived from them.
    $forbidden = ['band', 'bands', 'flag', 'flagged', 'score', 'delta', 'trend', 'direction', 'arrow', 'sparkline', 'abnormal', 'normal', 'range', 'percentile', 'zscore'];
    $assertClean = function (array $data, string $where) use (&$assertClean, $forbidden): void {
        foreach ($data as $key => $value) {
            expect(in_array(strtolower((string) $key), $forbidden, true))
                ->toBeFalse("interpretation key '{$key}' leaked into {$where}");
            if (is_array($value)) {
                $assertClean($value, $where);
            }
        }
    };
    // The scan must have something to walk, or it proves nothing.
    expect($props['vitals'])->toHaveCount(2);
    $assertClean((array) ($props['vitals'] ?? []), 'the vitals payload');

    // A frankly abnormal reading is carried RAW: recorded numbers, and no word about them.
    $worst = collect($props['vitals'])->firstWhere('systolic', 176);
    expect($worst)->not->toBeNull()
        ->and($worst['diastolic'])->toBe(104)
        ->and($worst['spo2'])->toBe(91)
        ->and(array_keys($worst))->toBe(['id', 'recorded_at', 'systolic', 'diastolic', 'heart_rate', 'temperature_c', 'spo2', 'weight_g', 'height_mm', 'extra']);
    $assertClean((array) ($props['vitalsHistory'] ?? []), 'the vitals history');

    // No risk/acuity/EWS/prognosis/interaction anywhere in the chart payload.
    $encoded = strtolower(json_encode($props, JSON_THROW_ON_ERROR));
    foreach (['riskscore', 'acuity', 'ewsscore', 'newsscore', 'prognosis', 'crossreact', 'contraindication', 'autoproblem'] as $token) {
        expect(str_contains(preg_replace('~[^a-z0-9]~', '', $encoded) ?? '', $token))
            ->toBeFalse("fence token '{$token}' appears in the chart payload");
    }
});

test('THE RE-ASSERTION: the chart page never styles a vital by its value and draws nothing', function () {
    $code = cpStrip((string) file_get_contents(base_path('resources/js/pages/Clinical/Chart.vue')));

    // No sparkline, no chart library, no drawing on the page (D-172).
    foreach (['sparkline', '<canvas', 'getcontext', 'chart.js', 'apexchart', 'd3.'] as $drawing) {
        expect(str_contains($code, $drawing))->toBeFalse("the chart draws a vitals trend: '{$drawing}'");
    }

    // No class/style binding keyed to a vital value or a numeric threshold (D-169).
    preg_match_all('~:(?:class|style)="([^"]*)"~', $code, $bindings);
    foreach ($bindings[1] ?? [] as $binding) {
        foreach (['systolic', 'diastolic', 'heart_rate', 'spo2', 'temperature', 'vital'] as $needle) {
            expect(str_contains($binding, $needle))->toBeFalse("the chart styles from a vital: {$binding}");
        }
        expect(preg_match('~[<>]=?\s*\d~', $binding))->toBe(0, "the chart styles by a threshold: {$binding}");
    }

    // The find-in-chart filter is a plain text match: it must not rank or score.
    expect($code)->toContain('includes(needle)');
    foreach (['relevance', 'score(', '.sort((a, b) => b.', 'fuzzy', 'levenshtein'] as $ranking) {
        expect(str_contains($code, $ranking))->toBeFalse("find-in-chart ranks results: '{$ranking}'");
    }
});
