<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Models\ServiceBranch;
use Modules\Scheduling\Services\ServiceCatalog;

uses(RefreshDatabase::class);

function schedulingTenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function schedulingContext(): TenantContext
{
    return app(TenantContext::class);
}

function schedulingCatalog(): ServiceCatalog
{
    return app(ServiceCatalog::class);
}

/**
 * @return array<string, mixed>
 */
function servicePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Consultation',
        'code' => 'CONSULT',
        'category' => 'front-desk',
        'default_duration_minutes' => 30,
        'buffer_before_minutes' => 5,
        'buffer_after_minutes' => 10,
        'requires_resource_types' => ['practitioner'],
        'bookable_online' => true,
        'active' => true,
    ], $overrides);
}

test('services are tenant isolated and fail closed', function () {
    $alpha = schedulingTenant('alpha');
    $beta = schedulingTenant('beta');

    schedulingContext()->set($alpha);
    $alphaService = schedulingCatalog()->create(servicePayload(['code' => 'ALPHA']));

    schedulingContext()->set($beta);
    $betaService = schedulingCatalog()->create(servicePayload(['code' => 'BETA']));

    expect(Service::pluck('id')->all())->toBe([$betaService->id])
        ->and(Service::find($alphaService->id))->toBeNull();

    schedulingContext()->forget();

    expect(fn () => Service::query()->get())->toThrow(TenantContextMissingException::class);
});

test('service codes are unique per tenant', function () {
    $alpha = schedulingTenant('alpha');
    $beta = schedulingTenant('beta');

    schedulingContext()->set($alpha);
    schedulingCatalog()->create(servicePayload(['code' => 'HYGIENE']));

    expect(fn () => schedulingCatalog()->create(servicePayload([
        'name' => 'Duplicate Hygiene',
        'code' => 'HYGIENE',
    ])))->toThrow(InvalidArgumentException::class);

    schedulingContext()->set($beta);
    $sameCode = schedulingCatalog()->create(servicePayload(['code' => 'HYGIENE']));

    expect($sameCode->code)->toBe('HYGIENE');
});

test('duration buffers and resource type requirements are validated', function () {
    $tenant = schedulingTenant('alpha');
    schedulingContext()->set($tenant);

    expect(fn () => schedulingCatalog()->create(servicePayload([
        'default_duration_minutes' => 0,
    ])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => schedulingCatalog()->create(servicePayload([
            'buffer_before_minutes' => -1,
        ])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => schedulingCatalog()->create(servicePayload([
            'requires_resource_types' => [],
        ])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => schedulingCatalog()->create(servicePayload([
            'requires_resource_types' => ['practitioner', ''],
        ])))->toThrow(InvalidArgumentException::class);
});

test('resource type requirements are stored and read as structured data', function () {
    $tenant = schedulingTenant('alpha');
    schedulingContext()->set($tenant);

    $service = schedulingCatalog()->create(servicePayload([
        'requires_resource_types' => ['practitioner', 'chair'],
    ]));

    expect($service->requires_resource_types)->toBe(['practitioner', 'chair'])
        ->and($service->fresh()->requires_resource_types)->toBe(['practitioner', 'chair']);
});

test('branch availability uses tenant-owned service_branch links', function () {
    $tenant = schedulingTenant('alpha');
    schedulingContext()->set($tenant);

    $north = Branch::create(['name' => 'North', 'code' => 'NORTH']);
    $south = Branch::create(['name' => 'South', 'code' => 'SOUTH']);

    $service = schedulingCatalog()->create(servicePayload(), [$north->id]);

    expect($service->branchLinks)->toHaveCount(1)
        ->and($service->isAvailableAtBranch($north->id))->toBeTrue()
        ->and($service->isAvailableAtBranch($south->id))->toBeFalse()
        ->and(ServiceBranch::firstOrFail()->tenant_id)->toBe($tenant->id);

    $updated = schedulingCatalog()->update($service, [], [$south->id]);

    expect($updated->branchLinks)->toHaveCount(1)
        ->and($updated->isAvailableAtBranch($south->id))->toBeTrue()
        ->and($updated->isAvailableAtBranch($north->id))->toBeFalse();
});

test('cross tenant branch availability is rejected', function () {
    $alpha = schedulingTenant('alpha');
    $beta = schedulingTenant('beta');

    schedulingContext()->set($beta);
    $betaBranch = Branch::create(['name' => 'Beta', 'code' => 'BETA']);

    schedulingContext()->set($alpha);

    expect(fn () => schedulingCatalog()->create(servicePayload(), [$betaBranch->id]))
        ->toThrow(CrossTenantReferenceException::class);
});
