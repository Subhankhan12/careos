<?php

namespace Modules\Platform\Services;

use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;

/**
 * Resolves the user an UNATTENDED scheduled run acts as inside one tenant.
 *
 * Several services (billing dunning, reconciliation) require an authorized
 * actor by design — there is no "no actor" path through them, and there should
 * not be: the work they do is accountable. A scheduler has no logged-in user,
 * so it has to choose one, and the only safe way to choose is to pick somebody
 * who ALREADY holds the permission and never to invent authority.
 *
 * Rules, all of them fail-closed:
 *   - Tenant staff only. A platform super-admin (tenant_id = null) is never
 *     chosen: super-admin bypasses every gate, so scheduling as one would mean
 *     unattended jobs silently run with more authority than any tenant user has.
 *   - The permission must be held TENANT-WIDE. {@see PermissionService::has()}
 *     with no branch counts only all-branches assignments, so a nurse with a
 *     branch-scoped role never gets picked up for a tenant-wide job.
 *   - Deterministic: lowest user id wins, so the same tenant resolves the same
 *     actor on every run and the audit trail stays stable.
 *   - Returns null when nobody qualifies. The caller SKIPS that tenant. A
 *     tenant with no billing manager gets no dunning run — it does not get one
 *     executed by somebody who was never given the permission.
 */
class SystemActorResolver
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly TenantContext $context,
    ) {}

    /**
     * The lowest-id active user in $tenant who genuinely holds $permission
     * tenant-wide, or null when there is nobody to act as.
     *
     * Requires the tenant context to be set to $tenant: role assignments are
     * tenant-scoped, so resolution is confined to that tenant by the same
     * fail-closed scope everything else uses.
     */
    public function forPermission(Tenant $tenant, string $permission): ?User
    {
        if ($this->context->id() !== $tenant->getKey()) {
            return null;
        }

        return User::query()
            ->where('tenant_id', $tenant->getKey())
            ->orderBy('id')
            ->get()
            ->first(fn (User $user): bool => ! $user->isSuperAdmin()
                && $this->permissions->has($user, $permission));
    }
}
