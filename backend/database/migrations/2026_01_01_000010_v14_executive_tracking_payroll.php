<?php

use App\Enums\Permissions;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** v1.4: live agent tracking, device register, journey-plan change requests, payroll, Executive + HR roles, MSL / share-of-shelf targets. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('is_msl')->default(false);                 // must-sell-list item
            $t->decimal('sos_target_pct', 5, 2)->nullable();       // expected share of shelf (%)
        });

        Schema::table('agent_profiles', function (Blueprint $t) {
            $t->string('pay_type', 12)->default('daily');          // daily | hourly | monthly
            $t->decimal('pay_rate', 12, 2)->default(0);
        });

        Schema::create('agent_pings', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->double('latitude')->nullable();
            $t->double('longitude')->nullable();
            $t->decimal('accuracy_m', 8, 2)->nullable();
            $t->unsignedSmallInteger('battery_pct')->nullable();
            $t->string('device_model', 120)->nullable();
            $t->string('os_version', 60)->nullable();
            $t->string('app_version', 30)->nullable();
            $t->timestampTz('recorded_at');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['user_id', 'recorded_at']);
        });

        Schema::create('user_devices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('device_key', 80);                          // hash of what the device reports about itself
            $t->string('name', 120)->nullable();
            $t->string('platform', 60)->nullable();
            $t->string('last_ip', 64)->nullable();
            $t->timestampTz('first_seen_at');
            $t->timestampTz('last_seen_at');
            $t->timestamps();
            $t->unique(['user_id', 'device_key']);
        });

        Schema::create('jp_change_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('client_uuid')->nullable();
            $t->foreignUuid('journey_plan_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('agent_id')->constrained('users')->cascadeOnDelete();
            $t->string('type', 12);                                // add | remove | move
            $t->foreignUuid('location_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('weekday');                   // ISO 1-7 (for move: the current day)
            $t->unsignedSmallInteger('to_weekday')->nullable();    // move / add target day
            $t->text('reason');
            $t->double('latitude')->nullable();
            $t->double('longitude')->nullable();
            $t->decimal('accuracy_m', 8, 2)->nullable();
            $t->string('proof_disk', 20)->nullable();
            $t->string('proof_path')->nullable();
            $t->string('status', 12)->default('pending');          // pending | approved | rejected
            $t->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
            $t->unique(['agent_id', 'client_uuid']);
            $t->index(['status', 'created_at']);
        });

        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 160);
            $t->date('period_start');
            $t->date('period_end');
            $t->string('status', 12)->default('draft');            // draft | approved | paid
            $t->text('notes')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('paid_at')->nullable();
            $t->timestamps();
            $t->index('status');
        });

        Schema::create('payroll_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('payroll_run_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('days_worked')->default(0);
            $t->decimal('hours_worked', 8, 2)->default(0);
            $t->unsignedSmallInteger('late_days')->default(0);
            $t->unsignedInteger('outlets_visited')->default(0);
            $t->string('pay_type', 12);
            $t->decimal('pay_rate', 12, 2)->default(0);
            $t->decimal('gross', 14, 2)->default(0);
            $t->decimal('adjustment', 14, 2)->default(0);
            $t->string('adjustment_note')->nullable();
            $t->decimal('net', 14, 2)->default(0);
            $t->timestamps();
            $t->unique(['payroll_run_id', 'user_id']);
        });

        $this->syncRolesAndPermissions();
    }

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
                    continue;
                }
                $roleId = DB::table('roles')->insertGetId(['slug' => $role->value, 'name' => $role->label(), 'scope_type' => $role->scopeType(), 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                DB::table('roles')->where('id', $roleId)->update(['name' => $role->label()]);
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
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('jp_change_requests');
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('agent_pings');
        Schema::table('agent_profiles', fn (Blueprint $t) => $t->dropColumn(['pay_type', 'pay_rate']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['is_msl', 'sos_target_pct']));
    }
};
