<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Exceptions\TenantRegionImmutableException;
use Modules\Platform\Models\Tenant;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('tenants')->delete();
});

test('region defaults to eu and us is allowed at creation', function () {
    $eu = Tenant::create(['name' => 'EU Clinic', 'slug' => 'eu-clinic']);
    $us = Tenant::create(['name' => 'US Clinic', 'slug' => 'us-clinic', 'region' => 'us']);

    expect($eu->region)->toBe('eu')
        ->and($us->region)->toBe('us');
});

test('changing region after creation throws', function () {
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'region' => 'eu']);

    $tenant->region = 'us';

    expect(fn () => $tenant->save())
        ->toThrow(TenantRegionImmutableException::class);

    // Nothing persisted.
    expect(Tenant::query()->find($tenant->id)->region)->toBe('eu');
});

test('updating a non-region attribute is allowed', function () {
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'region' => 'eu']);

    $tenant->status = 'active';
    $tenant->save();

    expect($tenant->fresh()->status)->toBe('active');
});
