<?php

namespace Modules\Platform\Services;

use Modules\Platform\Models\Branch;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\User;

/**
 * Resolves whether a user holds a permission, honouring branch scope.
 *
 * Assignments are read through {@see RoleAssignment}, which is tenant-scoped by
 * BelongsToTenant — so resolution is automatically confined to the current
 * tenant (and fails closed when no tenant context is established).
 */
class PermissionService
{
    /**
     * Does the user hold $key? If $branchId is given, a branch-scoped
     * assignment only counts when it matches that branch; an all-branches
     * assignment (branch_id null) always counts.
     */
    public function has(User $user, string $key, ?string $branchId = null): bool
    {
        // Platform super-admin can do anything (also short-circuited in Gate::before).
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignments = RoleAssignment::query()
            ->where('user_id', $user->getKey())
            ->whereHas('role.permissions', fn ($query) => $query->where('permissions.key', $key))
            ->get(['id', 'branch_id']);

        foreach ($assignments as $assignment) {
            if ($assignment->branch_id === null) {
                return true;
            }

            if ($branchId !== null && $assignment->branch_id === $branchId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract a branch id from Gate ability arguments, supporting both
     * ['branch_id' => ...] and a Branch/string passed positionally.
     *
     * @param  array<int|string, mixed>  $arguments
     */
    public static function branchFromArguments(array $arguments): ?string
    {
        $value = array_key_exists('branch_id', $arguments)
            ? $arguments['branch_id']
            : ($arguments[0] ?? null);

        if ($value instanceof Branch) {
            return $value->getKey();
        }

        return $value !== null ? (string) $value : null;
    }
}
