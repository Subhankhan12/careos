<?php

use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\FeatureFlag;
use Modules\Platform\Models\Plan;
use Modules\Platform\Models\Setting;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\FeatureService;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function cfgTenant(string $slug, ?string $planKey = null): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
        'plan_id' => $planKey ? Plan::where('key', $planKey)->value('id') : null,
    ]);
}

function cfgCtx(): TenantContext
{
    return app(TenantContext::class);
}

// --- Plans -------------------------------------------------------------------

test('seeded plans store money as integer minor units and expose limits', function () {
    $this->seed(PlanCatalogSeeder::class);

    $pro = Plan::where('key', 'eu_pro')->firstOrFail();

    expect($pro->price_minor)->toBe(19900)->toBeInt()
        ->and($pro->limits['max_branches'])->toBe(10)
        ->and($pro->limits['max_staff'])->toBe(100);

    $starter = Plan::where('key', 'eu_starter')->firstOrFail();
    expect($starter->price_minor)->toBe(4900)->toBeInt()
        ->and($starter->limits['max_branches'])->toBe(1);
});

test('a tenant reads its plan limit via planLimit()', function () {
    $this->seed(PlanCatalogSeeder::class);
    $tenant = cfgTenant('alpha', 'eu_pro');
    cfgCtx()->set($tenant);

    expect($tenant->planLimit('max_branches'))->toBe(10)
        ->and($tenant->planLimit('max_staff'))->toBe(100)
        ->and($tenant->planLimit('unknown', 42))->toBe(42);
});

// --- Feature flags -----------------------------------------------------------

test('feature resolution: tenant override beats plan default beats false', function () {
    $this->seed(PlanCatalogSeeder::class);
    $tenant = cfgTenant('alpha', 'eu_pro'); // telehealth=true, evv=false
    cfgCtx()->set($tenant);
    $features = app(FeatureService::class);

    // plan defaults
    expect($features->enabled('telehealth'))->toBeTrue()
        ->and($features->enabled('evv'))->toBeFalse()
        // unknown feature (not in plan) → false
        ->and($features->enabled('nonexistent'))->toBeFalse();

    // tenant override wins in both directions
    FeatureFlag::create(['key' => 'telehealth', 'enabled' => false]);
    FeatureFlag::create(['key' => 'evv', 'enabled' => true]);

    expect($features->enabled('telehealth'))->toBeFalse()
        ->and($features->enabled('evv'))->toBeTrue();
});

test('a feature is false when the tenant has no plan and no override', function () {
    $tenant = cfgTenant('alpha'); // no plan
    cfgCtx()->set($tenant);

    expect(app(FeatureService::class)->enabled('telehealth'))->toBeFalse();
});

// --- Settings ----------------------------------------------------------------

test('settings provide platform defaults and typed get/set', function () {
    $tenant = cfgTenant('alpha');
    cfgCtx()->set($tenant);
    $settings = app(SettingsService::class);

    // platform defaults
    expect($settings->get('locale'))->toBe('en')
        ->and($settings->get('timezone'))->toBe('UTC')
        ->and($settings->get('currency'))->toBe('EUR');

    // string set/get overrides the default
    $settings->set('timezone', 'Europe/Zurich');
    expect($settings->get('timezone'))->toBe('Europe/Zurich');

    // typed int + bool round-trip
    $settings->set('max_widgets', 5, 'int');
    $settings->set('beta_mode', true, 'bool');
    expect($settings->get('max_widgets'))->toBe(5)->toBeInt()
        ->and($settings->get('beta_mode'))->toBeTrue();

    // unknown key falls back to the supplied default
    expect($settings->get('missing', 'fallback'))->toBe('fallback');
});

// --- Tenant isolation --------------------------------------------------------

test('feature flags and settings are tenant-isolated', function () {
    $this->seed(PlanCatalogSeeder::class);
    $a = cfgTenant('alpha', 'eu_pro');
    $b = cfgTenant('beta'); // no plan

    cfgCtx()->set($a);
    FeatureFlag::create(['key' => 'telehealth', 'enabled' => false]); // override A off
    app(SettingsService::class)->set('timezone', 'Europe/Zurich');

    cfgCtx()->set($b);
    // B does not see A's override; B has no plan → false; B's setting is the default.
    expect(app(FeatureService::class)->enabled('telehealth'))->toBeFalse()
        ->and(app(SettingsService::class)->get('timezone'))->toBe('UTC');

    // A retains its own values.
    cfgCtx()->set($a);
    expect(app(SettingsService::class)->get('timezone'))->toBe('Europe/Zurich');
});

test('feature flags and settings fail closed without a tenant context', function () {
    cfgTenant('alpha');
    cfgCtx()->forget();

    expect(fn () => FeatureFlag::query()->get())->toThrow(TenantContextMissingException::class)
        ->and(fn () => Setting::query()->get())->toThrow(TenantContextMissingException::class);
});
