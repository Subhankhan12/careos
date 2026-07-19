<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Modules\Platform\Models\User;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Permissions the staff top-nav gates its links on. Shared to the client purely as a
     * UX hint (hide links a role can't use); every route stays authoritatively gated by the
     * server-side Gate, so hiding a link never grants — nor blocks — actual access.
     *
     * @var list<string>
     */
    private const NAV_PERMISSIONS = [
        'patient.view',
        'appointment.manage',
        'dispatch.manage',
        'comms.manage',
        'billing.view',
        'reporting.view',
        'admin.manage',
    ];

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'locale' => app()->getLocale(),
            'auth' => [
                'user' => fn () => $this->authUser($request),
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'assignmentWarnings' => fn () => $request->session()->get('assignmentWarnings'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function authUser(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'isSuperAdmin' => $user->isSuperAdmin(),
            'tenantId' => $user->tenant_id,
            'permissions' => $this->navPermissions($user),
        ];
    }

    /**
     * The nav-relevant permissions resolved for this user (super-admins get all via
     * Gate::before). Read at response time, after tenant identification has run, so the
     * tenant-scoped RoleAssignment lookup resolves correctly.
     *
     * @return array<string, bool>
     */
    private function navPermissions(User $user): array
    {
        $permissions = [];

        foreach (self::NAV_PERMISSIONS as $key) {
            $permissions[$key] = $user->can($key);
        }

        return $permissions;
    }
}
