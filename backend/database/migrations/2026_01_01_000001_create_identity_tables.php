<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('code', 40)->nullable()->unique();
            $t->string('company_name')->nullable();
            $t->string('industry')->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 40)->nullable();
            $t->text('address')->nullable();
            $t->string('status', 20)->default('active');
            $t->text('notes')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index('status');
            $t->index('name');
        });

        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('username', 60)->nullable()->unique();
            $t->string('phone', 40)->nullable();
            $t->string('password');
            $t->string('status', 20)->default('active');
            $t->foreignUuid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $t->boolean('must_change_password')->default(false);
            $t->unsignedSmallInteger('failed_logins')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->string('last_login_ip', 64)->nullable();
            $t->rememberToken();
            $t->timestamps();
            $t->softDeletes();
            $t->index('status');
            $t->index('client_id');
        });

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->uuidMorphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 40)->unique();
            $t->string('name');
            $t->string('scope_type', 20);
            $t->boolean('is_system')->default(true);
            $t->timestamps();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 60)->unique();
            $t->string('module', 40)->index();
            $t->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $t) {
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->primary(['user_id', 'role_id']);
        });

        Schema::create('security_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('user_id')->nullable()->index();
            $t->string('type', 60)->index();
            $t->string('severity', 10)->default('info');
            $t->string('ip', 64)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->uuid('request_id')->nullable();
            $t->jsonb('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->uuid('actor_id')->nullable()->index();
            $t->string('action', 60)->index();
            $t->string('module', 60)->index();
            $t->string('subject_type', 120)->nullable();
            $t->string('subject_id', 64)->nullable();
            $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable();
            $t->jsonb('meta')->nullable();
            $t->string('ip', 64)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->uuid('request_id')->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['subject_type', 'subject_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Append-only: not even the application can rewrite history.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_logs_immutable() RETURNS trigger AS $$
                BEGIN RAISE EXCEPTION 'audit_logs is append-only'; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER audit_logs_no_update BEFORE UPDATE OR DELETE ON audit_logs
                    FOR EACH ROW EXECUTE FUNCTION audit_logs_immutable();
            SQL);
        }

        Schema::create('jobs', function (Blueprint $t) {
            $t->id();
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs; DROP FUNCTION IF EXISTS audit_logs_immutable();');
        }
        foreach (['failed_jobs', 'jobs', 'audit_logs', 'security_events', 'user_roles', 'role_permissions', 'permissions', 'roles', 'personal_access_tokens', 'password_reset_tokens', 'users', 'clients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
