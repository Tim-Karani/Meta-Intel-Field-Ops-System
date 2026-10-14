<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('campaign_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('activity_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('agent_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('form_id')->nullable()->constrained('forms')->nullOnDelete();
            $t->date('scheduled_date');
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->text('instructions')->nullable();
            $t->unsignedInteger('target')->nullable();
            $t->string('status', 20)->default('pending');
            $t->text('cancel_reason')->nullable();
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['agent_id', 'scheduled_date']);
            $t->index('campaign_id');
            $t->index(['status', 'scheduled_date']);
            $t->index('location_id');
        });

        Schema::create('attendance', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('client_uuid')->nullable();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('assignment_id')->nullable()->constrained()->nullOnDelete();
            $t->date('attendance_date');
            $t->timestampTz('clock_in_at')->nullable();
            $t->double('clock_in_lat')->nullable();
            $t->double('clock_in_lng')->nullable();
            $t->decimal('clock_in_accuracy_m', 8, 2)->nullable();
            $t->timestampTz('clock_out_at')->nullable();
            $t->double('clock_out_lat')->nullable();
            $t->double('clock_out_lng')->nullable();
            $t->string('status', 20)->default('present');   // present | late | absent
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'attendance_date']);
            $t->unique(['user_id', 'client_uuid']);
        });

        Schema::create('visits', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('client_uuid')->nullable();
            $t->foreignUuid('assignment_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('campaign_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('activity_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('agent_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('form_id')->nullable()->constrained('forms')->nullOnDelete();
            $t->string('status', 20)->default('scheduled');
            $t->date('visit_date');
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->timestampTz('submitted_at')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('review_note')->nullable();
            $t->string('gps_outcome', 20)->nullable();
            $t->string('device_id', 120)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['agent_id', 'client_uuid']);
            $t->index(['status', 'visit_date']);
            $t->index(['campaign_id', 'visit_date']);
            $t->index(['agent_id', 'visit_date']);
            $t->index('location_id');
            $t->index('assignment_id');
        });

        foreach (['visit_checkins', 'visit_checkouts'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->uuid('id')->primary();
                $t->foreignUuid('visit_id')->unique()->constrained()->cascadeOnDelete();
                $t->double('latitude');
                $t->double('longitude');
                $t->decimal('accuracy_m', 8, 2)->nullable();
                $t->timestampTz('device_ts')->nullable();
                $t->timestampTz('server_ts');
                $t->decimal('distance_m', 10, 2);
                $t->string('outcome', 20);
                $t->jsonb('reasons')->nullable();
                $t->boolean('mock_flag')->nullable();
                if ($table === 'visit_checkins') {
                    $t->string('provider', 30)->nullable();
                    $t->string('device_id', 120)->nullable();
                    $t->string('network_type', 30)->nullable();
                    $t->string('app_version', 30)->nullable();
                }
                $t->timestamps();
            });
        }

        Schema::create('visit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('visit_id')->constrained()->cascadeOnDelete();
            $t->string('type', 40);
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20)->nullable();
            $t->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('note')->nullable();
            $t->jsonb('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['visit_id', 'created_at']);
        });

        Schema::create('visit_responses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('visit_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('question_id')->constrained('form_questions')->restrictOnDelete();
            $t->jsonb('value')->nullable();
            $t->timestamps();
            $t->unique(['visit_id', 'question_id']);
        });

        Schema::create('sales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('client_uuid')->nullable();
            $t->foreignUuid('visit_id')->constrained()->cascadeOnDelete();
            $t->string('payment_method', 20)->nullable();   // cash | mpesa | card | credit | other
            $t->decimal('total_value', 14, 2)->default(0);
            $t->timestampTz('captured_at');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['visit_id', 'client_uuid']);
            $t->index('visit_id');
        });

        Schema::create('sales_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('sale_id')->constrained('sales')->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->decimal('unit_price', 12, 2);
            $t->decimal('line_total', 14, 2);
            $t->boolean('is_promo')->default(false);
            $t->timestamps();
            $t->index('product_id');
        });

        Schema::create('osa_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('client_uuid')->nullable();
            $t->foreignUuid('visit_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->boolean('available')->default(true);
            $t->boolean('out_of_stock')->default(false);
            $t->unsignedInteger('shelf_quantity')->nullable();
            $t->unsignedInteger('backroom_quantity')->nullable();
            $t->unsignedInteger('facings')->nullable();
            $t->decimal('share_of_shelf', 5, 2)->nullable();
            $t->decimal('price_on_shelf', 12, 2)->nullable();
            $t->boolean('display_compliant')->nullable();
            $t->boolean('visibility_ok')->nullable();
            $t->timestampTz('captured_at');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['visit_id', 'client_uuid']);
            $t->unique(['visit_id', 'product_id']);
        });

        Schema::create('merchandising_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('client_uuid')->nullable();
            $t->foreignUuid('visit_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('posm_present')->nullable();
            $t->string('posm_type', 80)->nullable();
            $t->string('shelf_position', 80)->nullable();
            $t->boolean('planogram_compliant')->nullable();
            $t->boolean('price_compliant')->nullable();
            $t->boolean('competitor_present')->nullable();
            $t->text('competitor_notes')->nullable();
            $t->timestampTz('captured_at');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['visit_id', 'client_uuid']);
            $t->index('visit_id');
        });

        Schema::create('photos', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('client_uuid')->nullable();
            $t->foreignUuid('visit_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('question_id')->nullable()->constrained('form_questions')->nullOnDelete();
            $t->foreignUuid('taken_by')->constrained('users')->restrictOnDelete();
            $t->string('kind', 20)->default('evidence');   // evidence | signature
            $t->string('disk', 20);
            $t->string('path');
            $t->string('original_name')->nullable();
            $t->string('mime', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->string('sha256', 64);
            $t->timestampTz('captured_at')->nullable();
            $t->double('latitude')->nullable();
            $t->double('longitude')->nullable();
            $t->string('review_status', 20)->default('pending');   // pending | accepted | rejected
            $t->text('review_note')->nullable();
            $t->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['taken_by', 'client_uuid']);
            $t->index('visit_id');
            $t->index('review_status');
            $t->index('sha256');
        });

        Schema::create('issues', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('client_uuid')->nullable();
            $t->foreignUuid('visit_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('campaign_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('activity_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('location_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('category', 60)->nullable();
            $t->string('priority', 20)->default('medium');
            $t->string('status', 20)->default('open');
            $t->foreignUuid('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('escalated_at')->nullable();
            $t->text('resolution')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['reported_by', 'client_uuid']);
            $t->index(['status', 'priority']);
            $t->index('campaign_id');
            $t->index('assigned_to');
        });

        Schema::create('issue_comments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('issue_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 20)->default('comment');   // comment | status | assignment
            $t->text('body');
            $t->timestamps();
            $t->index('issue_id');
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('type', 60);
            $t->string('title');
            $t->text('body')->nullable();
            $t->jsonb('data')->nullable();
            $t->timestampTz('read_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'read_at']);
        });

        Schema::create('report_exports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('report_type', 60);
            $t->string('format', 10);
            $t->jsonb('filters')->nullable();
            $t->unsignedInteger('row_count')->default(0);
            $t->string('ip', 64)->nullable();
            $t->timestamps();
        });

        Schema::create('agency_settings', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->jsonb('value');
            $t->timestamps();
        });

        Schema::create('gps_rules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('campaign_id')->nullable()->unique()->constrained()->cascadeOnDelete();   // null = agency default
            $t->jsonb('config');
            $t->timestamps();
        });

        // Reserved for the mobile sync endpoint; created now so the schema stays stable.
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('entity', 40);
            $t->uuid('client_uuid');
            $t->uuid('server_id')->nullable();
            $t->string('payload_hash', 64);
            $t->jsonb('response')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'entity', 'client_uuid']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_items ADD CONSTRAINT sales_items_qty_ck CHECK (quantity > 0 AND unit_price >= 0)');
            DB::statement('ALTER TABLE osa_records ADD CONSTRAINT osa_share_ck CHECK (share_of_shelf IS NULL OR share_of_shelf BETWEEN 0 AND 100)');
            DB::statement('ALTER TABLE visit_checkins ADD CONSTRAINT checkin_lat_ck CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)');
            DB::statement('ALTER TABLE visit_checkouts ADD CONSTRAINT checkout_lat_ck CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)');
            // At most one live visit per assignment (idempotent starts, no parallel visits).
            DB::statement("CREATE UNIQUE INDEX visits_one_active_per_assignment ON visits (assignment_id) WHERE status IN ('scheduled','started','completed')");
        }
    }

    public function down(): void
    {
        foreach (['idempotency_keys', 'gps_rules', 'agency_settings', 'report_exports', 'notifications', 'issue_comments', 'issues', 'photos',
            'merchandising_records', 'osa_records', 'sales_items', 'sales', 'visit_responses', 'visit_events', 'visit_checkouts',
            'visit_checkins', 'visits', 'attendance', 'assignments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
