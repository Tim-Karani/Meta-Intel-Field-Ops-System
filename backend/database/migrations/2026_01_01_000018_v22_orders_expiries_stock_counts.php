<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Orders (quantity and value), expiries (expiry date and quantity) and stock counts (shelf + back room), all captured inside a visit. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('client_uuid')->nullable();
            $t->foreignUuid('visit_id')->constrained()->cascadeOnDelete();
            $t->decimal('total_value', 14, 2)->default(0);
            $t->timestampTz('captured_at');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['visit_id', 'client_uuid']);
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->decimal('unit_price', 12, 2);
            $t->decimal('line_total', 14, 2);
            $t->timestamps();
        });
        Schema::create('expiry_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('client_uuid')->nullable();
            $t->foreignUuid('visit_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->date('expiry_date');
            $t->unsignedInteger('quantity');
            $t->string('batch_no', 60)->nullable();
            $t->timestampTz('captured_at');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['visit_id', 'client_uuid']);
            $t->index(['expiry_date']);
        });
        Schema::create('stock_counts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('client_uuid')->nullable();
            $t->foreignUuid('visit_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('shelf_quantity')->default(0);
            $t->unsignedInteger('backroom_quantity')->default(0);
            $t->timestampTz('captured_at');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['visit_id', 'product_id']);
        });
    }

    public function down(): void
    {
        foreach (['stock_counts', 'expiry_records', 'order_items', 'orders'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
