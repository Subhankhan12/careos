<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\User;
use Modules\Platform\Services\RbacProvisioner;

/**
 * Roles & access admin (admin.manage). Assigns one of the tenant's EXISTING system
 * role templates to a user — it is NOT a role builder and has NO per-permission
 * toggles. Assignment goes exclusively through the sanctioned path
 * (`RoleAssignment::create`), so the server-side Gate stays authoritative (a user's
 * effective permissions are exactly the template's) and the change is AUTO-AUDITED
 * (the `RoleAssignment::created`/`deleted` events write `role.assigned`/`role.revoked`).
 * A presentation-layer guard refuses to remove the tenant's last org_admin.
 */
class UserRoleController
{
    public function index(Request $request): Response
    {
        Gate::authorize('admin.manage');

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        // Tenant-scoped users (User is not auto tenant-scoped — filter explicitly).
        $users = User::query()
            ->where('tenant_id', $actor->tenant_id)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        // RoleAssignment IS tenant-scoped by BelongsToTenant → confined to this tenant.
        $assignments = RoleAssignment::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->groupBy('user_id');

        // The assignable role templates (already seeded per tenant, is_system).
        $roles = Role::query()->where('is_system', true)->orderBy('name')->get(['id', 'key', 'name']);
        $permissionLabels = RbacProvisioner::PERMISSIONS;
        // id => name for every role in the tenant (Role is tenant-scoped) — a keyed lookup so
        // we never traverse the untyped belongsTo relation property (an undefined-type at L5).
        $roleNames = Role::query()->pluck('name', 'id');

        return Inertia::render('Admin/Roles', [
            'currentUserId' => $actor->id,
            'users' => $users->map(function (User $user) use ($assignments, $roleNames): array {
                $userAssignments = $assignments->get($user->id) ?? collect();
                $primary = $userAssignments->first();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $userAssignments->map(fn (RoleAssignment $assignment): ?string => $roleNames->get($assignment->role_id))->filter()->unique()->values()->all(),
                    'currentRoleId' => $primary?->role_id,
                ];
            })->all(),
            'roles' => $roles->map(fn (Role $role): array => ['id' => $role->id, 'key' => $role->key, 'name' => $role->name])->all(),
            // Read-only "what each role grants" so an admin sees who-can-do-what before assigning.
            'catalog' => Role::query()->where('is_system', true)->orderBy('name')->get()->map(fn (Role $role): array => [
                'key' => $role->key,
                'name' => $role->name,
                'permissions' => $role->permissions()->orderBy('key')->pluck('key')
                    ->map(fn (string $key): array => ['key' => $key, 'label' => $permissionLabels[$key] ?? $key])
                    ->all(),
            ])->all(),
            'assignUrl' => route('admin.roles.assign'),
            'settingsUrl' => route('settings.index'),
        ]);
    }

    public function assign(Request $request): RedirectResponse
    {
        Gate::authorize('admin.manage');

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role_id' => ['required', 'string'],
        ]);

        // Target user must belong to the acting admin's tenant (fail-closed otherwise).
        $user = User::query()
            ->where('id', $data['user_id'])
            ->where('tenant_id', $actor->tenant_id)
            ->first();
        abort_unless($user instanceof User, 404);

        // Role must be one of THIS tenant's seeded system templates (Role is tenant-scoped,
        // so a cross-tenant id resolves to nothing → 404). This is what keeps a user from
        // being granted anything beyond a built template — there is no per-permission surface.
        $role = Role::query()->where('is_system', true)->whereKey($data['role_id'])->first();
        abort_unless($role instanceof Role, 404);

        $current = RoleAssignment::query()->where('user_id', $user->id)->get();

        // No-op if the user already holds exactly this one role (avoids audit churn).
        if ($current->count() === 1 && $current->first()->role_id === $role->id) {
            return redirect()->route('admin.roles.index')->with('status', 'unchanged');
        }

        // Self-lockout guard: the tenant must always keep at least one org_admin. There is
        // no such guard in the RBAC layer, so it lives here (presentation-layer safety).
        if ($role->key !== 'org_admin' && $this->isLastOrgAdmin($user)) {
            return redirect()->route('admin.roles.index')->withErrors(['role' => 'last_admin']);
        }

        // Set the user's role: revoke existing assignments (each delete fires role.revoked),
        // then assign the new one (fires role.assigned). Both audited automatically; never
        // use insert()/query-delete which would bypass the model events and lose the audit.
        DB::transaction(function () use ($current, $user, $role): void {
            $current->each->delete();
            RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'branch_id' => null]);
        });

        return redirect()->route('admin.roles.index')->with('status', 'assigned');
    }

    /** True when $user is currently the tenant's only org_admin. */
    private function isLastOrgAdmin(User $user): bool
    {
        $orgAdminRoleId = Role::query()->where('key', 'org_admin')->value('id');

        if ($orgAdminRoleId === null) {
            return false;
        }

        $adminUserIds = RoleAssignment::query()
            ->where('role_id', $orgAdminRoleId)
            ->pluck('user_id')
            ->unique();

        return $adminUserIds->count() === 1 && $adminUserIds->contains($user->id);
    }
}
