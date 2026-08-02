<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\RbacProvisioner;

/**
 * RAD.G1 — add the Radiology / RIS permissions (`radiology.catalog`, `radiology.study`) + the radiology role
 * templates (radiographer / radiologist) additively for every existing tenant. Re-provisions from the updated
 * RbacProvisioner consts — the `add_lab_permissions` / `add_ed_permissions` precedent. New tenants get them via
 * the Tenant `created` hook; the `RbacTest` permission-count assertion is self-referential to the const, so it
 * stays green.
 */
return new class extends Migration
{
    private const RADIOLOGY_PERMISSIONS = ['radiology.catalog', 'radiology.study'];

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
            ->whereIn('permissions.key', self::RADIOLOGY_PERMISSIONS)
            ->delete();
        DB::table('permissions')->whereIn('key', self::RADIOLOGY_PERMISSIONS)->delete();
    }
};
