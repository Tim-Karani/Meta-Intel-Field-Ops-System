<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting structure: outlets belong to a channel (a retail chain or a trade group), categories can have
 * sub-categories, outlets carry region/locality/distributor, products carry pack details. Everything is optional,
 * so existing data keeps working and is simply reported as "Unassigned" until filled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('channels')) {
            Schema::create('channels', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name')->unique();
                $t->string('channel_group', 80)->nullable();   // wider trade group, e.g. "Modern trade", "Wholesale"
                $t->string('code', 40)->nullable();
                $t->string('contact_name')->nullable();
                $t->string('contact_phone', 40)->nullable();
                $t->string('status', 20)->default('active');
                $t->text('notes')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        Schema::table('locations', function (Blueprint $t) {
            if (!Schema::hasColumn('locations', 'channel_id')) {
                $t->foreignUuid('channel_id')->nullable()->constrained('channels')->nullOnDelete();
                $t->index('channel_id');
            }
            foreach (['region' => 80, 'locality' => 80, 'distributor' => 120] as $col => $len) {
                if (!Schema::hasColumn('locations', $col)) {
                    $t->string($col, $len)->nullable();
                }
            }
        });

        Schema::table('product_categories', function (Blueprint $t) {
            if (!Schema::hasColumn('product_categories', 'parent_id')) {
                $t->foreignUuid('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            }
        });

        Schema::table('products', function (Blueprint $t) {
            if (!Schema::hasColumn('products', 'pack_size')) {
                $t->string('pack_size', 60)->nullable();
            }
            if (!Schema::hasColumn('products', 'units_per_case')) {
                $t->unsignedInteger('units_per_case')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['pack_size', 'units_per_case']));
        Schema::table('product_categories', fn (Blueprint $t) => $t->dropConstrainedForeignId('parent_id'));
        Schema::table('locations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('channel_id');
            $t->dropColumn(['region', 'locality', 'distributor']);
        });
        Schema::dropIfExists('channels');
    }
};
