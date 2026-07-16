<?php

namespace Modules\FrontDesk\Services;

use Illuminate\Support\Str;
use Modules\FrontDesk\Models\KioskDevice;
use Modules\Platform\Models\User;

/**
 * Provision and revoke kiosk devices. A device is scoped to one branch; its
 * plaintext token is returned ONCE at issue time and only the sha256 hash is
 * stored. The token authorizes nothing but the check-in flow.
 */
class KioskDeviceService
{
    /**
     * @return array{device: KioskDevice, token: string}
     */
    public function issue(string $branchId, string $name, User $actor): array
    {
        $token = Str::random(48);

        $device = KioskDevice::query()->create([
            'branch_id' => $branchId,
            'name' => $name,
            'token_hash' => $this->hash($token),
            'active' => true,
            'created_by' => (string) $actor->getKey(),
        ]);

        return ['device' => $device, 'token' => $token];
    }

    public function revoke(KioskDevice $device): KioskDevice
    {
        $device->forceFill(['active' => false])->save();

        return $device->refresh();
    }

    /**
     * Resolve an ACTIVE device by its plaintext token WITHOUT a tenant context —
     * the token identifies the tenant. Only this lookup crosses the tenant scope;
     * the caller sets the context from the returned device before anything else.
     */
    public function deviceForToken(string $token): ?KioskDevice
    {
        if (trim($token) === '') {
            return null;
        }

        $device = KioskDevice::query()
            ->withoutGlobalScopes()
            ->where('token_hash', $this->hash($token))
            ->where('active', true)
            ->first();

        return $device instanceof KioskDevice ? $device : null;
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
