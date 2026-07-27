<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\RbacProvisioner;

/**
 * PHARMACY.G2 — add the `medication.prescribe` permission (the prescriber act — doctor / hospitalist /
 * org_admin) additively for every existing tenant. Re-provisions from the updated RbacProvisioner consts —
 * the `add_billing_manage_permission` precedent. New tenants get it via the Tenant `created` hook.
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
        DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.key', 'medication.prescribe')
            ->delete();
        DB::table('permissions')->where('key', 'medication.prescribe')->delete();
    }
};
