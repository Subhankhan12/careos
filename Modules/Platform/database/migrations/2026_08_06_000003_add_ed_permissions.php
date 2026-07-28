<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\RbacProvisioner;

/**
 * ED.G1 — add the Emergency Department permissions (`ed.manage`, `triage.record`) + the ED role templates
 * (ed_physician / triage_nurse / ed_charge_nurse) additively for every existing tenant. Re-provisions from the
 * updated RbacProvisioner consts — the `add_surgery_permissions` precedent. New tenants get them via the Tenant
 * `created` hook; the `RbacTest` permission-count assertion is self-referential to the const, so it stays green.
 */
return new class extends Migration
{
    private const ED_PERMISSIONS = ['ed.manage', 'triage.record'];

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
            ->whereIn('permissions.key', self::ED_PERMISSIONS)
            ->delete();
        DB::table('permissions')->whereIn('key', self::ED_PERMISSIONS)->delete();
    }
};
