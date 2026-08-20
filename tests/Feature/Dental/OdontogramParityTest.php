<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Dental\Models\ToothRecord;
use Modules\Dental\Services\ToothChartService;
use Modules\Dental\Support\ToothNotation;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * DENTAL-B.P2 — Odontogram visual parity over the EXISTING tooth-chart backend.
 *
 * The gate adds a read/chart mode toggle, a per-tooth detail rail, a US-notation
 * cross-reference and chart-key polish. Nothing clinical was computed and no per-tooth field
 * was invented: the rail shows the rows that are actually in tooth_records.
 *
 * These tests prove the three things that could go wrong:
 *   1. the rail FABRICATES something (it must equal the recorded rows, and be honestly empty
 *      when there are none);
 *   2. the notation cross-reference is anything other than a deterministic lookup;
 *   3. read mode is mistaken for a permission (it is a UI mode — the server's gate is
 *      unchanged, and the client never sends a mode the server honours).
 *
 * Plus THE RE-ASSERTION: DMFT/dmft, the finding count and the "site to watch" flag were ruled
 * a CORRECT divergence (DENTAL-BATCH-DIFF.md §5.1/§6) and must STAY absent — from the payload
 * and from the components. Existing behaviour tests are NOT touched by this gate.
 */

function opCtx(): TenantContext
{
    return app(TenantContext::class);
}

function opUser(Tenant $tenant, string $role, string $name = 'Dr Luca Ferrari'): User
{
    opCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create(['name' => $name]);
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, doctor: User, patient: Patient}
 */
function opFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Dental', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    opCtx()->set($tenant);
    $doctor = opUser($tenant, 'doctor');
    $patient = app(PatientService::class)->create(['first_name' => 'Anna', 'last_name' => 'Vogel', 'date_of_birth' => '1979-05-22', 'sex' => 'female']);

    return compact('tenant', 'doctor', 'patient');
}

test('the per-tooth rail shows the REAL recorded rows — current state and the full correction trail, nothing fabricated', function () {
    $fx = opFixture();
    $charts = app(ToothChartService::class);

    // A real correction on tooth 16: caries, then restoration with a reason. Plus an
    // unrelated whole-tooth record so the rail has to pick the right rows.
    $charts->chart($fx['doctor'], $fx['patient'], '16', 'occlusal', 'caries', 'Kariöse Läsion.');
    $charts->chart($fx['doctor'], $fx['patient'], '16', 'occlusal', 'restoration', 'Kompositfüllung gelegt.', 'Nachtrag nach Behandlung.');
    $charts->chart($fx['doctor'], $fx['patient'], '26', null, 'crown');

    $recorded = ToothRecord::query()->where('patient_id', $fx['patient']->id)->get();
    expect($recorded)->toHaveCount(3);

    opCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get(route('dental.chart', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dental/Odontogram')
            // CURRENT = latest per (tooth, surface): the restoration supersedes the caries.
            ->where('chart', function ($chart) {
                $chart = collect($chart);
                expect($chart)->toHaveCount(2);
                $sixteen = $chart->firstWhere('tooth', '16');
                expect($sixteen['condition'])->toBe('restoration')
                    ->and($sixteen['surface'])->toBe('occlusal');

                return true;
            })
            // HISTORY = every recorded row, and the rail's fields are the RECORDED ones.
            ->where('history', function ($history) use ($recorded) {
                $history = collect($history);
                expect($history)->toHaveCount($recorded->count());

                // Every id in the payload is a real row — nothing invented.
                expect($history->pluck('id')->sort()->values()->all())
                    ->toBe($recorded->pluck('id')->sort()->values()->all());

                $trail = $history->where('tooth', '16')->values();
                expect($trail)->toHaveCount(2);
                expect($trail->pluck('condition')->sort()->values()->all())->toBe(['caries', 'restoration']);

                // The correction carries the clinician's OWN reason and note, verbatim.
                $correction = $trail->firstWhere('condition', 'restoration');
                expect($correction['reason'])->toBe('Nachtrag nach Behandlung.')
                    ->and($correction['note'])->toBe('Kompositfüllung gelegt.')
                    // charted_by is resolved to a name for DISPLAY — it is the real charter.
                    ->and($correction['charted_by_name'])->toBe('Dr Luca Ferrari');

                return true;
            }));
});

test('the rail is honestly empty for a tooth with no records — no placeholder row is invented', function () {
    $fx = opFixture();
    app(ToothChartService::class)->chart($fx['doctor'], $fx['patient'], '16', 'occlusal', 'caries');

    opCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get(route('dental.chart', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Tooth 47 was never charted: it appears in NEITHER the current chart nor history.
            ->where('chart', fn ($chart) => collect($chart)->where('tooth', '47')->isEmpty())
            ->where('history', fn ($history) => collect($history)->where('tooth', '47')->isEmpty())
            // The payload carries exactly the one real row — no empty scaffold per tooth.
            ->has('chart', 1)
            ->has('history', 1));
});

test('the US-notation cross-reference is a deterministic lookup over the whole FDI universe', function () {
    // Known anchors of the Universal system: permanent 1-32 walking from the upper-right
    // third molar; primary A-T on the same walk.
    $pairs = [
        '18' => '1',  '11' => '8',  '21' => '9',  '28' => '16',
        '38' => '17', '31' => '24', '41' => '25', '48' => '32',
        '55' => 'A',  '51' => 'E',  '61' => 'F',  '65' => 'J',
        '75' => 'K',  '71' => 'O',  '81' => 'P',  '85' => 'T',
    ];
    foreach ($pairs as $fdi => $universal) {
        expect(ToothNotation::universal($fdi))->toBe($universal, "FDI {$fdi} should map to US {$universal}");
    }

    // Total over the valid universe, and a BIJECTION — no two teeth share a US name.
    $map = ToothNotation::universalMap();
    expect($map)->toHaveCount(count(ToothNotation::all()))
        ->and(count(array_unique($map)))->toBe(count($map));

    // Deterministic: the same input always gives the same output, and invalid ids give null.
    expect(ToothNotation::universal('16'))->toBe(ToothNotation::universal('16'))
        ->and(ToothNotation::universal('99'))->toBeNull()
        ->and(ToothNotation::universal('abc'))->toBeNull();

    // The page gets the map from the DOMAIN, so the component maps nothing itself.
    $fx = opFixture();
    opCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get(route('dental.chart', $fx['patient']->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('universal.18', '1')
            ->where('universal.85', 'T'));
});

test('read mode is a UI mode, NOT a permission — the server authorises writes exactly as before', function () {
    $fx = opFixture();

    // The payload exposes no server-side mode. Charting capability is still the SAME
    // `actions.can_chart` gate the page has always used.
    opCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get(route('dental.chart', $fx['patient']->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('actions.can_chart', true)
            ->missing('mode')
            ->missing('actions.mode')
            ->missing('actions.read_only'));

    // A client claiming to be "in read mode" changes NOTHING: the parameter is not a server
    // concept, so a permitted write still succeeds.
    opCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->post(route('dental.chart.store', $fx['patient']->id), ['tooth' => '11', 'condition' => 'present', 'mode' => 'read'])
        ->assertRedirect();
    expect(ToothRecord::query()->where('patient_id', $fx['patient']->id)->count())->toBe(1);

    // And a client claiming "chart mode" gains NOTHING: reception still holds patient.view
    // without dental.chart, and is refused by the same gate as always.
    $reception = opUser($fx['tenant'], 'reception', 'Nadia Brun');
    opCtx()->forget();
    $this->actingAs($reception)->get(route('dental.chart', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('actions.can_chart', false));
    opCtx()->forget();
    $this->actingAs($reception)
        ->post(route('dental.chart.store', $fx['patient']->id), ['tooth' => '12', 'condition' => 'present', 'mode' => 'chart'])
        ->assertForbidden();
    expect(ToothRecord::query()->where('patient_id', $fx['patient']->id)->count())->toBe(1);
});

test('THE RE-ASSERTION: no DMFT, finding count, site-to-watch flag, index, trend or AI key in the Odontogram payload', function () {
    $fx = opFixture();
    $charts = app(ToothChartService::class);
    $charts->chart($fx['doctor'], $fx['patient'], '16', 'occlusal', 'caries');
    $charts->chart($fx['doctor'], $fx['patient'], '36', null, 'missing');
    $charts->chart($fx['doctor'], $fx['patient'], '47', 'mesial', 'caries');

    /*
     * The wireframe recomputes a DMFT/dmft caries index live, shows a "1 finding" count and a
     * "Flagged · one site to watch" chip. All three were ruled a CORRECT divergence — the live
     * app omits them deliberately. This gate is visual parity, so it is exactly the moment
     * someone might "finish the design" by adding them back.
     */
    $forbidden = [
        'dmft', 'dmfs', 'decayed', 'missing_teeth', 'filled', 'caries_index', 'index',
        'finding', 'findings', 'flag', 'flagged', 'watch', 'severity', 'score', 'grade',
        'risk', 'trend', 'abnormal', 'priority', 'recommendation', 'interpretation',
        'diagnosis', 'verdict', 'ai', 'confidence', 'detected', 'analysis',
    ];

    $assertClean = function (array $data, string $where) use (&$assertClean, $forbidden): void {
        foreach ($data as $key => $value) {
            expect(in_array(strtolower((string) $key), $forbidden, true))
                ->toBeFalse("judgment key '{$key}' leaked into {$where}");
            if (is_array($value)) {
                $assertClean($value, $where);
            }
        }
    };

    opCtx()->forget();
    $response = $this->actingAs($fx['doctor'])->get(route('dental.chart', $fx['patient']->id))->assertOk();

    $props = $response->viewData('page')['props'];
    $assertClean($props, 'the odontogram payload');

    // Three caries/missing records exist, but NO count of them is computed anywhere.
    expect(ToothRecord::query()->where('patient_id', $fx['patient']->id)->count())->toBe(3);
    $encoded = strtolower(json_encode($props, JSON_THROW_ON_ERROR));
    foreach (['dmft', 'site to watch', 'caries index'] as $phrase) {
        expect(str_contains($encoded, $phrase))->toBeFalse("'{$phrase}' appears in the odontogram payload");
    }
});

test('THE RE-ASSERTION: the page and the arch widget contain no index, finding count or flag affordance', function () {
    $strip = function (string $source): string {
        $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;
        $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;

        return strtolower(preg_replace('~^\s*//.*$~m', ' ', $source) ?? $source);
    };

    $sources = [
        'Odontogram.vue' => $strip((string) file_get_contents(base_path('resources/js/pages/Dental/Odontogram.vue'))),
        'ToothArch.vue' => $strip((string) file_get_contents(base_path('resources/js/Components/Dental/ToothArch.vue'))),
    ];

    foreach ($sources as $name => $code) {
        foreach (['dmft', 'dmfs', 'caries index', 'site to watch', 'finding', 'flagged', 'severity', 'trend', 'confidence'] as $token) {
            expect(str_contains($code, $token))->toBeFalse("'{$token}' appears in {$name}");
        }

        /*
         * The same phrases again, against a source with all non-alphanumerics stripped.
         * A plain scan reads "site to watch" but MISSES `siteToWatch` — which is exactly how
         * an identifier would be spelled in code. Normalising collapses both to the same
         * string, so the compound §5.1 phrases cannot be smuggled in as camelCase.
         *
         * Only COMPOUND phrases go through this pass. Vue's own `watch`/`watchEffect` is a
         * legitimate reactive primitive, so bare "watch" is deliberately NOT banned — banning
         * a framework API teaches the next author to weaken the scan instead of respecting it.
         */
        $squashed = preg_replace('~[^a-z0-9]~', '', $code) ?? $code;
        foreach (['sitetowatch', 'cariesindex', 'dmftindex', 'findingcount', 'severityramp', 'severityscale', 'trendarrow', 'watchlist'] as $compound) {
            expect(str_contains($squashed, $compound))->toBeFalse("'{$compound}' appears in {$name}");
        }

        // No severity ramp: colour comes from the shared categorical vocabulary only.
        expect(str_contains($code, 'linear-gradient'))->toBeFalse("a gradient appears in {$name}");
    }

    // §6 preserved: the on-screen note still says colour is not severity, verbatim.
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    expect($en['dental']['legend']['note'])
        ->toContain('not its severity')
        ->toContain('scored, graded, or flagged');
});
