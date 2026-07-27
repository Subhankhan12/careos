<?php

namespace Modules\Pharmacy\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Pharmacy\Contracts\MedicationSafetyProvider;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Authors the tenant's FORMULARY (PHARMACY.G1) — create / edit / deactivate / seed-a-starter. The catalog
 * discipline of `DentalCatalogService` / `BedBillingService::seedStarter`: gated `formulary.manage`,
 * idempotent by code, tenant-scoped + fail-closed. The seeded starter is a SMALL GENERIC editable template
 * of common meds with the tenant's OWN codes — NOT a licensed drug database.
 *
 * No pricing here (a formulary item's billing overlay is PHARMACY.G5) and — by design — NO safety logic:
 * medication-safety judgment lives only behind the {@see MedicationSafetyProvider}
 * certified-partner seam.
 */
class FormularyService
{
    /**
     * A GENERIC starter set — a handful of common meds as an editable template, the tenant's own `MED-*`
     * codes (NO licensed drug database: no First Databank / Medi-Span / RxNorm / ATC / NDC data). Plain
     * generic names + placeholder strengths the pharmacist edits.
     *
     * @var list<array{code: string, name: string, form: string, strength: string}>
     */
    public const STARTER = [
        ['code' => 'MED-PARACETAMOL-500', 'name' => 'Paracetamol', 'form' => FormularyItem::FORM_TABLET, 'strength' => '500 mg'],
        ['code' => 'MED-IBUPROFEN-400', 'name' => 'Ibuprofen', 'form' => FormularyItem::FORM_TABLET, 'strength' => '400 mg'],
        ['code' => 'MED-AMOXICILLIN-500', 'name' => 'Amoxicillin', 'form' => FormularyItem::FORM_CAPSULE, 'strength' => '500 mg'],
        ['code' => 'MED-OMEPRAZOLE-20', 'name' => 'Omeprazole', 'form' => FormularyItem::FORM_CAPSULE, 'strength' => '20 mg'],
        ['code' => 'MED-SALINE-0-9', 'name' => 'Sodium chloride 0.9%', 'form' => FormularyItem::FORM_INJECTION, 'strength' => '500 mL'],
    ];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Seed the generic starter formulary for the current tenant. Idempotent by code; gated `formulary.manage`.
     * NO licensed drug data — the tenant edits these like any other catalog. Returns the number created.
     */
    public function seedStarter(User $actor): int
    {
        Gate::forUser($actor)->authorize('formulary.manage');
        $created = 0;

        foreach (self::STARTER as $starter) {
            $item = FormularyItem::query()->firstOrCreate(
                ['code' => $starter['code']],
                ['name' => $starter['name'], 'form' => $starter['form'], 'strength' => $starter['strength'], 'active' => true],
            );
            if ($item->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  array{code: string, name: string, form?: string|null, strength?: string|null, active?: bool}  $data
     */
    public function create(User $actor, array $data): FormularyItem
    {
        Gate::forUser($actor)->authorize('formulary.manage');

        return FormularyItem::query()->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'form' => $data['form'] ?? null,
            'strength' => $data['strength'] ?? null,
            'active' => $data['active'] ?? true,
        ]);
    }

    /**
     * @param  array{name?: string, form?: string|null, strength?: string|null, active?: bool}  $data
     */
    public function update(User $actor, FormularyItem $item, array $data): FormularyItem
    {
        Gate::forUser($actor)->authorize('formulary.manage');
        $this->assertSameTenant($item);

        $item->fill(array_intersect_key($data, array_flip(['name', 'form', 'strength', 'active'])))->save();

        return $item->refresh();
    }

    public function deactivate(User $actor, FormularyItem $item): FormularyItem
    {
        Gate::forUser($actor)->authorize('formulary.manage');
        $this->assertSameTenant($item);

        $item->forceFill(['active' => false])->save();

        return $item->refresh();
    }

    /**
     * The tenant's formulary, newest edits first — the admin-surface read.
     *
     * @return Collection<int, FormularyItem>
     */
    public function forTenant(): Collection
    {
        return FormularyItem::query()->orderBy('name')->orderBy('code')->get();
    }

    private function assertSameTenant(FormularyItem $item): void
    {
        if ($item->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('formulary_item_id', (string) $item->id);
        }
    }
}
