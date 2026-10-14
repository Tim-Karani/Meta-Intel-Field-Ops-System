<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_contacts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('title')->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 40)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->timestamps();
        });

        Schema::create('client_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->string('disk', 20);
            $t->string('path');
            $t->string('mime', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('forms', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('group_id')->index();   // all versions of one form share group_id
            $t->unsignedInteger('version')->default(1);
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('status', 20)->default('draft');   // draft | published | archived
            $t->boolean('requires_live_capture')->default(false);
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['group_id', 'version']);
        });

        Schema::create('form_sections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('form_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->unsignedInteger('position')->default(0);
            $t->timestamps();
        });

        Schema::create('form_questions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('form_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('section_id')->constrained('form_sections')->cascadeOnDelete();
            $t->string('type', 30);
            $t->string('label');
            $t->text('help_text')->nullable();
            $t->boolean('required')->default(false);
            $t->boolean('evidence_required')->default(false);
            $t->unsignedInteger('position')->default(0);
            $t->jsonb('validation')->nullable();
            $t->jsonb('scoring')->nullable();
            $t->jsonb('settings')->nullable();
            $t->timestamps();
        });

        Schema::create('form_options', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('question_id')->constrained('form_questions')->cascadeOnDelete();
            $t->string('label');
            $t->string('value');
            $t->decimal('score', 8, 2)->nullable();
            $t->unsignedInteger('position')->default(0);
            $t->timestamps();
        });

        Schema::create('form_conditions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('question_id')->constrained('form_questions')->cascadeOnDelete();
            $t->foreignUuid('depends_on_question_id')->constrained('form_questions')->cascadeOnDelete();
            $t->string('operator', 20);   // equals | not_equals | contains | gt | lt | answered
            $t->jsonb('value')->nullable();
            $t->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('client_id')->constrained()->restrictOnDelete();
            $t->string('code', 40)->unique();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('campaign_type', 60)->nullable();
            $t->text('objectives')->nullable();
            $t->decimal('budget', 14, 2)->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('status', 20)->default('draft');
            $t->text('status_reason')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index('client_id');
            $t->index('status');
        });

        Schema::create('activities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('form_id')->nullable()->constrained('forms')->nullOnDelete();
            $t->string('name');
            $t->string('activity_type', 60)->nullable();
            $t->text('description')->nullable();
            $t->unsignedInteger('target')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('status', 20)->default('planned');
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('campaign_id');
        });

        Schema::create('campaign_users', function (Blueprint $t) {
            $t->foreignUuid('campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('role_label', 60)->nullable();
            $t->primary(['campaign_id', 'user_id']);
        });

        Schema::create('campaign_forms', function (Blueprint $t) {
            $t->foreignUuid('campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('form_id')->constrained()->cascadeOnDelete();
            $t->primary(['campaign_id', 'form_id']);
        });

        Schema::create('campaign_kpis', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('campaign_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('metric', 40);
            $t->decimal('target', 14, 2);
            $t->string('unit', 20)->nullable();
            $t->timestamps();
        });

        Schema::create('territories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('code', 40)->nullable()->unique();
            $t->string('region')->nullable();
            $t->text('description')->nullable();
            $t->string('status', 20)->default('active');
            $t->timestamps();
        });

        Schema::create('locations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('client_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('territory_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('code', 40)->nullable()->unique();
            $t->string('type', 30)->default('retail_store');
            $t->text('address')->nullable();
            $t->string('county', 80)->nullable();
            $t->string('town', 80)->nullable();
            $t->double('latitude');
            $t->double('longitude');
            $t->unsignedInteger('geofence_radius_m')->default(100);
            $t->string('contact_name')->nullable();
            $t->string('contact_phone', 40)->nullable();
            $t->string('opening_hours')->nullable();
            $t->string('status', 20)->default('active');
            $t->text('notes')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['county', 'town']);
            $t->index('territory_id');
        });

        Schema::create('campaign_locations', function (Blueprint $t) {
            $t->foreignUuid('campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('location_id')->constrained()->cascadeOnDelete();
            $t->string('visit_frequency', 30)->nullable();
            $t->primary(['campaign_id', 'location_id']);
        });

        Schema::create('routes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('territory_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('code', 40)->nullable()->unique();
            $t->text('description')->nullable();
            $t->string('status', 20)->default('active');
            $t->timestamps();
        });

        Schema::create('route_locations', function (Blueprint $t) {
            $t->foreignUuid('route_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('location_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('position')->default(0);
            $t->primary(['route_id', 'location_id']);
        });

        Schema::create('teams', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('region')->nullable();
            $t->text('description')->nullable();
            $t->foreignUuid('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('team_members', function (Blueprint $t) {
            $t->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('joined_at')->useCurrent();
            $t->primary(['team_id', 'user_id']);
        });

        Schema::create('campaign_teams', function (Blueprint $t) {
            $t->foreignUuid('campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $t->primary(['campaign_id', 'team_id']);
        });

        Schema::create('agent_profiles', function (Blueprint $t) {
            $t->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $t->string('agent_type', 30)->default('merchandiser');
            $t->string('agent_code', 40)->nullable()->unique();
            $t->foreignUuid('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('region')->nullable();
            $t->timestamps();
            $t->index('supervisor_id');
        });

        Schema::create('brands', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('client_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('product_categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name')->unique();
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('client_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('brand_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $t->string('name');
            $t->string('sku', 60)->nullable()->unique();
            $t->decimal('unit_price', 12, 2)->nullable();
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->softDeletes();
            $t->index('client_id');
        });

        Schema::create('campaign_products', function (Blueprint $t) {
            $t->foreignUuid('campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $t->primary(['campaign_id', 'product_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_dates_ck CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date)');
            DB::statement('ALTER TABLE activities ADD CONSTRAINT activities_dates_ck CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date)');
            DB::statement('ALTER TABLE locations ADD CONSTRAINT locations_lat_ck CHECK (latitude BETWEEN -90 AND 90)');
            DB::statement('ALTER TABLE locations ADD CONSTRAINT locations_lng_ck CHECK (longitude BETWEEN -180 AND 180)');
            DB::statement('ALTER TABLE locations ADD CONSTRAINT locations_radius_ck CHECK (geofence_radius_m BETWEEN 10 AND 5000)');
            DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_budget_ck CHECK (budget IS NULL OR budget >= 0)');
        }
    }

    public function down(): void
    {
        foreach (['campaign_products', 'products', 'product_categories', 'brands', 'agent_profiles', 'campaign_teams', 'team_members', 'teams',
            'route_locations', 'routes', 'campaign_locations', 'locations', 'territories', 'campaign_kpis', 'campaign_forms', 'campaign_users',
            'activities', 'campaigns', 'form_conditions', 'form_options', 'form_questions', 'form_sections', 'forms', 'client_documents', 'client_contacts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
