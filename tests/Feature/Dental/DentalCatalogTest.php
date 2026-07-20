<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Models\AuditEvent;
use Modules\Dental\Services\DentalCatalogService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * DENTAL.G3 — the dental procedure catalog (fee schedule), authored over the EXISTING
 * billing tariff engine. These tests prove the catalog is tenant-authored (a generic
 * starter template, NO licensed CDT), tenant-isolated, billing.manage-gated, audited, and
 * that the fee-schedule payload carries no clinical judgment. A dental procedure IS a
 * tariff item; charging it (billing integration) is covered in DentalBillingTest.
 */

function dcCtx(): TenantContext
{
    return app(TenantContext::class);
}

function dcCatalog(): DentalCatalogService
{
    return app(DentalCatalogService::class);
}

function dcTenant(string $slug): Tenant
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Dental', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    dcCtx()->set($tenant);

    return $tenant;
}

function dcUser(Tenant $tenant, string $role): User
{
    dcCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @param  array<mixed>  $data
 */
function dcAssertNoJudgment(array $data): void
{
    $forbidden = ['severity', 'score', 'grade', 'risk', 'flag', 'abnormal', 'recommendation', 'priority', 'rating', 'interpretation', 'diagnosis', 'verdict'];
    foreach ($data as $key => $value) {
        expect(in_array((string) $key, $forbidden, true))->toBeFalse("interpretation key '{$key}' leaked into the fee-schedule payload");
        if (is_array($value)) {
            dcAssertNoJudgment($value);
        }
    }
}

test('seedStarter lays down a generic tenant-authored template (no licensed codes), idempotent + tenant-isolated', function () {
    $tenant = dcTenant('alpha');
    $admin = dcUser($tenant, 'org_admin');

    expect(dcCatalog()->seedStarter($admin))->toBe(count(DentalCatalogService::STARTER));

    $procedures = dcCatalog()->list();
    expect($procedures)->toHaveCount(count(DentalCatalogService::STARTER));

    // Tenant-authored generic codes (D-EXAM…) — NOT a licensed CDT code set (CDT is Dnnnn numeric).
    $byCode = $procedures->keyBy(fn ($p) => (string) $p->tariffItem->code);
    foreach ($byCode->keys() as $code) {
        expect(str_starts_with((string) $code, 'D-'))->toBeTrue()
            ->and(preg_match('/^D\d{4}$/', (string) $code))->toBe(0); // not the CDT format
    }
    expect($byCode->get('D-RESTOR')->tooth_scoped)->toBeTrue()   // a filling applies to a tooth
        ->and($byCode->get('D-EXAM')->tooth_scoped)->toBeFalse(); // an exam does not

    // Idempotent — a second seed adds nothing.
    expect(dcCatalog()->seedStarter($admin))->toBe(0);
    expect(dcCatalog()->list())->toHaveCount(count(DentalCatalogService::STARTER));

    // Tenant-isolated — a second tenant starts with an empty catalog.
    $beta = dcTenant('beta');
    dcUser($beta, 'org_admin');
    dcCtx()->set($beta);
    expect(dcCatalog()->list())->toHaveCount(0);
});

test('create + update author the tariff item (fee), are billing.manage-gated and tenant-scoped', function () {
    $tenant = dcTenant('alpha');
    $admin = dcUser($tenant, 'org_admin');

    $procedure = dcCatalog()->create($admin, 'D-SEALANT', 'Fissure sealant', 8000, 770, true);
    expect($procedure->tooth_scoped)->toBeTrue()
        ->and($procedure->tariffItem->code)->toBe('D-SEALANT')
        ->and($procedure->tariffItem->unit_price_minor)->toBe(8000)
        ->and($procedure->tariffItem->vat_rate_bp)->toBe(770)
        ->and($procedure->tariffItem->catalog->key)->toBe('dental'); // lives in the dental tariff catalog

    dcCatalog()->update($admin, $procedure, 'Fissure sealant (posterior)', 9000, 770, true, true);
    expect($procedure->refresh()->tariffItem->unit_price_minor)->toBe(9000)
        ->and($procedure->tariffItem->description)->toBe('Fissure sealant (posterior)');

    expect(AuditEvent::query()->where('tenant_id', $tenant->id)->where('action', 'dental.procedure.created')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('tenant_id', $tenant->id)->where('action', 'dental.procedure.updated')->exists())->toBeTrue();

    // A doctor (no billing.manage — the fee schedule is a billing catalog) cannot author.
    $doctor = dcUser($tenant, 'doctor');
    dcCtx()->set($tenant);
    expect(fn () => dcCatalog()->create($doctor, 'D-X', 'X', 1000, 0, false))->toThrow(AuthorizationException::class);
});

test('the fee-schedule page is billing.manage gated and its payload carries no interpretation field', function () {
    $tenant = dcTenant('alpha');
    $admin = dcUser($tenant, 'org_admin');
    dcCatalog()->seedStarter($admin);

    dcCtx()->forget();
    $this->actingAs($admin)
        ->get(route('dental.fee-schedule'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dental/FeeSchedule')
            ->has('procedures', count(DentalCatalogService::STARTER))
            ->where('procedures', function ($procedures) {
                dcAssertNoJudgment(collect($procedures)->toArray());

                return true;
            }));

    dcCtx()->forget();
    $this->actingAs(dcUser($tenant, 'reception'))->get(route('dental.fee-schedule'))->assertForbidden();
});
