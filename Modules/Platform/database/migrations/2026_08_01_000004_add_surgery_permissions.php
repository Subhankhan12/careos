<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\RbacProvisioner;

/**
 * SURGERY.G1 — add the operating-theatre / surgery permissions (`theatre.manage`, `surgery.schedule`,
 * `surgery.manage`) + the OR role templates (surgeon / anesthetist / scrub_nurse / surgical_scheduler)
 * additively for every existing tenant. Re-provisions from the updated RbacProvisioner consts — the
 * `add_medication_prescribe_permission` precedent. New tenants get them via the Tenant `created` hook; the
 * `RbacTest` permission-count assertion is self-referential to the const, so it stays green.
 */
return new class extends Migration
{
    private const SURGERY_PERMISSIONS = ['theatre.manage', 'surgery.schedule', 'surgery.manage'];

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
            ->whereIn('permissions.key', self::SURGERY_PERMISSIONS)
            ->delete();
        DB::table('permissions')->whereIn('key', self::SURGERY_PERMISSIONS)->delete();
    }
};
