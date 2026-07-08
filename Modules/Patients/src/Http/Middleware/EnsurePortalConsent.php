<?php

namespace Modules\Patients\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Platform\Services\TenantContext;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalConsent
{
    public function __construct(
        private readonly ConsentService $consents,
        private readonly TenantContext $tenants,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user('patient');

        if (! $account instanceof PortalAccount) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        if ($account->tenant_id !== $this->tenants->id()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $patient = Patient::query()->whereKey($account->patient_id)->first();

        if (! $patient instanceof Patient) {
            abort(Response::HTTP_FORBIDDEN);
        }

        if (! $this->consents->has($patient, 'portal.access')) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
