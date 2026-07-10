<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Exceptions\TariffNotFoundForDateException;
use Modules\Billing\Exceptions\TariffVersionOverlapException;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\EuGenericTariffSeeder;
use Modules\Billing\Services\TariffResolver;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Setting;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function f1Tenant(string $slug): Tenant
{
    return Tenant::query()->create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function f1Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function f1Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function f1User(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => f1Role($role)->id,
    ]);

    return $user;
}

function f1Catalog(array $overrides = []): TariffCatalog
{
    return TariffCatalog::query()->create([
        'key' => 'eu-generic',
        'name' => 'EU Generic',
        'version' => 1,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'status' => TariffCatalog::STATUS_ACTIVE,
        'rules' => ['requires_documentation' => true],
        ...$overrides,
    ]);
}

function f1Item(TariffCatalog $catalog, array $overrides = []): TariffItem
{
    return TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id,
        'code' => 'HOME-60',
        'description' => 'Home care visit',
        'unit_price_minor' => 7500,
        'vat_rate_bp' => 0,
        'unit' => 'session',
        'requires_service_documentation' => true,
        'active' => true,
        ...$overrides,
    ]);
}

test('tariff catalogs and items are tenant isolated and fail closed', function () {
    $alpha = f1Tenant('alpha');
    $beta = f1Tenant('beta');

    f1Ctx()->set($alpha);
    $alphaCatalog = f1Catalog(['key' => 'alpha', 'name' => 'Alpha']);
    f1Item($alphaCatalog, ['code' => 'A-001']);

    f1Ctx()->set($beta);
    $betaCatalog = f1Catalog(['key' => 'beta', 'name' => 'Beta']);
    f1Item($betaCatalog, ['code' => 'B-001']);

    expect(TariffCatalog::query()->pluck('id')->all())->toBe([$betaCatalog->id])
        ->and(TariffCatalog::query()->whereKey($alphaCatalog->id)->exists())->toBeFalse()
        ->and(TariffItem::query()->where('tariff_catalog_id', $alphaCatalog->id)->exists())->toBeFalse();

    f1Ctx()->forget();

    expect(fn () => TariffCatalog::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => TariffItem::query()->count())->toThrow(TenantContextMissingException::class);
});

test('billing manage is granted to org admins and billing role but not reception', function () {
    $tenant = f1Tenant('alpha');
    f1Ctx()->set($tenant);

    $orgAdmin = f1User($tenant, 'org_admin');
    $billing = f1User($tenant, 'billing');
    $reception = f1User($tenant, 'reception');

    expect(Gate::forUser($orgAdmin)->allows('billing.manage'))->toBeTrue()
        ->and(Gate::forUser($billing)->allows('billing.manage'))->toBeTrue()
        ->and(Gate::forUser($reception)->allows('billing.manage'))->toBeFalse();
});

test('catalog versions for the same key cannot overlap', function () {
    $tenant = f1Tenant('alpha');
    f1Ctx()->set($tenant);

    f1Catalog([
        'key' => 'home-care',
        'version' => 1,
        'valid_from' => '2026-01-01',
        'valid_to' => '2026-01-31',
    ]);

    expect(fn () => f1Catalog([
        'key' => 'home-care',
        'version' => 2,
        'valid_from' => '2026-01-15',
        'valid_to' => '2026-02-28',
    ]))->toThrow(TariffVersionOverlapException::class);

    $next = f1Catalog([
        'key' => 'home-care',
        'version' => 2,
        'valid_from' => '2026-02-01',
        'valid_to' => null,
    ]);

    expect($next->version)->toBe(2);
});

test('tariff resolver uses the catalog version valid on the service date', function () {
    $tenant = f1Tenant('alpha');
    f1Ctx()->set($tenant);

    $january = f1Catalog([
        'key' => 'home-care',
        'version' => 1,
        'valid_from' => '2026-01-01',
        'valid_to' => '2026-01-31',
    ]);
    f1Item($january, ['code' => 'HOME-60', 'unit_price_minor' => 7000]);

    $february = f1Catalog([
        'key' => 'home-care',
        'version' => 2,
        'valid_from' => '2026-02-01',
        'valid_to' => null,
    ]);
    f1Item($february, ['code' => 'HOME-60', 'unit_price_minor' => 8000]);

    $resolver = app(TariffResolver::class);

    expect(fn () => $resolver->resolve($tenant, 'HOME-60', '2025-12-31'))->toThrow(TariffNotFoundForDateException::class)
        ->and($resolver->resolve($tenant, 'HOME-60', '2026-01-30')->unit_price_minor)->toBe(7000)
        ->and($resolver->resolve($tenant, 'HOME-60', '2026-02-01')->unit_price_minor)->toBe(8000);
});

test('tariff prices are integer minor units and vat rates are basis point integers', function () {
    $columns = collect(DB::select(
        "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tariff_items'"
    ))->pluck('DATA_TYPE', 'COLUMN_NAME');

    expect(Schema::hasColumns('tariff_items', [
        'unit_price_minor',
        'vat_rate_bp',
    ]))->toBeTrue()
        ->and($columns['unit_price_minor'])->toBe('int')
        ->and($columns['vat_rate_bp'])->toBe('int');
});

test('eu generic starter catalog seeds per tenant with tenant currency default', function () {
    $tenant = f1Tenant('alpha');
    f1Ctx()->set($tenant);
    Setting::query()->create([
        'key' => 'currency',
        'value' => 'CHF',
        'type' => 'string',
    ]);

    $catalog = app(EuGenericTariffSeeder::class)->seed($tenant, '2026-01-01');
    app(EuGenericTariffSeeder::class)->seed($tenant, '2026-01-01');

    expect($catalog->currency)->toBe('CHF')
        ->and(TariffCatalog::query()->where('key', EuGenericTariffSeeder::CATALOG_KEY)->count())->toBe(1)
        ->and(TariffItem::query()->where('tariff_catalog_id', $catalog->id)->count())->toBe(3)
        ->and(TariffItem::query()->where('code', 'HOME-60')->first()->unit_price_minor)->toBe(7500);
});
