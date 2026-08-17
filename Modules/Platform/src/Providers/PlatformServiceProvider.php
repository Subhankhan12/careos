<?php

namespace Modules\Platform\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\FeatureService;
use Modules\Platform\Services\OperatorAccessService;
use Modules\Platform\Services\PermissionService;
use Modules\Platform\Services\RbacProvisioner;
use Modules\Platform\Services\TenantContext;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One TenantContext per request/job; reset on the next.
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'platform');

        // Seed the starter roles for every newly created tenant.
        Tenant::created(function (Tenant $tenant): void {
            $this->app->make(RbacProvisioner::class)->provisionTenant($tenant);
        });

        // Blade helper: @feature('telehealth') … @endfeature.
        // (Inertia surfaces the same flags via a shared prop in a later gate.)
        Blade::if('feature', fn (string $key): bool => $this->app->make(FeatureService::class)->enabled($key));

        // RBAC ↔ Gate integration. The ability name IS the permission key, so
        // $user->can('patient.view', ['branch_id' => $id]) resolves through the
        // role assignments.
        Gate::before(function ($user, string $ability, array $arguments = []) {
            if (! $user instanceof User) {
                return null;
            }

            // OPMODE.G1 — a super-admin is NO LONGER an unconditional bypass.
            //
            // Before: `return true` for any super-admin, always. The only thing that
            // contained them was never being given a tenant context (TenantScope then
            // throws) — an emergent side effect, not an access-control decision. The
            // moment anything set a tenant context for a super-admin (which is exactly
            // what Operator Mode does) that became unlimited, unscoped, untimed,
            // unaudited access to every record in that clinic.
            //
            // Now the decision is explicit and fail-closed:
            //   - no tenant context  → PLATFORM level; unchanged (console, tenant list,
            //     cron, system jobs). No tenant row is reachable there regardless.
            //   - tenant context set → INSIDE a tenant; permitted ONLY by an active,
            //     unexpired, in-tier, in-scope OperatorGrant for that tenant.
            if ($user->isSuperAdmin()) {
                $context = $this->app->make(TenantContext::class);

                if (! $context->has()) {
                    return true;
                }

                return $this->app->make(OperatorAccessService::class)
                    ->allows($user, (string) $context->id(), $ability, $arguments);
            }

            // Only govern known permission abilities; anything else is deferred
            // (null) to future policies. Returning a definitive bool here also
            // short-circuits the Gate so it never spreads a ['branch_id' => …]
            // context array as named arguments to an undefined ability.
            if (! array_key_exists($ability, RbacProvisioner::PERMISSIONS)) {
                return null;
            }

            $branchId = PermissionService::branchFromArguments($arguments);

            return $this->app->make(PermissionService::class)->has($user, $ability, $branchId);
        });
    }
}
