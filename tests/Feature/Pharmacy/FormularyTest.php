<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Pharmacy\Services\FormularyService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * PHARMACY.G1 — the tenant-authored formulary. The tenant's OWN medication list (NO licensed drug data
 * bundled), the catalog discipline of tariffs/procedures/orderables. RBAC-gated (formulary.manage) +
 * audited + tenant-isolated. No medication-safety judgment lives on the record (that is the seam, tested
 * separately).
 */

function fmCtx(): TenantContext
{
    return app(TenantContext::class);
}

function fmTenant(string $slug = 'pharmhosp'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Hospital', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    fmCtx()->set($tenant);

    return $tenant;
}

function fmUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, pharmacist: User, reception: User} */
function fmFixture(string $slug = 'pharmhosp'): array
{
    $tenant = fmTenant($slug);
    $pharmacist = fmUser($tenant, 'pharmacist'); // holds formulary.manage
    $reception = fmUser($tenant, 'reception');   // patient.view but NOT formulary.manage

    return compact('tenant', 'pharmacist', 'reception');
}

test('the formulary is tenant-authored: a generic starter of the tenant\'s own codes, NOT a licensed code set', function () {
    $fx = fmFixture();

    $created = app(FormularyService::class)->seedStarter($fx['pharmacist']);
    expect($created)->toBe(5);

    // The tenant's OWN MED-* codes + plain generic names — no licensed database.
    $codes = FormularyItem::query()->pluck('code')->all();
    expect($codes)->toContain('MED-PARACETAMOL-500')->toContain('MED-AMOXICILLIN-500')
        ->and(FormularyItem::query()->where('code', 'MED-PARACETAMOL-500')->firstOrFail()->name)->toBe('Paracetamol');

    // FENCE: NO licensed-identifier column (rxnorm/atc/ndc/gtin) and NO computed-safety column.
    $cols = Schema::getColumnListing('formulary_items');
    foreach (['rxnorm', 'atc', 'ndc', 'gtin', 'snomed', 'interaction', 'contraindication', 'dose_max', 'severity', 'score', 'risk'] as $word) {
        expect($cols)->not->toContain($word, "formulary_items must not carry a licensed/computed-safety column: {$word}");
    }
    expect($cols)->toContain('code')->toContain('name')->toContain('form')->toContain('strength');

    // Idempotent by code: re-seeding creates nothing new.
    expect(app(FormularyService::class)->seedStarter($fx['pharmacist']))->toBe(0);
});

test('formulary authoring is RBAC-gated (formulary.manage) and audited', function () {
    $fx = fmFixture();

    // reception (no formulary.manage) cannot author.
    expect(fn () => app(FormularyService::class)->create($fx['reception'], ['code' => 'MED-X', 'name' => 'X']))
        ->toThrow(AuthorizationException::class);

    // the pharmacist can — created + updated + deactivated, each audited, the chain intact.
    $item = app(FormularyService::class)->create($fx['pharmacist'], ['code' => 'MED-METFORMIN-500', 'name' => 'Metformin', 'form' => FormularyItem::FORM_TABLET, 'strength' => '500 mg']);
    expect($item->tenant_id)->toBe($fx['tenant']->id)
        ->and($item->active)->toBeTrue();

    app(FormularyService::class)->update($fx['pharmacist'], $item, ['strength' => '850 mg']);
    app(FormularyService::class)->deactivate($fx['pharmacist'], $item);
    expect($item->fresh()->active)->toBeFalse()
        ->and($item->fresh()->strength)->toBe('850 mg');

    expect(AuditEvent::query()->where('action', 'formulary.item.created')->where('resource_id', $item->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'formulary.item.updated')->where('resource_id', $item->id)->count())->toBeGreaterThanOrEqual(1)
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();
});

test('the formulary admin surface is gated: pharmacist reaches it (200), reception is denied (403), writes are gated', function () {
    $fx = fmFixture();
    app(FormularyService::class)->seedStarter($fx['pharmacist']);

    fmCtx()->forget();
    $this->actingAs($fx['pharmacist'])
        ->get('/pharmacy/formulary')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Pharmacy/Formulary')->has('items', 5));

    fmCtx()->forget();
    $this->actingAs($fx['reception'])->get('/pharmacy/formulary')->assertForbidden();

    // reception cannot author through the real stack.
    fmCtx()->forget();
    $this->actingAs($fx['reception'])->post('/pharmacy/formulary', ['code' => 'MED-Y', 'name' => 'Y'])->assertForbidden();

    // the pharmacist can — the item lands, tenant-scoped.
    fmCtx()->forget();
    $this->actingAs($fx['pharmacist'])->post('/pharmacy/formulary', ['code' => 'MED-ASPIRIN-100', 'name' => 'Aspirin', 'form' => 'tablet', 'strength' => '100 mg'])->assertRedirect();
    fmCtx()->set($fx['tenant']);
    expect(FormularyItem::query()->where('code', 'MED-ASPIRIN-100')->count())->toBe(1);
});

test('the formulary is tenant isolated and fails closed on a cross-tenant edit', function () {
    $fxA = fmFixture('alpha');
    $itemA = app(FormularyService::class)->create($fxA['pharmacist'], ['code' => 'MED-A-ONLY', 'name' => 'Alpha-only med']);

    // switch to tenant B — tenant A's formulary is invisible.
    $fxB = fmFixture('beta');
    app(TenantContext::class)->set($fxB['tenant']);
    expect(FormularyItem::find($itemA->id))->toBeNull();

    // editing tenant A's item from tenant B is rejected — fail closed.
    expect(fn () => app(FormularyService::class)->update($fxB['pharmacist'], $itemA, ['name' => 'hijacked']))
        ->toThrow(CrossTenantReferenceException::class);
});
