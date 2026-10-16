<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outlets may exist without coordinates. The first valid check-in (or the agent who adds the outlet in the field)
 * supplies them; the origin is recorded and the position is flagged for supervisor confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $t) {
            $t->double('latitude')->nullable()->change();
            $t->double('longitude')->nullable()->change();
            $t->string('geo_source', 20)->nullable()->after('longitude');          // manual | first_visit | field_added
            $t->timestamp('geo_captured_at')->nullable()->after('geo_source');
            $t->foreignUuid('geo_captured_by')->nullable()->after('geo_captured_at')->constrained('users')->nullOnDelete();
            $t->double('geo_accuracy_m')->nullable()->after('geo_captured_by');
            $t->boolean('needs_review')->default(false)->after('geo_accuracy_m');
            $t->uuid('client_uuid')->nullable()->unique()->after('needs_review');  // idempotency for field-added outlets
            $t->string('created_via', 20)->default('office')->after('client_uuid'); // office | field
            $t->index('needs_review');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $t) {
            $t->dropIndex(['needs_review']);
            $t->dropConstrainedForeignId('geo_captured_by');
            $t->dropColumn(['geo_source', 'geo_captured_at', 'geo_accuracy_m', 'needs_review', 'client_uuid', 'created_via']);
        });
    }
};
