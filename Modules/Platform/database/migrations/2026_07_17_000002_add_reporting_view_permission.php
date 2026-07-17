<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\RbacProvisioner;

/**
 * Adds the `reporting.view` permission (operational reporting aggregates) and
 * re-provisions existing tenants so the updated starter role templates
 * (org_admin + coordinator) receive it. Financial aggregates stay behind the
 * existing `billing.view` (P0P.G14).
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
            ->where('permissions.key', 'reporting.view')
            ->delete();

        DB::table('permissions')->where('key', 'reporting.view')->delete();
    }
};
