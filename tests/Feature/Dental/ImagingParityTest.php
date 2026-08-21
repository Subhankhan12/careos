<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Dental\Models\DentalImage;
use Modules\Dental\Services\DentalImagingService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * DENTAL-B.P6 — Scan Library + Upload visual parity over the 2D backend.
 *
 * The sharpest fence in the batch. The wireframe's imaging intelligence is CADe/CADx — a
 * REGULATED MEDICAL DEVICE: AI radiograph findings, "bone loss confirmed on today's bitewing",
 * scan analysis, per-tooth coverage flagging, "beyond 0.5 mm" deviation. None of it exists here
 * and none of it may be added. 3D/mesh capture, superimposition and ortho overlays (B5) are
 * partner-gated and are NOT built.
 *
 * What the 2D backend really holds: a DentalImage is metadata over a file in the existing
 * clinical document storage — image_type (one of five plain labels), an optional FDI tooth, an
 * optional free-text region, captured_at and uploaded_by; plus append-only DentalImageReading
 * rows carrying the DENTIST'S OWN written interpretation. Both models are immutable.
 *
 * These tests EXTEND the existing DentalImagingTest (not modified): that file asserts the
 * payload keys, and this one adds the library/upload/viewer surface and the compound-phrase and
 * styling scans the later gates introduced.
 */

function dipCtx(): TenantContext
{
    return app(TenantContext::class);
}

function dipUser(Tenant $tenant, string $role, string $name = 'Dr Sabine Morgenstern'): User
{
    dipCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create(['name' => $name]);
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, dentist: User, patient: Patient}
 */
function dipFixture(string $slug = 'alpha'): array
{
    Storage::fake('local');
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Dental', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    dipCtx()->set($tenant);
    $dentist = dipUser($tenant, 'doctor');
    $patient = app(PatientService::class)->create(['first_name' => 'Anna', 'last_name' => 'Vogel', 'date_of_birth' => '1979-05-22', 'sex' => 'female']);

    return compact('tenant', 'dentist', 'patient');
}

/** Strip comments so the scans test AFFORDANCES, not the prose that documents their absence. */
function dipStrip(string $source): string
{
    $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;
    $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;

    return strtolower(preg_replace('~(^|\s)//[^\n]*~m', '$1 ', $source) ?? $source);
}

test('the library renders the REAL stored images with their real metadata, and nothing invented', function () {
    $fx = dipFixture();
    $imaging = app(DentalImagingService::class);

    $imaging->upload($fx['dentist'], $fx['patient'], UploadedFile::fake()->image('bitewing-16.jpg', 800, 600), 'bitewing', '16', null);
    $imaging->upload($fx['dentist'], $fx['patient'], UploadedFile::fake()->image('opg.jpg', 1200, 600), 'panoramic', null, 'Oberkiefer');

    dipCtx()->set($fx['tenant']);
    $stored = DentalImage::query()->where('patient_id', $fx['patient']->id)->with('document')->get();
    expect($stored)->toHaveCount(2);
    $bitewing = $stored->firstWhere('image_type', 'bitewing');
    $imaging->recordReading($fx['dentist'], $bitewing, 'Approximale Aufhellung distal an 16.', null);

    dipCtx()->forget();
    $this->actingAs($fx['dentist'])
        ->get(route('dental.imaging', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dental/Imaging')
            ->has('images', 2)
            ->where('images', function ($images) use ($stored) {
                $images = collect($images);

                // Every rendered id is a real stored row — nothing fabricated.
                expect($images->pluck('id')->sort()->values()->all())
                    ->toBe($stored->pluck('id')->sort()->values()->all());

                $bw = $images->firstWhere('image_type', 'bitewing');
                expect($bw['tooth'])->toBe('16')
                    ->and($bw['region'])->toBeNull()
                    // Real document facts, not guesses about the picture.
                    ->and($bw['original_filename'])->toBe('bitewing-16.jpg')
                    ->and($bw['size_bytes'])->toBeInt()
                    ->and($bw['mime_type'])->toContain('image/')
                    // The recorded capturer, resolved for display only.
                    ->and($bw['uploaded_by_name'])->toBe('Dr Sabine Morgenstern');

                // The DENTIST'S OWN reading, verbatim, attributed to them.
                expect($bw['readings'])->toHaveCount(1)
                    ->and($bw['readings'][0]['reading'])->toBe('Approximale Aufhellung distal an 16.')
                    ->and($bw['readings'][0]['read_by_name'])->toBe('Dr Sabine Morgenstern');

                // The panoramic carries its real region and no tooth — not a placeholder.
                $opg = $images->firstWhere('image_type', 'panoramic');
                expect($opg['region'])->toBe('Oberkiefer')
                    ->and($opg['tooth'])->toBeNull()
                    ->and($opg['readings'])->toBe([]);

                return true;
            }));
});

test('a patient with no images gets an honest empty library — no placeholder rows', function () {
    $fx = dipFixture();

    dipCtx()->forget();
    $this->actingAs($fx['dentist'])
        ->get(route('dental.imaging', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('images', []));
});

test('upload goes through the UNCHANGED endpoint and its real validation still applies', function () {
    $fx = dipFixture();

    // The real accepted shape: jpg/png, a known type, an optional FDI tooth and region.
    dipCtx()->forget();
    $this->actingAs($fx['dentist'])
        ->post(route('dental.imaging.store', $fx['patient']->id), [
            'file' => UploadedFile::fake()->image('pa-26.jpg'),
            'image_type' => 'periapical',
            'tooth' => '26',
            'region' => '',
            // A field the endpoint does not accept. It must be IGNORED, never stored —
            // the page cannot smuggle a finding in through the upload form.
            'ai_finding' => 'periapical radiolucency, 4mm',
        ])
        ->assertRedirect(route('dental.imaging', $fx['patient']->id));

    dipCtx()->set($fx['tenant']);
    $image = DentalImage::query()->where('patient_id', $fx['patient']->id)->firstOrFail();
    expect($image->image_type)->toBe('periapical')
        ->and($image->tooth)->toBe('26')
        ->and($image->region)->toBeNull()
        ->and($image->getAttributes())->not->toHaveKey('ai_finding');
    // The forged value reached nothing at all.
    expect(json_encode($image->toArray(), JSON_THROW_ON_ERROR))->not->toContain('radiolucency');

    // A disallowed file type is still refused by the SAME validation as before.
    dipCtx()->forget();
    $this->actingAs($fx['dentist'])
        ->post(route('dental.imaging.store', $fx['patient']->id), [
            'file' => UploadedFile::fake()->create('scan.stl', 200, 'application/sla'),
            'image_type' => 'scan',
        ])
        ->assertSessionHasErrors('file');

    // An unknown image type is refused by the model's deterministic check.
    dipCtx()->forget();
    $this->actingAs($fx['dentist'])
        ->post(route('dental.imaging.store', $fx['patient']->id), [
            'file' => UploadedFile::fake()->image('x.jpg'),
            'image_type' => 'cbct',
        ])
        ->assertSessionHasErrors('file');

    dipCtx()->set($fx['tenant']);
    expect(DentalImage::query()->where('patient_id', $fx['patient']->id)->count())->toBe(1);
});

test('the viewer is OPTICS ONLY — zoom and pan, with no system-generated mark or overlay', function () {
    $code = dipStrip((string) file_get_contents(base_path('resources/js/pages/Dental/Imaging.vue')));

    // Zoom and pan exist: they change what you see, not what is recorded.
    foreach (['zoomin', 'zoomout', 'startpan', 'movepan'] as $optic) {
        expect(str_contains(str_replace(['_', '-'], '', $code), $optic))->toBeTrue("the viewer lost its {$optic} control");
    }

    // Nothing may DRAW on the image: no canvas, no SVG overlay, no marker/box/region layer.
    foreach (['<canvas', 'getcontext', 'fillrect', 'strokerect', 'drawimage', '<svg', 'marker', 'boundingbox', 'heatmap'] as $drawing) {
        expect(str_contains($code, $drawing))->toBeFalse("the viewer draws on the image: '{$drawing}'");
    }
});

test('THE RE-ASSERTION: no AI finding, detection, confidence, analysis, coverage/quality or comparison anywhere', function () {
    $fx = dipFixture();
    $imaging = app(DentalImagingService::class);
    $imaging->upload($fx['dentist'], $fx['patient'], UploadedFile::fake()->image('bw.jpg'), 'bitewing', '16', null);

    $forbidden = [
        'ai', 'finding', 'findings', 'detect', 'detected', 'detection', 'confidence', 'analysis',
        'analyzed', 'analysed', 'predicted', 'prediction', 'auto', 'suggested', 'recommendation',
        'pathology', 'diagnosis', 'severity', 'grade', 'score', 'flag', 'flagged', 'overlay',
        'coverage', 'quality', 'superimposition', 'superimposed', 'deviation', 'comparison',
    ];

    $assertClean = function (array $data) use (&$assertClean, $forbidden): void {
        foreach ($data as $key => $value) {
            expect(in_array(strtolower((string) $key), $forbidden, true))
                ->toBeFalse("analysis key '{$key}' leaked into the imaging payload");
            if (is_array($value)) {
                $assertClean($value);
            }
        }
    };

    dipCtx()->forget();
    $response = $this->actingAs($fx['dentist'])->get(route('dental.imaging', $fx['patient']->id))->assertOk();
    $assertClean($response->viewData('page')['props']);

    // And the component itself offers no such affordance.
    $code = dipStrip((string) file_get_contents(base_path('resources/js/pages/Dental/Imaging.vue')));
    foreach (['confidence', 'coverage', 'superimpose', 'superimposition', 'deviation', 'heatmap', 'detected'] as $token) {
        expect(preg_match('~\b'.preg_quote($token, '~').'\b~', $code))->toBe(0, "'{$token}' appears in Imaging.vue");
    }

    /*
     * The compound pass over a non-alphanumeric-stripped copy — a camelCase `aiFinding` or
     * `coverageScore` slips a word-boundary scan entirely (the P2 mutation proved it).
     */
    $squashed = preg_replace('~[^a-z0-9]~', '', $code) ?? $code;
    foreach ([
        'aifinding', 'aifindings', 'autodetect', 'autodetected', 'detectedcaries', 'boneloss',
        'coveragescore', 'coverageflag', 'qualityscore', 'qualityflag', 'imageanalysis',
        'comparescans', 'comparisonoverlay', 'scandiff', 'planvsactual',
    ] as $compound) {
        expect(str_contains($squashed, $compound))->toBeFalse("compound phrase '{$compound}' appears in Imaging.vue");
    }

    /*
     * D-169: a ramp needs no judgment word. No class or style binding may be keyed to a value
     * or to a numeric comparison — tinting a scan by a computed "quality" is the same breach as
     * a severity ramp. Bindings on `selectedId`, `panning` and `zoom` are UI state, not verdicts.
     */
    preg_match_all('~:(?:class|style)="([^"]*)"~', $code, $bindings);
    foreach ($bindings[1] ?? [] as $binding) {
        foreach (['quality', 'coverage', 'confidence', 'severity', 'finding', 'tint', 'band', 'score'] as $needle) {
            expect(str_contains($binding, $needle))->toBeFalse("Imaging.vue styles from a verdict: {$binding}");
        }
        expect(preg_match('~[<>]=?\s*\d~', $binding))->toBe(0, "Imaging.vue styles by a threshold: {$binding}");
    }
});
