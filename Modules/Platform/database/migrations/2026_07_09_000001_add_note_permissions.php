<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $permissions = [
        'note.write' => 'Write clinical note drafts',
        'note.sign' => 'Sign clinical notes',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $key => $description) {
            $permissionId = DB::table('permissions')->where('key', $key)->value('id');

            if ($permissionId === null) {
                $permissionId = (string) Str::ulid();

                DB::table('permissions')->insert([
                    'id' => $permissionId,
                    'key' => $key,
                    'description' => $description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $roleIds = DB::table('roles')
                ->whereIn('key', ['org_admin', 'doctor', 'nurse'])
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
        $permissionIds = DB::table('permissions')
            ->whereIn('key', array_keys($this->permissions))
            ->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
