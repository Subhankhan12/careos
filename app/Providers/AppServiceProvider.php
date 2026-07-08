<?php

namespace App\Providers;

use App\Audit\AuthAuditSubscriber;
use App\Audit\PlatformAuditContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Audit\Contracts\AuditContext;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\FeatureFlag;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Setting;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Wire the audit context to the Platform-aware implementation. This
        // binding lives in the app layer so neither module depends on the other.
        $this->app->bind(AuditContext::class, PlatformAuditContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Auth events (Fortify fires the framework events).
        Event::subscribe(AuthAuditSubscriber::class);

        // RBAC changes.
        RoleAssignment::created(fn (RoleAssignment $m) => $this->auditChange('role.assigned', [
            'resource_type' => 'role_user',
            'resource_id' => $m->id,
            'context' => ['role_id' => $m->role_id, 'user_id' => $m->user_id, 'branch_id' => $m->branch_id],
        ]));
        RoleAssignment::deleted(fn (RoleAssignment $m) => $this->auditChange('role.revoked', [
            'resource_type' => 'role_user',
            'resource_id' => $m->id,
            'context' => ['role_id' => $m->role_id, 'user_id' => $m->user_id, 'branch_id' => $m->branch_id],
        ]));
        Role::created(fn (Role $m) => $this->auditChange('role.changed', [
            'resource_type' => 'role',
            'resource_id' => $m->id,
            'context' => ['key' => $m->key, 'op' => 'created'],
        ]));
        Role::updated(fn (Role $m) => $this->auditChange('role.changed', [
            'resource_type' => 'role',
            'resource_id' => $m->id,
            'context' => ['key' => $m->key, 'op' => 'updated'],
        ]));

        // Feature-flag & settings changes.
        FeatureFlag::saved(fn (FeatureFlag $m) => $this->auditChange('feature_flag.changed', [
            'resource_type' => 'feature_flag',
            'resource_id' => $m->id,
            'context' => ['key' => $m->key, 'enabled' => $m->enabled],
        ]));
        Setting::saved(fn (Setting $m) => $this->auditChange('setting.changed', [
            'resource_type' => 'setting',
            'resource_id' => $m->id,
            'context' => ['key' => $m->key],
        ]));

        // Tenant status changes (platform-level; audited against the tenant itself).
        Tenant::updated(function (Tenant $tenant): void {
            if ($tenant->wasChanged('status')) {
                $this->app->make(AuditService::class)->record([
                    'action' => 'tenant.status_changed',
                    'tenant_id' => $tenant->id,
                    'resource_type' => 'tenant',
                    'resource_id' => $tenant->id,
                    'context' => ['status' => $tenant->status],
                ]);
            }
        });
    }

    /**
     * Record a tenant-scoped change, skipping system-mode provisioning so seeded
     * roles etc. do not pollute the audit chain.
     *
     * @param  array<string, mixed>  $data
     */
    private function auditChange(string $action, array $data): void
    {
        if ($this->app->make(TenantContext::class)->inSystemMode()) {
            return;
        }

        $this->app->make(AuditService::class)->record(['action' => $action, ...$data]);
    }
}
