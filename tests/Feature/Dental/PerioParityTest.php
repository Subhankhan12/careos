<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Dental\Models\PerioExam;
use Modules\Dental\Models\PerioMeasurement;
use Modules\Dental\Services\PerioChartService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * DENTAL-B.P3 — Perio grid ergonomics + raw values over time.
 *
 * Perio is the most computed screen in the dental pack after Endo: the mock's ENTIRE right rail
 * is judgment — BOP %, "sites ≥ 4 mm", mean pocket depth, a plaque score, trend arrows
 * ("▼ from 3.1", "plateau"), "one site to watch", a severity colour band keyed to depth, and
 * "bone loss confirmed on today's bitewing" (an AI imaging finding). None of it may be built.
 *
 * This gate changed LAYOUT and ENTRY ERGONOMICS only. So these tests prove:
 *   1. recording still writes the same raw rows through the same path (behaviour unchanged);
 *   2. a prior reading is offered to the grid as a RAW NUMBER with no delta or direction;
 *   3. the whole computed rail is ABSENT from the payload and the components — re-asserted
 *      with the compound-phrase scan the P2 mutation exposed as necessary.
 *
 * These EXTEND the DENTAL.G6/D-104 assertion in PerioChartTest.php (which is not modified);
 * that file's forbidden-key list stops at stage/grade/severity/trend/watch, and this one adds
 * the arithmetic vocabulary — bop percentage, index, mean, average, count, band, delta.
 */

function pgdCtx(): TenantContext
{
    return app(TenantContext::class);
}

function pgdUser(Tenant $tenant, string $role): User
{
    pgdCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create(['name' => 'Dr Luca Ferrari']);
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, doctor: User, patient: Patient}
 */
function pgdFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Dental', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    pgdCtx()->set($tenant);
    $doctor = pgdUser($tenant, 'doctor');
    $patient = app(PatientService::class)->create(['first_name' => 'Anna', 'last_name' => 'Vogel', 'date_of_birth' => '1979-05-22', 'sex' => 'female']);

    return compact('tenant', 'doctor', 'patient');
}

/**
 * The six raw rows for one tooth.
 *
 * @param  array<int, int>  $depths
 * @return array<int, array<string, mixed>>
 */
function pgdSites(string $tooth, array $depths, bool $bleeding = false): array
{
    $rows = [];
    foreach (PerioMeasurement::SITES as $i => $site) {
        $rows[] = [
            'tooth' => $tooth,
            'site' => $site,
            'pocket_depth_mm' => $depths[$i],
            'recession_mm' => 0,
            'bleeding_on_probing' => $bleeding,
        ];
    }

    return $rows;
}

test('the grid records the SAME raw rows through the existing path — recording behaviour is unchanged', function () {
    $fx = pgdFixture();

    // Exactly the payload shape the grid POSTs: one flat row per (tooth, site).
    $measurements = [...pgdSites('16', [3, 3, 4, 3, 2, 3]), ...pgdSites('26', [2, 2, 3, 2, 2, 2], true)];

    pgdCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->post(route('dental.perio.store', $fx['patient']->id), [
            'exam_date' => '2026-08-18',
            'note' => 'SPT recall.',
            'measurements' => $measurements,
        ])
        ->assertRedirect(route('dental.perio', $fx['patient']->id));

    pgdCtx()->set($fx['tenant']);
    $exam = PerioExam::query()->where('patient_id', $fx['patient']->id)->firstOrFail();
    expect($exam->measurements)->toHaveCount(12)
        ->and($exam->exam_date->toDateString())->toBe('2026-08-18')
        ->and($exam->note)->toBe('SPT recall.');

    // The RAW values landed verbatim — the grid summarised nothing on the way out.
    $probed = $exam->measurements->firstWhere(fn (PerioMeasurement $m) => $m->tooth === '16' && $m->site === 'disto_buccal');
    expect($probed->pocket_depth_mm)->toBe(4)
        ->and($probed->recession_mm)->toBe(0)
        ->and($probed->bleeding_on_probing)->toBeFalse();

    $bleeding = $exam->measurements->firstWhere(fn (PerioMeasurement $m) => $m->tooth === '26' && $m->site === 'buccal');
    expect($bleeding->bleeding_on_probing)->toBeTrue()
        ->and($bleeding->pocket_depth_mm)->toBe(2);

    // Every one of the six domain sites was recorded for tooth 16 — no site was dropped.
    expect($exam->measurements->where('tooth', '16')->pluck('site')->sort()->values()->all())
        ->toBe(collect(PerioMeasurement::SITES)->sort()->values()->all());
});

test('the previous exam is offered as RAW recorded numbers — no delta, no direction, no label', function () {
    $fx = pgdFixture();
    $perio = app(PerioChartService::class);

    // A site that genuinely deepened: 16 disto-buccal 4mm → 6mm. This is precisely the site the
    // wireframe would flag as "one site to watch" with a "▲ from 4" arrow.
    $perio->recordExam($fx['doctor'], $fx['patient'], '2026-07-10', pgdSites('16', [3, 3, 4, 3, 2, 3]));
    $perio->recordExam($fx['doctor'], $fx['patient'], '2026-08-18', pgdSites('16', [3, 4, 6, 3, 2, 3], true));

    pgdCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get(route('dental.perio', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dental/PerioChart')
            // The previous reading is the RECORDED NUMBER from the most recent exam.
            ->where('previous.exam_date', '2026-08-18')
            ->where('previous.pocket_depth_mm.16.disto_buccal', 6)
            ->where('previous.pocket_depth_mm.16.buccal', 4)
            // ...and NOTHING derived from it. No delta, no direction, no characterisation.
            ->where('previous', function ($previous) {
                // Decode rather than cast — Inertia hands back a Collection, and a plain
                // (array) cast would expose its internals instead of the payload's keys.
                $encoded = json_encode($previous, JSON_THROW_ON_ERROR);
                $decoded = json_decode($encoded, true);
                expect(array_keys($decoded))->toBe(['exam_date', 'pocket_depth_mm']);

                $encoded = strtolower($encoded);
                foreach (['delta', 'change', 'direction', 'from', 'trend', 'arrow', 'deepen', 'improv', 'worsen', 'plateau'] as $judgment) {
                    expect(str_contains($encoded, $judgment))->toBeFalse("'{$judgment}' appears in the previous-readings payload");
                }

                return true;
            }));
});

test('THE RE-ASSERTION: the perio payload carries no BOP %, index, mean, count, band, trend or finding', function () {
    $fx = pgdFixture();
    $perio = app(PerioChartService::class);
    $perio->recordExam($fx['doctor'], $fx['patient'], '2026-07-10', pgdSites('16', [3, 3, 4, 3, 2, 3]));
    $perio->recordExam($fx['doctor'], $fx['patient'], '2026-08-18', pgdSites('16', [3, 4, 6, 3, 2, 3], true));

    /*
     * The arithmetic vocabulary, on top of the DENTAL.G6/D-104 list. Every entry names one of
     * the mock's right-rail figures.
     */
    $forbidden = [
        'bop', 'bop_percent', 'bleeding_percent', 'percent', 'percentage', 'index', 'mean',
        'average', 'avg', 'median', 'total', 'count', 'sum', 'band', 'bands', 'delta', 'change',
        'direction', 'arrow', 'plaque_score', 'sites_over', 'deep_sites', 'watch', 'trend',
        'severity', 'grade', 'stage', 'finding', 'findings', 'confidence', 'detected',
    ];

    $assertClean = function (array $data, string $path = '') use (&$assertClean, $forbidden): void {
        foreach ($data as $key => $value) {
            expect(in_array(strtolower((string) $key), $forbidden, true))
                ->toBeFalse("judgment key '{$key}' leaked into the perio payload at '{$path}'");
            if (is_array($value)) {
                $assertClean($value, $path.'/'.$key);
            }
        }
    };

    pgdCtx()->forget();
    $response = $this->actingAs($fx['doctor'])->get(route('dental.perio', $fx['patient']->id))->assertOk();
    $props = $response->viewData('page')['props'];

    $assertClean($props);

    // Two exams with a genuinely deepened site exist, yet NOTHING is computed over them.
    pgdCtx()->set($fx['tenant']);
    expect(PerioExam::query()->where('patient_id', $fx['patient']->id)->count())->toBe(2);

    $encoded = strtolower(json_encode($props, JSON_THROW_ON_ERROR));
    foreach (['site to watch', 'bleeding on probing %', 'mean pocket', 'bone loss'] as $phrase) {
        expect(str_contains($encoded, $phrase))->toBeFalse("'{$phrase}' appears in the perio payload");
    }
});

test('THE RE-ASSERTION: the perio page and grid component offer no computed-rail affordance', function () {
    $strip = function (string $source): string {
        $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;
        $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;

        return strtolower(preg_replace('~^\s*//.*$~m', ' ', $source) ?? $source);
    };

    $sources = [
        'PerioChart.vue' => $strip((string) file_get_contents(base_path('resources/js/pages/Dental/PerioChart.vue'))),
        'PerioSiteGrid.vue' => $strip((string) file_get_contents(base_path('resources/js/Components/Dental/PerioSiteGrid.vue'))),
    ];

    foreach ($sources as $name => $code) {
        // Word-boundary pass: the single-word judgments.
        foreach (['mean', 'average', 'avg', 'median', 'percent', 'percentage', 'severity', 'stage', 'grade', 'trend', 'delta', 'band', 'plateau', 'worsening', 'improving', 'finding', 'findings', 'confidence'] as $token) {
            expect(preg_match('~\b'.preg_quote($token, '~').'\b~', $code))
                ->toBe(0, "judgment affordance '{$token}' appears in {$name}");
        }

        // Compound pass over a non-alphanumeric-stripped copy — a camelCase `siteToWatch` or
        // `bopPercent` slips a word-boundary scan entirely (the P2 mutation proved it).
        $squashed = preg_replace('~[^a-z0-9]~', '', $code) ?? $code;
        foreach ([
            'sitetowatch', 'boppercent', 'bleedingpercent', 'meandepth', 'meanpocket', 'pocketmean',
            'plaquescore', 'sitesover', 'deepsites', 'severityband', 'severityramp', 'colourband',
            'colorband', 'trendarrow', 'boneloss', 'depthband',
        ] as $compound) {
            expect(str_contains($squashed, $compound))->toBeFalse("compound judgment phrase '{$compound}' appears in {$name}");
        }

        expect(str_contains($code, 'linear-gradient'))->toBeFalse("a gradient appears in {$name}");

        /*
         * NO SEVERITY RAMP KEYED TO DEPTH — the hardest thing here to assert, because a ramp
         * needs no judgment WORD at all. A mutation of the form
         *
         *     function cellTint(mm) { return mm >= 6 ? 'bg-danger' : mm >= 4 ? 'bg-warning' : '' }
         *
         * passed every lexical check above: no banned token, and a threshold regex keyed to
         * `pocket_depth_mm` misses a parameter simply named `mm`. So the rule is expressed
         * where the breach must surface instead — in the STYLING.
         *
         * No class or style BINDING may reference a measurement or compare against a number.
         * Whatever a ramp is called, it has to reach the cell through one of these.
         */
        preg_match_all('~:(?:class|style)="([^"]*)"~', $code, $bindings);
        foreach ($bindings[1] ?? [] as $binding) {
            foreach (['depth', '_mm', 'tint', 'band', 'colour', 'color', 'shade', 'heat'] as $needle) {
                expect(str_contains($binding, $needle))
                    ->toBeFalse("{$name} styles a cell from a measurement: {$binding}");
            }
            expect(preg_match('~[<>]=?\s*\d~', $binding))
                ->toBe(0, "{$name} styles a cell by comparing against a threshold: {$binding}");
        }
    }

    /*
     * And in the GRID itself — the component that draws the cells — the severity tone classes
     * are banned outright. Every cell is rendered identically whatever number it holds, so a
     * danger/warning/success tone in this file could only ever be a ramp. (The page keeps them:
     * its flash message is legitimately a success tone, which is why this rule is scoped to the
     * grid rather than applied to both.)
     */
    $grid = $sources['PerioSiteGrid.vue'];
    foreach (['danger', 'warning', 'success', 'critical', 'alarm'] as $tone) {
        foreach (['bg-', 'text-', 'border-', 'ring-', 'fill-'] as $prefix) {
            expect(str_contains($grid, $prefix.$tone))
                ->toBeFalse("the perio grid tints a cell with '{$prefix}{$tone}' — that is a severity ramp");
        }
    }

    // The existing on-screen fence note is still there and still says what it said.
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    expect($en['perio']['fenceNote'])->toBeString()->not->toBe('');
});
