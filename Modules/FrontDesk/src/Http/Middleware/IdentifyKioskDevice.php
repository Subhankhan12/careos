<?php

namespace Modules\FrontDesk\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\FrontDesk\Models\KioskDevice;
use Modules\FrontDesk\Services\KioskDeviceService;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the kiosk device from the `{kioskToken}` route parameter, sets the
 * tenant context from the device, and stashes the device on the request. An
 * unknown/revoked token is a flat 403 — the kiosk is trusted for the check-in
 * flow ONLY, and this middleware is the sole thing that lets those routes run
 * without a logged-in user.
 */
class IdentifyKioskDevice
{
    public function __construct(
        private readonly KioskDeviceService $devices,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('kioskToken');
        $device = $this->devices->deviceForToken($token);

        if (! $device instanceof KioskDevice) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $tenant = Tenant::query()->find($device->tenant_id);

        if ($tenant === null || $tenant->status === 'suspended') {
            abort(Response::HTTP_FORBIDDEN);
        }

        $this->context->set($tenant);
        $request->attributes->set('kiosk_device', $device);

        return $next($request);
    }
}
