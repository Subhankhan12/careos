<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\RbacProvisioner;

/**
 * Adds the `competency.manage` permission and re-provisions existing tenants so the
 * updated starter role templates (org_admin + coordinator) receive it. Competency
 * definitions, enforcement (hard/soft), and grants govern who can be assigned to
 * whom, so managing them is a distinct, auditable capability (P0P.G12).
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
            ->where('permissions.key', 'competency.manage')
            ->delete();

        DB::table('permissions')->where('key', 'competency.manage')->delete();
    }
};
