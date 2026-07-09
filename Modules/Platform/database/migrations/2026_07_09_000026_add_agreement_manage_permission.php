<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('key', 'agreement.manage')->value('id');

        if ($permissionId === null) {
            $permissionId = (string) Str::ulid();

            DB::table('permissions')->insert([
                'id' => $permissionId,
                'key' => 'agreement.manage',
                'description' => 'Manage nursing service agreements',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $coordinatorRoleId = DB::table('roles')
                ->where('tenant_id', $tenantId)
                ->where('key', 'coordinator')
                ->value('id');

            if ($coordinatorRoleId === null) {
                $coordinatorRoleId = (string) Str::ulid();

                DB::table('roles')->insert([
                    'id' => $coordinatorRoleId,
                    'tenant_id' => $tenantId,
                    'key' => 'coordinator',
                    'name' => 'Nursing Coordinator',
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $roleIds = DB::table('roles')
                ->where('tenant_id', $tenantId)
                ->whereIn('key', ['org_admin', 'coordinator'])
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('key', 'agreement.manage')->value('id');

        if ($permissionId !== null) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        DB::table('roles')->where('key', 'coordinator')->delete();
    }
};
