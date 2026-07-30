<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\RbacProvisioner;

/**
 * LAB.G1 — add the Laboratory / LIS permissions (`lab.catalog`, `lab.result`) + the lab role templates
 * (lab_tech / pathologist / phlebotomist) additively for every existing tenant. Re-provisions from the updated
 * RbacProvisioner consts — the `add_ed_permissions` / `add_surgery_permissions` precedent. New tenants get them
 * via the Tenant `created` hook; the `RbacTest` permission-count assertion is self-referential to the const, so
 * it stays green.
 */
return new class extends Migration
{
    private const LAB_PERMISSIONS = ['lab.catalog', 'lab.result'];

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
            ->whereIn('permissions.key', self::LAB_PERMISSIONS)
            ->delete();
        DB::table('permissions')->whereIn('key', self::LAB_PERMISSIONS)->delete();
    }
};
