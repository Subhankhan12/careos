<?php

namespace Modules\Hospital\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Hospital\Models\Ward;
use Modules\Platform\Models\User;

/**
 * Ward / unit CRUD (HOSPITAL.G1). Tenant + branch scoped (via the model's
 * BelongsToTenant + cross-tenant branch guard); every mutating method is gated on
 * `ward.manage` server-side (the server Gate is authoritative). Creation / rename /
 * deactivation are audited through app-layer model hooks (Hospital stays free of
 * Audit). No ADT/stay logic here — a ward simply groups beds.
 */
class WardService
{
    public function create(User $actor, string $branchId, string $name, string $code): Ward
    {
        Gate::forUser($actor)->authorize('ward.manage');

        return Ward::query()->create([
            'branch_id' => $branchId,
            'name' => $name,
            'code' => $code,
        ]);
    }

    public function rename(User $actor, Ward $ward, string $name, string $code): Ward
    {
        Gate::forUser($actor)->authorize('ward.manage');

        $ward->forceFill(['name' => $name, 'code' => $code])->save();

        return $ward;
    }

    public function deactivate(User $actor, Ward $ward): Ward
    {
        Gate::forUser($actor)->authorize('ward.manage');

        $ward->forceFill(['active' => false])->save();

        return $ward;
    }

    /**
     * The tenant's active wards, ordered by name — the ward board's read source (HOSPITAL.G3).
     * Tenant-scoped by BelongsToTenant. A read; the board controller gates it on `patient.view`.
     *
     * @return Collection<int, Ward>
     */
    public function activeWards(): Collection
    {
        return Ward::query()->where('active', true)->orderBy('name')->get();
    }
}
