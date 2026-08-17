<?php

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * After authentication, establishes the tenant context from the current user.
 *
 *  - tenant staff (tenant_id set) → load the tenant and set TenantContext;
 *    a suspended tenant is denied outright;
 *  - super-admin (tenant_id null) → CLEAR the TenantContext (they operate via system
 *    mode / platform scope). Enforced, not assumed: since OPMODE.G1 the presence of a
 *    context decides whether a super-admin needs an OperatorGrant, so an inherited one
 *    must never make that decision for them;
 *  - guest → no-op (this runs in the web/api groups for every request).
 *
 * Registered in the web + api groups so it runs after the guard has resolved
 * the authenticated user. API token flows (PWA) are finalized in a later gate.
 */
class IdentifyTenantFromUser
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isTenantStaff()) {
            $tenant = Tenant::find($user->tenant_id);

            if ($tenant === null || $tenant->status === 'suspended') {
                abort(Response::HTTP_FORBIDDEN, __('platform::auth.tenant_suspended'));
            }

            $this->context->set($tenant);
        } elseif ($user instanceof User) {
            // OPMODE.G1 — a super-admin's empty tenant context is now ENFORCED, not
            // merely assumed by declining to set one. Since OPMODE.G1 a super-admin's
            // abilities depend on whether a context is present (no context = platform
            // level; context = a grant is required), so an INHERITED context must never
            // silently decide that. Clearing it is also the safe direction: a stale
            // context can now only ever deny, never widen.
            $this->context->forget();
        }

        return $next($request);
    }
}
