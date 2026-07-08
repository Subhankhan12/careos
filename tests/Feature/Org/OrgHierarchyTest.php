<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Department;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function orgTenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function orgCtx(): TenantContext
{
    return app(TenantContext::class);
}

test('branches are isolated per tenant', function () {
    $a = orgTenant('alpha');
    $b = orgTenant('beta');

    orgCtx()->set($a);
    Branch::create(['name' => 'A Main', 'code' => 'MAIN']);

    orgCtx()->set($b);
    Branch::create(['name' => 'B Main', 'code' => 'MAIN']);
    Branch::create(['name' => 'B North', 'code' => 'NORTH']);

    orgCtx()->set($a);
    $rows = Branch::all();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->name)->toBe('A Main')
        ->and($rows->first()->tenant_id)->toBe($a->id);
});

test('the same branch code may exist in different tenants', function () {
    $a = orgTenant('alpha');
    $b = orgTenant('beta');

    orgCtx()->set($a);
    $branchA = Branch::create(['name' => 'A Main', 'code' => 'MAIN']);

    orgCtx()->set($b);
    $branchB = Branch::create(['name' => 'B Main', 'code' => 'MAIN']);

    expect($branchA->tenant_id)->toBe($a->id)
        ->and($branchB->tenant_id)->toBe($b->id);
});

test('tenant A cannot see or fetch a branch owned by tenant B', function () {
    $a = orgTenant('alpha');
    $b = orgTenant('beta');

    orgCtx()->set($b);
    $branchB = Branch::create(['name' => 'B Main', 'code' => 'MAIN']);

    orgCtx()->set($a);

    expect(Branch::find($branchB->id))->toBeNull()
        ->and(Branch::whereKey($branchB->id)->first())->toBeNull();
});

test('creating a department stamps the tenant_id and links its branch', function () {
    $a = orgTenant('alpha');

    orgCtx()->set($a);
    $branch = Branch::create(['name' => 'A Main', 'code' => 'MAIN']);
    $dept = Department::create(['branch_id' => $branch->id, 'name' => 'Cardiology', 'code' => 'CARD']);

    expect($dept->tenant_id)->toBe($a->id)
        ->and($dept->branch_id)->toBe($branch->id)
        ->and($dept->branch->is($branch))->toBeTrue();
});

test('departments are isolated per tenant', function () {
    $a = orgTenant('alpha');
    $b = orgTenant('beta');

    orgCtx()->set($a);
    $branchA = Branch::create(['name' => 'A Main', 'code' => 'MAIN']);
    Department::create(['branch_id' => $branchA->id, 'name' => 'Cardiology', 'code' => 'CARD']);

    orgCtx()->set($b);
    $branchB = Branch::create(['name' => 'B Main', 'code' => 'MAIN']);
    Department::create(['branch_id' => $branchB->id, 'name' => 'Neurology', 'code' => 'NEUR']);

    orgCtx()->set($a);

    expect(Department::all())->toHaveCount(1)
        ->and(Department::first()->code)->toBe('CARD');
});

test('a department cannot be attached to a branch from another tenant', function () {
    $a = orgTenant('alpha');
    $b = orgTenant('beta');

    orgCtx()->set($b);
    $branchB = Branch::create(['name' => 'B Main', 'code' => 'MAIN']);

    orgCtx()->set($a);

    expect(fn () => Department::create([
        'branch_id' => $branchB->id,
        'name' => 'Cardiology',
        'code' => 'CARD',
    ]))->toThrow(CrossTenantReferenceException::class);

    // Nothing was written.
    expect(Department::count())->toBe(0);
});

test('moving a department to a cross-tenant branch is rejected on update', function () {
    $a = orgTenant('alpha');
    $b = orgTenant('beta');

    orgCtx()->set($b);
    $branchB = Branch::create(['name' => 'B Main', 'code' => 'MAIN']);

    orgCtx()->set($a);
    $branchA = Branch::create(['name' => 'A Main', 'code' => 'MAIN']);
    $dept = Department::create(['branch_id' => $branchA->id, 'name' => 'Cardiology', 'code' => 'CARD']);

    $dept->branch_id = $branchB->id;

    expect(fn () => $dept->save())->toThrow(CrossTenantReferenceException::class);
});

test('tenant branches relation returns only that tenant rows', function () {
    $a = orgTenant('alpha');
    $b = orgTenant('beta');

    orgCtx()->set($a);
    Branch::create(['name' => 'A Main', 'code' => 'MAIN']);
    Branch::create(['name' => 'A North', 'code' => 'NORTH']);

    orgCtx()->set($b);
    Branch::create(['name' => 'B Main', 'code' => 'MAIN']);

    orgCtx()->set($a);

    expect($a->branches()->get())->toHaveCount(2);
});
