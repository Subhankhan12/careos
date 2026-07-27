<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\RbacProvisioner;

/**
 * PHARMACY.G1 — add the pharmacy permissions (`formulary.manage`, `dispense.manage`) + the pharmacist /
 * pharmacy_technician starter roles, additively, for every existing tenant. Re-provisions from the
 * (updated) RbacProvisioner consts — the `add_billing_manage_permission` precedent. New tenants get these
 * automatically via the Tenant `created` hook.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(RbacProvisioner::class)->syncPermissionCatalog();

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant): void {
            app(RbacProvisioner::class)->provisionTenant($tenant);
        });
    }

    public function down(): void
    {
        // Detach + remove the two new roles, then the two new permissions.
        DB::table('permission_role')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->whereIn('roles.key', ['pharmacist', 'pharmacy_technician'])
            ->delete();
        DB::table('roles')->whereIn('key', ['pharmacist', 'pharmacy_technician'])->delete();

        DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->whereIn('permissions.key', ['formulary.manage', 'dispense.manage'])
            ->delete();
        DB::table('permissions')->whereIn('key', ['formulary.manage', 'dispense.manage'])->delete();
    }
};
