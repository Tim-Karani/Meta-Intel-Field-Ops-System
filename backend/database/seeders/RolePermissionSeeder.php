<?php

namespace Database\Seeders;

use App\Domain\Audit\AuditService;
use App\Enums\Permissions;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Idempotent: safe to run on every deploy. Permissions are code-defined, so this re-syncs the default role map. */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $muted = AuditService::$muted;
        AuditService::$muted = true;

        DB::transaction(function () {
            $now = now();
            foreach (Permissions::ALL as $module => $slugs) {
                foreach ($slugs as $slug) {
                    DB::table('permissions')->updateOrInsert(['slug' => $slug], ['module' => $module, 'updated_at' => $now, 'created_at' => $now]);
                }
            }
            $permissionIds = DB::table('permissions')->pluck('id', 'slug');

            foreach (RoleEnum::cases() as $role) {
                DB::table('roles')->updateOrInsert(
                    ['slug' => $role->value],
                    ['name' => $role->label(), 'scope_type' => $role->scopeType(), 'is_system' => true, 'updated_at' => $now, 'created_at' => $now],
                );
                $roleId = DB::table('roles')->where('slug', $role->value)->value('id');
                $wanted = collect(Permissions::forRole($role))->map(fn ($s) => $permissionIds[$s])->all();

                DB::table('role_permissions')->where('role_id', $roleId)->whereNotIn('permission_id', $wanted)->delete();
                $have = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission_id')->all();
                $rows = collect($wanted)->diff($have)->map(fn ($pid) => ['role_id' => $roleId, 'permission_id' => $pid])->values()->all();
                if ($rows) {
                    DB::table('role_permissions')->insert($rows);
                }
            }
        });

        AuditService::$muted = $muted;
    }
}
