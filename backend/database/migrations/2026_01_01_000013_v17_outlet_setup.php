<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Outlet setup the office can edit: outlet types and sub-types, outlet statuses, distributors and a four-level
 * geography (country > region > locality > area). Outlets get the matching links plus a few trade details.
 * Additive: existing outlets keep working and are linked to the defaults below.
 */
return new class extends Migration
{
    private const TYPES = [
        'retail_store' => 'Retail store', 'supermarket' => 'Supermarket', 'wholesale' => 'Wholesale', 'kiosk' => 'Kiosk', 'pharmacy' => 'Pharmacy',
        'bar_restaurant' => 'Bar / restaurant', 'market' => 'Market', 'event_venue' => 'Event venue', 'other' => 'Other',
    ];

    public function up(): void
    {
        Schema::create('outlet_types', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('slug', 60)->unique();
            $t->string('name', 120)->unique();
            $t->string('status', 20)->default('active');
            $t->timestamps();
        });
        Schema::create('outlet_subtypes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('outlet_type_id')->constrained('outlet_types')->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['outlet_type_id', 'name']);
        });
        Schema::create('outlet_statuses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 80)->unique();
            $t->string('tone', 12)->default('neutral');          // ok | warn | info | danger | neutral
            $t->boolean('counts_as_active')->default(true);      // can outlets in this status be visited and planned?
            $t->boolean('is_default')->default(false);           // given to new outlets
            $t->timestamps();
        });
        Schema::create('distributors', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name')->unique();
            $t->string('code', 40)->nullable();
            $t->string('contact_name')->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('email')->nullable();
            $t->string('status', 20)->default('active');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('geo_areas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('parent_id')->nullable();
            $t->string('level', 12);                              // country | region | locality | area
            $t->string('name', 120);
            $t->string('code', 40)->nullable();
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->index(['level', 'parent_id']);
        });
        // Self-reference is added afterwards: Postgres needs the primary key to exist first.
        Schema::table('geo_areas', fn (Blueprint $t) => $t->foreign('parent_id')->references('id')->on('geo_areas')->restrictOnDelete());

        Schema::table('locations', function (Blueprint $t) {
            $t->foreignUuid('outlet_subtype_id')->nullable()->constrained('outlet_subtypes')->nullOnDelete();
            $t->foreignUuid('outlet_status_id')->nullable()->constrained('outlet_statuses')->nullOnDelete();
            $t->foreignUuid('distributor_id')->nullable()->constrained('distributors')->nullOnDelete();
            $t->foreignUuid('geo_area_id')->nullable()->constrained('geo_areas')->nullOnDelete();
            $t->string('phone', 40)->nullable();
            $t->string('email')->nullable();
            $t->string('shop_no', 60)->nullable();
            $t->string('market_name')->nullable();
            $t->string('setting', 12)->nullable();                // urban | peri_urban | rural
        });

        $now = now();
        foreach (self::TYPES as $slug => $name) {
            DB::table('outlet_types')->insert(['id' => (string) Str::uuid(), 'slug' => $slug, 'name' => $name, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        }
        $active = (string) Str::uuid();
        $closed = (string) Str::uuid();
        DB::table('outlet_statuses')->insert([
            ['id' => $active, 'name' => 'Active', 'tone' => 'ok', 'counts_as_active' => true, 'is_default' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) Str::uuid(), 'name' => 'Temporarily closed', 'tone' => 'warn', 'counts_as_active' => false, 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $closed, 'name' => 'Permanently closed', 'tone' => 'danger', 'counts_as_active' => false, 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('locations')->where('status', 'active')->update(['outlet_status_id' => $active]);
        DB::table('locations')->where('status', '!=', 'active')->update(['outlet_status_id' => $closed]);

        foreach (DB::table('locations')->whereNotNull('distributor')->where('distributor', '!=', '')->distinct()->pluck('distributor') as $name) {
            $id = (string) Str::uuid();
            DB::table('distributors')->insert(['id' => $id, 'name' => $name, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('locations')->where('distributor', $name)->update(['distributor_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $t) {
            foreach (['outlet_subtype_id', 'outlet_status_id', 'distributor_id', 'geo_area_id'] as $c) {
                $t->dropConstrainedForeignId($c);
            }
            $t->dropColumn(['phone', 'email', 'shop_no', 'market_name', 'setting']);
        });
        foreach (['geo_areas', 'distributors', 'outlet_statuses', 'outlet_subtypes', 'outlet_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
