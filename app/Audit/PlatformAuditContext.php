<?php

namespace App\Audit;

use Illuminate\Support\Facades\Auth;
use Modules\Audit\Contracts\AuditContext;
use Modules\Patients\Models\PortalAccount;
use Modules\Platform\Services\TenantContext;

/**
 * Platform-aware {@see AuditContext}: reads the current tenant from
 * TenantContext and the actor from the auth guard.
 *
 * Lives in the application layer (not a module) so it may depend on BOTH the
 * Audit and Platform modules without violating the module boundary rule.
 */
class PlatformAuditContext implements AuditContext
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function tenantId(): ?string
    {
        return $this->tenant->id();
    }

    public function actor(): array
    {
        $user = Auth::user();

        if ($user !== null) {
            return ['type' => 'user', 'id' => (string) $user->getAuthIdentifier()];
        }

        $patient = Auth::guard('patient')->user();

        if ($patient instanceof PortalAccount) {
            return ['type' => 'patient', 'id' => (string) $patient->getAuthIdentifier()];
        }

        return ['type' => 'service', 'id' => null];
    }

    public function ip(): ?string
    {
        return request()->ip();
    }

    public function userAgent(): ?string
    {
        $agent = request()->userAgent();

        return $agent !== null ? substr($agent, 0, 255) : null;
    }
}
