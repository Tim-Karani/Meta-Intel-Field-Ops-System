<?php

use App\Enums\Permissions;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.3
 *  - Team Leader role (row + permissions) and agent_profiles.team_leader_id for the Supervisor > Team Leader > Agent chain.
 *  - attendance.work_started_at / work_ended_at / worked_minutes: working time is measured from the FIRST outlet check-in of
 *    the day, never from the clock-in (an agent can clock in from home).
 *  - report_schedules / report_runs: reports emailed on a schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $t) {
            $t->foreignUuid('team_leader_id')->nullable()->constrained('users')->nullOnDelete();
            $t->index('team_leader_id');
        });

        Schema::table('attendance', function (Blueprint $t) {
            $t->timestamp('work_started_at')->nullable();
            $t->timestamp('work_ended_at')->nullable();
            $t->unsignedInteger('worked_minutes')->nullable();
            $t->unsignedSmallInteger('outlets_visited')->default(0);
        });

        // Share of shelf (SOS): our facings / all facings counted on the shelf.
        Schema::table('merchandising_records', function (Blueprint $t) {
            $t->unsignedSmallInteger('our_facings')->nullable();
            $t->unsignedSmallInteger('total_facings')->nullable();
        });

        Schema::create('report_schedules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('report_type', 40);
            $t->json('filters')->nullable();            // campaign_id, client_id, agent_id ...
            $t->string('period', 20)->default('yesterday');   // yesterday | last_7_days | last_30_days | month_to_date | last_month
            $t->string('frequency', 12)->default('daily');    // daily | weekly | monthly
            $t->unsignedTinyInteger('weekday')->nullable();   // 1 (Mon) .. 7 (Sun) for weekly
            $t->unsignedTinyInteger('month_day')->nullable(); // 1..28 for monthly
            $t->string('send_time', 5)->default('07:00');     // HH:MM, app timezone
            $t->json('recipients');                           // list of email addresses
            $t->boolean('is_active')->default(true);
            $t->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();   // the report is built with THIS user's access
            $t->timestamp('last_run_at')->nullable();
            $t->string('last_status', 20)->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
            $t->index(['is_active', 'frequency']);
        });

        Schema::create('report_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('schedule_id')->constrained('report_schedules')->cascadeOnDelete();
            $t->string('status', 20);                          // sent | failed | empty
            $t->unsignedInteger('row_count')->default(0);
            $t->unsignedSmallInteger('recipient_count')->default(0);
            $t->text('error')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        $this->syncRolesAndPermissions();
    }

    /** Same additive approach as migration 000006, but it also CREATES role rows that do not exist yet. */
    private function syncRolesAndPermissions(): void
    {
        $now = now();
        foreach (Permissions::ALL as $module => $slugs) {
            foreach ($slugs as $slug) {
                if (!DB::table('permissions')->where('slug', $slug)->exists()) {
                    DB::table('permissions')->insert(['slug' => $slug, 'module' => $module, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
        $ids = DB::table('permissions')->pluck('id', 'slug');

        foreach (RoleEnum::cases() as $role) {
            $roleId = DB::table('roles')->where('slug', $role->value)->value('id');
            if (!$roleId) {
                if (DB::table('roles')->count() === 0) {
                    continue;   // fresh install: RolePermissionSeeder creates every role
                }
                $roleId = DB::table('roles')->insertGetId(['slug' => $role->value, 'name' => $role->label(), 'scope_type' => $role->scopeType(), 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            $have = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission_id')->all();
            $rows = collect(Permissions::forRole($role))->map(fn ($s) => $ids[$s] ?? null)->filter()->diff($have)
                ->map(fn ($pid) => ['role_id' => $roleId, 'permission_id' => $pid])->values()->all();
            if ($rows) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::table('merchandising_records', fn (Blueprint $t) => $t->dropColumn(['our_facings', 'total_facings']));
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('report_schedules');
        Schema::table('attendance', fn (Blueprint $t) => $t->dropColumn(['work_started_at', 'work_ended_at', 'worked_minutes', 'outlets_visited']));
        Schema::table('agent_profiles', function (Blueprint $t) {
            $t->dropConstrainedForeignId('team_leader_id');
        });
    }
};
