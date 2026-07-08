<?php

namespace Modules\Platform\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\FeatureService;
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
        // role assignments. Super-admin (tenant_id null) is the ONLY bypass.
        Gate::before(function ($user, string $ability, array $arguments = []) {
            if (! $user instanceof User) {
                return null;
            }

            if ($user->isSuperAdmin()) {
                return true;
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
