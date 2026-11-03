<?php

use App\Enums\Permissions;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permissions are defined in code (App\Enums\Permissions) but live in the database. A release that adds a permission
 * (e.g. locations.capture) therefore used to need `db:seed --class=RolePermissionSeeder` as well as `migrate`; forgetting it
 * left agents with a 403 "not authorized" on the new feature.
 *
 * This migration makes `migrate` enough: it ADDS any permission that is missing and grants it to the roles whose default
 * map includes it. It never removes a grant, so permissions an administrator customised are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (Permissions::ALL as $module => $slugs) {
            foreach ($slugs as $slug) {
                if (!DB::table('permissions')->where('slug', $slug)->exists()) {
                    DB::table('permissions')->insert(['slug' => $slug, 'module' => $module, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
        $permissionIds = DB::table('permissions')->pluck('id', 'slug');

        foreach (RoleEnum::cases() as $role) {
            $roleId = DB::table('roles')->where('slug', $role->value)->value('id');
            if (!$roleId) {
                continue;       // fresh install: RolePermissionSeeder creates the roles
            }
            $have = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission_id')->all();
            $rows = collect(Permissions::forRole($role))
                ->map(fn ($slug) => $permissionIds[$slug] ?? null)->filter()->diff($have)
                ->map(fn ($pid) => ['role_id' => $roleId, 'permission_id' => $pid])->values()->all();
            if ($rows) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: granting a permission that the code already defines is harmless.
    }
};
