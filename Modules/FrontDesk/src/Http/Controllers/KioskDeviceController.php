<?php

namespace Modules\FrontDesk\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\FrontDesk\Models\KioskDevice;
use Modules\FrontDesk\Services\KioskDeviceService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;

/**
 * Admin provisioning of kiosk devices (admin.manage). The plaintext token is
 * shown ONCE, right after issue; only its hash is stored. Devices are revocable.
 */
class KioskDeviceController
{
    public function index(): Response
    {
        Gate::authorize('admin.manage');

        return Inertia::render('Admin/Kiosks', [
            'devices' => KioskDevice::query()
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (KioskDevice $device): array => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'branch' => Branch::query()->find($device->branch_id)?->name,
                    'active' => $device->active,
                    'last_used_at' => $device->last_used_at?->toDateTimeString(),
                ])
                ->all(),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->all(),
            'issued' => session('kiosk_issued'), // shown once, right after issue
            'issueUrl' => route('admin.kiosks.issue'),
            'revokeUrl' => route('admin.kiosks.revoke'),
        ]);
    }

    public function issue(Request $request, KioskDeviceService $devices): RedirectResponse
    {
        Gate::authorize('admin.manage');

        $data = $request->validate([
            'branch_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        abort_unless(Branch::query()->whereKey($data['branch_id'])->exists(), 422);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $issued = $devices->issue($data['branch_id'], $data['name'], $actor);

        // The plaintext token + kiosk URL are flashed ONCE for the admin to
        // record; they are never retrievable again.
        return redirect()->route('admin.kiosks.index')->with('kiosk_issued', [
            'token' => $issued['token'],
            'url' => route('kiosk.check-in.page', $issued['token']),
        ]);
    }

    public function revoke(Request $request, KioskDeviceService $devices): RedirectResponse
    {
        Gate::authorize('admin.manage');

        $data = $request->validate(['device_id' => ['required', 'string']]);
        $device = KioskDevice::query()->findOrFail($data['device_id']);
        $devices->revoke($device);

        return redirect()->route('admin.kiosks.index');
    }
}
