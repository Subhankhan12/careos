<?php

namespace App\Http\Middleware;

use App\Services\DisplayTimezone;
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
        'encounter.manage',
        'dispatch.manage',
        'comms.manage',
        'billing.view',
        'reporting.view',
        'audit.view',
        'ai.manage',
        'admin.manage',
        'dental.chart',
        // POLISH.1 — nav-gating for the newly-wired clinical/admin surfaces (server Gate
        // stays authoritative; these only decide whether a link is shown).
        'order.manage',
        'competency.manage',
        'data.import',
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
            // Lazy so the locale reflects ApplyTenantLocaleTimezone, which runs AFTER share().
            'locale' => fn () => app()->getLocale(),
            /*
             * The tenant's DISPLAY zone, resolved explicitly (QA-FIX.1a, D-192).
             *
             * This used to read `date_default_timezone_get()`, which only had a tenant value
             * because a middleware mutated the process-wide default — the same mutation that
             * made Eloquent write the practice's wall clock into UTC columns. The prop's value
             * is unchanged; where it comes from is not. Storage stays UTC on every path and the
             * client converts for display using this zone.
             */
            'timezone' => fn () => app(DisplayTimezone::class)->forCurrentTenant(),
            'auth' => [
                'user' => fn () => $this->authUser($request),
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'assignmentWarnings' => fn () => $request->session()->get('assignmentWarnings'),
                'bulk' => fn () => $request->session()->get('bulk'),
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
