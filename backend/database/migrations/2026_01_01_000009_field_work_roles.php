<?php

use App\Enums\Permissions;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** v1.3.2: only people who visit outlets (Field Agents, Team Leaders) hold field-work permissions; the System Admin does not. */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', Permissions::FIELD_WORK)->pluck('id', 'slug');
        $admin = DB::table('roles')->where('slug', RoleEnum::SystemAdmin->value)->value('id');
        if ($admin) {
            DB::table('role_permissions')->where('role_id', $admin)->whereIn('permission_id', $ids->values())->delete();
        }
        $tl = DB::table('roles')->where('slug', RoleEnum::TeamLeader->value)->value('id');
        if ($tl) {
            $have = DB::table('role_permissions')->where('role_id', $tl)->pluck('permission_id')->all();
            $rows = $ids->values()->diff($have)->map(fn ($pid) => ['role_id' => $tl, 'permission_id' => $pid])->values()->all();
            if ($rows) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty: re-granting field-work permissions to the administrator is not wanted.
    }
};
