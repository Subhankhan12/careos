<?php

namespace App\Http\Controllers;

use App\Services\ResourceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\Resource;

/**
 * Bookable-resource management for the tenant admin (admin.manage). App-layer controller
 * so the deactivation guard can span Scheduling's Resource + its appointments without
 * breaking module boundaries (Platform must not depend on Scheduling). All writes go
 * through {@see ResourceService} and are audited by the AppServiceProvider model hooks.
 *
 * Resources are created under a branch (rooms/chairs/vehicles); the day-board and slot
 * finder already filter `active = true`, so a soft-deactivated resource simply drops out
 * of every booking surface while its rows persist. Deactivation is BLOCKED when the
 * resource still has future appointments so scheduled care is never orphaned — mirroring
 * the branch guard from CLINIC.W8b.
 *
 * Practitioner resources are intentionally excluded here: they are provisioned with a
 * staff profile (People module), not hand-created on the admin resource screen.
 */
class ResourceController
{
    /** Admin-creatable resource types (practitioner is staff-profile driven, not here). */
    private const CREATABLE_TYPES = [
        Resource::TYPE_ROOM,
        Resource::TYPE_CHAIR,
        Resource::TYPE_VEHICLE,
    ];

    public function store(Request $request, string $branch, ResourceService $resources): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $model = Branch::query()->whereKey($branch)->firstOrFail();
        $data = $this->validateResource($request);

        $resources->create([
            'name' => $data['name'],
            'type' => $data['type'],
            'branch_id' => $model->id,
            'active' => true,
        ]);

        return redirect()->route('admin.branches.index')->with('status', 'resourceCreated');
    }

    public function update(Request $request, string $resource, ResourceService $resources): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $model = Resource::query()->whereKey($resource)->firstOrFail();
        $data = $this->validateResource($request);

        $resources->update($model, [
            'name' => $data['name'],
            'type' => $data['type'],
        ]);

        return redirect()->route('admin.branches.index')->with('status', 'resourceUpdated');
    }

    public function deactivate(Request $request, string $resource, ResourceService $resources): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $model = Resource::query()->whereKey($resource)->firstOrFail();

        // SAFETY: never strand scheduled care. A resource with future active appointments
        // must have them reassigned/cancelled first — deactivation is blocked with the count.
        $future = $resources->futureAppointmentCount($model->id);
        if ($future > 0) {
            return back()->withErrors(['resource' => 'has_appointments'])->with('blockedCount', $future);
        }

        $resources->setActive($model, false);

        return redirect()->route('admin.branches.index')->with('status', 'resourceDeactivated');
    }

    public function activate(Request $request, string $resource, ResourceService $resources): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $model = Resource::query()->whereKey($resource)->firstOrFail();
        $resources->setActive($model, true);

        return redirect()->route('admin.branches.index')->with('status', 'resourceActivated');
    }

    /**
     * @return array{name: string, type: string}
     */
    private function validateResource(Request $request): array
    {
        /** @var array{name: string, type: string} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'string', 'in:'.implode(',', self::CREATABLE_TYPES)],
        ]);

        return $validated;
    }
}
