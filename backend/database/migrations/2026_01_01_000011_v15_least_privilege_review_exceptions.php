<?php

use App\Domain\Projects\ProjectSettings;
use App\Enums\Permissions;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * v1.5
 *  - Least privilege: every role is synced to EXACTLY the permissions Permissions::forRole() lists (earlier migrations only added).
 *  - Review by exception: visits record whether they were approved automatically, or why a person has to look at them.
 *  - Existing projects keep working as before: their position-capture rule stays "first visit" and the sales tile is left as it was.
 *  - Stock accountability for sales projects (stock issued to agents, returned, reconciled against sales).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $t) {
            $t->boolean('auto_approved')->default(false);
            $t->string('review_reason', 30)->nullable();     // new_outlet | gps_warning | review_all, null = nothing to review
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('agent_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 12);                          // issue | return | adjust
            $t->integer('quantity');                         // always positive; the kind decides the direction (adjust may be negative)
            $t->string('note', 255)->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['campaign_id', 'agent_id', 'product_id']);
        });

        $this->keepExistingProjectsAsTheyWere();
        $this->syncTemplates();
        $this->syncRolesAndPermissions();
    }

    /** Projects created before 1.5 had no capture_geo / review rule: keep what they did (agents mapped outlets; sales tile as set). */
    private function keepExistingProjectsAsTheyWere(): void
    {
        foreach (['campaigns', 'project_templates'] as $table) {
            DB::table($table)->when($table === 'project_templates', fn ($q) => $q->where('is_system', false))->orderBy('id')->get(['id', 'settings'])->each(function ($row) use ($table) {
                $s = $row->settings ? json_decode($row->settings, true) : null;
                if ($s === null) {
                    $s = ['tasks' => ['form' => true, 'sales' => true, 'osa' => true, 'merchandising' => true, 'photos' => true]];
                }
                $s['capture_geo'] = $s['capture_geo'] ?? 'first_visit';
                $s['review'] = $s['review'] ?? 'exceptions';
                DB::table($table)->where('id', $row->id)->update(['settings' => json_encode($s)]);
            });
        }
    }

    private function syncTemplates(): void
    {
        $now = now();
        foreach (ProjectSettings::PRESETS as $slug => $p) {
            $settings = json_encode(ProjectSettings::normalize($p['settings']));
            if (DB::table('project_templates')->where('slug', $slug)->exists()) {
                DB::table('project_templates')->where('slug', $slug)->where('is_system', true)->update(['settings' => $settings, 'description' => $p['description'], 'updated_at' => $now]);
            } else {
                DB::table('project_templates')->insert([
                    'id' => (string) Str::uuid(), 'slug' => $slug, 'name' => $p['name'], 'description' => $p['description'], 'is_system' => true,
                    'settings' => $settings, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
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
                continue;
            }
            $want = collect(Permissions::forRole($role))->map(fn ($s) => $ids[$s] ?? null)->filter()->values();
            DB::table('role_permissions')->where('role_id', $roleId)->whereNotIn('permission_id', $want)->delete();
            $have = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission_id');
            $rows = $want->diff($have)->map(fn ($pid) => ['role_id' => $roleId, 'permission_id' => $pid])->values()->all();
            if ($rows) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::table('visits', fn (Blueprint $t) => $t->dropColumn(['auto_approved', 'review_reason']));
    }
};
