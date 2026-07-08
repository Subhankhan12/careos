<?php

namespace Modules\Patients\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenantFromPortalSession
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->session()->get('portal_tenant_id');

        if (is_string($tenantId) && $tenantId !== '') {
            $tenant = Tenant::query()->find($tenantId);

            if ($tenant === null || $tenant->status === 'suspended') {
                abort(Response::HTTP_FORBIDDEN);
            }

            $this->context->set($tenant);
        }

        return $next($request);
    }
}
