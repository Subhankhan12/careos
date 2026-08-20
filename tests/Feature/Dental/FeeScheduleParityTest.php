<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Dental\Services\DentalCatalogService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * DENTAL-B.P5 — Fee Schedule visual parity.
 *
 * The line here is LICENSING, not clinical. The catalog is TENANT-AUTHORED: the practice enters
 * its own codes, descriptions and fees, and DentalCatalogService computes no prices. The
 * wireframe is drawn on a Swiss tax-point tariff (positions priced as tax points x a
 * Taxpunktwert) with effective-dated versions and a version diff — that pricing data is
 * LICENSED, and none of it is bundled, seeded or hardcoded here.
 *
 * These tests pin:
 *   1. the view renders the tenant's REAL authored rows;
 *   2. NO licensed code set is present anywhere in the shipped repo (the structural assertion);
 *   3. fees are DISPLAYED from the catalog with no page-side arithmetic;
 *   4. the authoring path and its billing.manage gate are unchanged;
 *   5. the D-169 styling rule holds — nothing is tinted by its price.
 */

/**
 * Strip comments so these scans test SHIPPED DATA, not prose.
 *
 * `DentalCatalogService`'s own docblock says "NO licensed code set (ADA CDT / Swiss SSO point
 * values) is bundled" — the sentence that DECLARES the policy would otherwise be the thing that
 * fails the test enforcing it. Same lesson as the P1/P3 fence scans: forbid the affordance, not
 * the documentation of its absence.
 */
function fspStripComments(string $source): string
{
    $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;      // block comments
    $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;     // template comments

    // Line comments, but never a URL's "//".
    return preg_replace('~(^|\s)//[^\n]*~m', '$1 ', $source) ?? $source;
}

function fspCtx(): TenantContext
{
    return app(TenantContext::class);
}

function fspUser(Tenant $tenant, string $role): User
{
    fspCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, manager: User}
 */
function fspFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Dental', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    fspCtx()->set($tenant);
    app(SettingsService::class)->set('currency', 'CHF');

    // org_admin holds billing.manage — the dentist-owner who maintains the fee schedule.
    return ['tenant' => $tenant, 'manager' => fspUser($tenant, 'org_admin')];
}

test('the schedule renders the tenant OWN authored positions, with fees formatted server-side', function () {
    $fx = fspFixture();
    $catalog = app(DentalCatalogService::class);

    // The practice authors its own positions — its own codes, its own fees.
    $catalog->create($fx['manager'], 'PRX-01', 'Kontrolle', 12500, 0, false);
    $catalog->create($fx['manager'], 'PRX-02', 'Füllung', 24000, 770, true);

    fspCtx()->forget();
    $this->actingAs($fx['manager'])
        ->get(route('dental.fee-schedule'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dental/FeeSchedule')
            ->has('procedures', 2)
            // The rows are the tenant's, verbatim.
            ->where('procedures.0.code', 'PRX-01')
            ->where('procedures.0.fee_minor', 12500)
            ->where('procedures.0.fee', 'CHF 125.00')
            ->where('procedures.0.fee_input', '125.00')
            ->where('procedures.0.scope', 'general')
            ->where('procedures.1.code', 'PRX-02')
            ->where('procedures.1.fee', 'CHF 240.00')
            ->where('procedures.1.vat', '7.70%')
            ->where('procedures.1.scope', 'tooth')
            // Factual counts of the tenant's own rows — no average, no catalog value.
            ->where('summary.positions', 2)
            ->where('summary.active', 2)
            ->where('summary.tooth_scoped', 1)
            ->where('summary', function ($summary) {
                $keys = array_keys(json_decode(json_encode($summary, JSON_THROW_ON_ERROR), true));
                expect($keys)->toBe(['positions', 'active', 'tooth_scoped']);

                return true;
            }));
});

test('NO licensed code set is bundled, seeded or hardcoded anywhere in the repo', function () {
    /*
     * The two sets the dental wireframe implies, and which must never ship with CareOS:
     *
     *  - ADA CDT procedure codes: the letter D followed by four digits (D0120, D1110, D2740).
     *    Our starter template deliberately uses D-EXAM / D-PROPHY — a hyphen and words, so a
     *    genuine CDT code is distinguishable from ours by shape alone.
     *  - Swiss SSO / UV-GO tax-point tariffs: positions priced in Taxpunkte multiplied by a
     *    Taxpunktwert. Neither the point values nor the position list may be bundled.
     */
    $roots = ['Modules', 'app', 'database', 'config', 'resources/js'];
    $files = [];
    foreach ($roots as $root) {
        $dir = base_path($root);
        if (! is_dir($dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (in_array($file->getExtension(), ['php', 'ts', 'vue', 'json'], true)) {
                $files[] = $file->getPathname();
            }
        }
    }
    expect(count($files))->toBeGreaterThan(100, 'the scan found suspiciously few files');

    $licensedTerms = ['taxpunkt', 'tarmed', 'uv-go', 'uvgo', 'dentaltarif', 'ada cdt', 'cdt code', 'sso tarif'];

    foreach ($files as $path) {
        // This test names the licensed sets in order to forbid them, so it must skip itself.
        if (str_ends_with($path, 'FeeScheduleParityTest.php')) {
            continue;
        }

        $source = fspStripComments((string) file_get_contents($path));
        $lower = strtolower($source);
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);

        // A CDT-shaped procedure code: D + exactly four digits.
        expect(preg_match('~\bD\d{4}\b~', $source))
            ->toBe(0, "a CDT-shaped procedure code appears in {$relative} — licensed code sets must not be bundled");

        foreach ($licensedTerms as $term) {
            // The i18n file NAMES these sets to tell the user they are NOT included.
            if (str_ends_with($relative, 'en.json')) {
                continue;
            }
            expect(str_contains($lower, $term))
                ->toBeFalse("licensed tariff data '{$term}' appears in {$relative}");
        }
    }

    // And the starter template that DOES ship is generic and tenant-editable.
    foreach (DentalCatalogService::STARTER as $starter) {
        expect(preg_match('~^D-[A-Z]+$~', $starter['code']))
            ->toBe(1, "starter code {$starter['code']} does not look like a generic placeholder");
        expect(preg_match('~\bD\d{4}\b~', $starter['code']))->toBe(0);
    }
    expect(DentalCatalogService::STARTER)->toHaveCount(7);
});

test('fees are DISPLAYED from the catalog — no page-side money arithmetic, and no aggregate the catalog cannot source', function () {
    $source = (string) file_get_contents(base_path('resources/js/pages/Dental/FeeSchedule.vue'));

    // The template — everything actually rendered — contains no arithmetic at all.
    preg_match('~<template>(.*)</template>~s', $source, $m);
    $template = $m[1] ?? '';
    expect($template)->not->toBe('');
    foreach (['/ 100', '/100', '.toFixed', '.reduce(', 'Math.', 'parseFloat'] as $arithmetic) {
        expect(str_contains($template, $arithmetic))->toBeFalse("the rendered template computes money: '{$arithmetic}'");
    }
    expect(preg_match('~_minor\s*[-+*/]~', $template))->toBe(0, 'the template does arithmetic on minor units');

    // No aggregate the catalog cannot source: no average, no total catalog value, no
    // point value, no price banding.
    $lower = strtolower(fspStripComments($source));
    foreach (['average', 'avg', 'mean', 'totalvalue', 'total_value', 'pointvalue', 'point_value', 'taxpoint', 'tax_point'] as $aggregate) {
        expect(str_contains($lower, $aggregate))->toBeFalse("the page derives an aggregate: '{$aggregate}'");
    }
    expect(preg_match('~\.reduce\(~', $source))->toBe(0, 'the page reduces over fees');

    /*
     * The ONLY arithmetic permitted on this surface is the input-boundary conversion of what
     * the DENTIST TYPED (major units -> minor, percent -> basis points) on its way to the
     * unchanged endpoint. Assert it is used nowhere but the form transforms.
     */
    preg_match_all('~toMinor\(|toBp\(~', $source, $uses);
    expect(count($uses[0]))->toBe(6, 'toMinor/toBp appear somewhere unexpected');
    foreach (['function toMinor(', 'function toBp('] as $declaration) {
        expect(str_contains($source, $declaration))->toBeTrue();
    }
    // Every call site sits inside a .transform(...) payload.
    preg_match_all('~\.transform\(\(d\) => \(\{[^}]*\}\)\)~s', $source, $transforms);
    $inTransforms = substr_count(implode(' ', $transforms[0] ?? []), 'toMinor(') + substr_count(implode(' ', $transforms[0] ?? []), 'toBp(');
    expect($inTransforms)->toBe(4, 'a conversion is used outside a form transform');
});

test('the authoring path and its billing.manage gate are unchanged', function () {
    $fx = fspFixture();

    // A billing.manage holder can author, and the fee lands as the integer the dentist meant.
    fspCtx()->forget();
    $this->actingAs($fx['manager'])
        ->post(route('dental.fee-schedule.store'), [
            'code' => 'PRX-09', 'name' => 'Beratung', 'fee_minor' => 8000, 'vat_rate_bp' => 0, 'tooth_scoped' => false,
        ])
        ->assertRedirect(route('dental.fee-schedule'));

    fspCtx()->set($fx['tenant']);
    $created = app(DentalCatalogService::class)->list()->firstOrFail();
    expect($created->tariffItem->code)->toBe('PRX-09')
        ->and($created->tariffItem->unit_price_minor)->toBe(8000);

    // A doctor holds dental.chart but NOT billing.manage: refused at both read and write.
    $doctor = fspUser($fx['tenant'], 'doctor');
    fspCtx()->forget();
    $this->actingAs($doctor)->get(route('dental.fee-schedule'))->assertForbidden();
    fspCtx()->forget();
    $this->actingAs($doctor)
        ->post(route('dental.fee-schedule.store'), [
            'code' => 'X-01', 'name' => 'Nope', 'fee_minor' => 100, 'vat_rate_bp' => 0, 'tooth_scoped' => false,
        ])
        ->assertForbidden();

    fspCtx()->set($fx['tenant']);
    expect(app(DentalCatalogService::class)->list())->toHaveCount(1);
});

test('D-169: nothing on the fee schedule is styled by its price, and no clinical judgment appears', function () {
    $source = (string) file_get_contents(base_path('resources/js/pages/Dental/FeeSchedule.vue'));
    $code = strtolower(preg_replace('~<!--.*?-->~s', ' ', preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source) ?? $source);

    // No class or style binding may be keyed to a fee or to a numeric comparison — an
    // "expensive item" tint is the same breach as a severity ramp (D-169).
    preg_match_all('~:(?:class|style)="([^"]*)"~', $code, $bindings);
    foreach ($bindings[1] ?? [] as $binding) {
        foreach (['fee', '_minor', 'price', 'vat', 'tint', 'band', 'expensive'] as $needle) {
            expect(str_contains($binding, $needle))
                ->toBeFalse("the fee schedule styles a row from its price: {$binding}");
        }
        expect(preg_match('~[<>]=?\s*\d~', $binding))
            ->toBe(0, "the fee schedule styles by comparing against a threshold: {$binding}");
    }

    // And no clinical judgment on an administrative screen.
    foreach (['recommend', 'suggested', 'severity', 'urgency', 'priority', 'confidence', 'pathway'] as $token) {
        expect(preg_match('~\b'.preg_quote($token, '~').'\b~', $code))->toBe(0, "'{$token}' appears on the fee schedule");
    }
});
