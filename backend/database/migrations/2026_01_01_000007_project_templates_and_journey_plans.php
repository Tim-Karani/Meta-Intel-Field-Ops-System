<?php

use App\Domain\Projects\ProjectSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Project templates, fixed journey plans and permanent/reliever agents.
 *
 *  - project_templates: reusable "how this project runs" setups (5 built-in; administrators can add their own).
 *  - campaigns.template_id / settings: the setup chosen for a project (copied from the template, then tunable).
 *  - agent_profiles.employment_type: permanent (follows a fixed journey plan) or reliever (covers outlets, may add them).
 *  - journey_plans / journey_plan_stops: an agent's recurring outlets by weekday; the scheduler turns them into assignments.
 *  - assignments.source / journey_plan_stop_id: where a visit came from, and (with the date) a guard against duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('slug', 60)->unique();
            $t->string('name', 120);
            $t->text('description')->nullable();
            $t->boolean('is_system')->default(false);
            $t->jsonb('settings');
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::table('campaigns', function (Blueprint $t) {
            $t->foreignUuid('template_id')->nullable()->constrained('project_templates')->nullOnDelete();
            $t->jsonb('settings')->nullable();
        });

        Schema::table('agent_profiles', function (Blueprint $t) {
            $t->string('employment_type', 20)->default('permanent');
        });

        Schema::create('journey_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('agent_id')->constrained('users')->cascadeOnDelete();
            $t->string('name', 160)->nullable();
            $t->string('status', 20)->default('active');          // active | paused | ended
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['campaign_id', 'agent_id']);
            $t->index('agent_id');
        });

        Schema::create('journey_plan_stops', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('journey_plan_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('location_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('activity_id')->nullable()->constrained('activities')->nullOnDelete();
            $t->unsignedSmallInteger('weekday');                  // 1 = Monday ... 7 = Sunday (ISO)
            $t->unsignedSmallInteger('sequence')->default(0);
            $t->time('start_time')->nullable();
            $t->timestamps();
            $t->unique(['journey_plan_id', 'location_id', 'weekday']);
            $t->index('location_id');
        });

        Schema::table('assignments', function (Blueprint $t) {
            $t->string('source', 20)->default('office');          // office | journey_plan | field
            $t->foreignUuid('journey_plan_stop_id')->nullable()->constrained('journey_plan_stops')->nullOnDelete();
            $t->unique(['journey_plan_stop_id', 'scheduled_date']);
        });
        DB::table('assignments')->where('instructions', 'Added in the field by the agent.')->update(['source' => 'field']);

        $now = now();
        foreach (ProjectSettings::PRESETS as $slug => $p) {
            DB::table('project_templates')->insert([
                'id' => (string) Str::uuid(), 'slug' => $slug, 'name' => $p['name'], 'description' => $p['description'], 'is_system' => true,
                'settings' => json_encode(ProjectSettings::normalize($p['settings'])), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $t) {
            $t->dropUnique(['journey_plan_stop_id', 'scheduled_date']);
            $t->dropConstrainedForeignId('journey_plan_stop_id');
            $t->dropColumn('source');
        });
        Schema::dropIfExists('journey_plan_stops');
        Schema::dropIfExists('journey_plans');
        Schema::table('agent_profiles', fn (Blueprint $t) => $t->dropColumn('employment_type'));
        Schema::table('campaigns', function (Blueprint $t) {
            $t->dropConstrainedForeignId('template_id');
            $t->dropColumn('settings');
        });
        Schema::dropIfExists('project_templates');
    }
};
