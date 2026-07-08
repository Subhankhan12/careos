<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('key', 'ai.manage')->value('id');

        if ($permissionId === null) {
            $permissionId = (string) Str::ulid();

            DB::table('permissions')->insert([
                'id' => $permissionId,
                'key' => 'ai.manage',
                'description' => 'Manage governed AI actions',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $orgAdminRoleIds = DB::table('roles')
            ->where('key', 'org_admin')
            ->pluck('id');

        foreach ($orgAdminRoleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('key', 'ai.manage')->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
