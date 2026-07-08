<?php

namespace Modules\Platform\Services;

use Modules\Platform\Models\Permission;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\Tenant;

/**
 * Seeds the platform permission catalog and the per-tenant starter roles.
 *
 * The catalog is shared across tenants; the starter role templates are created
 * for each tenant on creation (wired via a Tenant `created` hook in
 * PlatformServiceProvider). Both operations are idempotent.
 *
 * `super_admin` is intentionally NOT a seeded tenant role: the platform
 * super-admin is the user with tenant_id = null, bypassing via Gate::before.
 */
class RbacProvisioner
{
    /**
     * The starter permission catalog: key => human description.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'patient.view' => 'View patients',
        'patient.edit' => 'Create and edit patients',
        'patient.merge' => 'Merge duplicate patients',
        'appointment.manage' => 'Manage appointments',
        'billing.view' => 'View billing',
        'audit.view' => 'View the audit log',
        'admin.manage' => 'Manage tenant settings and users',
    ];

    /**
     * The starter tenant role templates: key => [name, permission keys].
     *
     * @var array<string, array{name: string, permissions: list<string>}>
     */
    public const ROLE_TEMPLATES = [
        'org_admin' => [
            'name' => 'Organisation Admin',
            'permissions' => [
                'admin.manage', 'patient.view', 'patient.edit', 'patient.merge',
                'appointment.manage', 'billing.view', 'audit.view',
            ],
        ],
        'doctor' => [
            'name' => 'Doctor',
            'permissions' => ['patient.view', 'patient.edit', 'appointment.manage'],
        ],
        'nurse' => [
            'name' => 'Nurse',
            'permissions' => ['patient.view', 'appointment.manage'],
        ],
        'reception' => [
            'name' => 'Reception',
            'permissions' => ['patient.view', 'appointment.manage'],
        ],
        'billing' => [
            'name' => 'Billing',
            'permissions' => ['billing.view'],
        ],
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Upsert the shared permission catalog (platform-level, no tenant context).
     */
    public function syncPermissionCatalog(): void
    {
        foreach (self::PERMISSIONS as $key => $description) {
            Permission::query()->updateOrCreate(['key' => $key], ['description' => $description]);
        }
    }

    /**
     * Create/refresh the starter roles for a tenant and attach their permissions.
     */
    public function provisionTenant(Tenant $tenant): void
    {
        $this->syncPermissionCatalog();

        // System mode: roles are written with an explicit tenant_id, without an
        // ambient tenant context (tenant creation happens outside any tenant).
        $this->context->system(function () use ($tenant) {
            foreach (self::ROLE_TEMPLATES as $key => $template) {
                $role = Role::query()
                    ->where('tenant_id', $tenant->getKey())
                    ->where('key', $key)
                    ->first() ?? new Role;

                $role->forceFill([
                    'tenant_id' => $tenant->getKey(),
                    'key' => $key,
                    'name' => $template['name'],
                    'is_system' => true,
                ])->save();

                $permissionIds = Permission::query()
                    ->whereIn('key', $template['permissions'])
                    ->pluck('id');

                $role->permissions()->sync($permissionIds);
            }
        });
    }
}
