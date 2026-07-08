<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;
use Tests\Support\TenantProbe;

uses(RefreshDatabase::class);

/**
 * The probe table is created here (per the gate) rather than via a migration.
 * Schema DDL auto-commits on MariaDB/MySQL, so we reset all state explicitly in
 * beforeEach instead of relying on transaction rollback, and drop the table in
 * afterEach. Every test therefore starts from a known-clean slate.
 */
beforeEach(function () {
    Schema::dropIfExists('tenant_probes');
    Schema::create('tenant_probes', function ($table) {
        $table->id();
        $table->ulid('tenant_id');       // char(26), matches tenants.id
        $table->string('value');
        $table->index('tenant_id');
    });

    // Clear any tenant rows leaked by the DDL-committed transaction above.
    DB::table('tenants')->delete();
});

afterEach(function () {
    Schema::dropIfExists('tenant_probes');
});

/**
 * @return array{0: Tenant, 1: Tenant}
 */
function makeTwoTenants(): array
{
    $a = Tenant::create(['name' => 'Alpha Clinic', 'slug' => 'alpha', 'region' => 'eu']);
    $b = Tenant::create(['name' => 'Beta Clinic', 'slug' => 'beta', 'region' => 'eu']);

    return [$a, $b];
}

function tenantCtx(): TenantContext
{
    return app(TenantContext::class);
}

test('(a) with context A, only A rows are visible and B rows are invisible', function () {
    [$a, $b] = makeTwoTenants();

    tenantCtx()->set($a);
    TenantProbe::create(['value' => 'a1']);
    TenantProbe::create(['value' => 'a2']);

    tenantCtx()->set($b);
    TenantProbe::create(['value' => 'b1']);

    tenantCtx()->set($a);
    $rows = TenantProbe::all();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('value')->all())->toEqualCanonicalizing(['a1', 'a2'])
        ->and($rows->pluck('tenant_id')->unique()->all())->toBe([$a->id]);
});

test('(b) creating a probe under context A stamps tenant_id = A automatically', function () {
    [$a] = makeTwoTenants();

    tenantCtx()->set($a);
    $probe = TenantProbe::create(['value' => 'auto']);

    expect($probe->tenant_id)->toBe($a->id);

    // Confirm it persisted with A's id, read back in system mode.
    $stored = tenantCtx()->system(fn () => DB::table('tenant_probes')->where('value', 'auto')->value('tenant_id'));
    expect($stored)->toBe($a->id);
});

test('(c) querying with NO tenant context throws TenantContextMissingException', function () {
    [$a] = makeTwoTenants();

    tenantCtx()->set($a);
    TenantProbe::create(['value' => 'a1']);

    tenantCtx()->forget();

    expect(fn () => TenantProbe::all())
        ->toThrow(TenantContextMissingException::class);
});

test('(d) TenantContext::system bypasses scoping and sees all tenants rows', function () {
    [$a, $b] = makeTwoTenants();

    tenantCtx()->set($a);
    TenantProbe::create(['value' => 'a1']);
    tenantCtx()->set($b);
    TenantProbe::create(['value' => 'b1']);
    TenantProbe::create(['value' => 'b2']);

    tenantCtx()->forget();

    $count = tenantCtx()->system(fn () => TenantProbe::count());

    expect($count)->toBe(3);
});

test('(e) reading a specific B row by id while context = A returns nothing', function () {
    [$a, $b] = makeTwoTenants();

    tenantCtx()->set($b);
    $bRow = TenantProbe::create(['value' => 'b-secret']);

    tenantCtx()->set($a);

    expect(TenantProbe::find($bRow->id))->toBeNull()
        ->and(TenantProbe::whereKey($bRow->id)->first())->toBeNull();
});
