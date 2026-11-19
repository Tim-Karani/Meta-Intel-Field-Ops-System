<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.0: an outlet remembers who last changed it (locations.updated_by). Who created it stays in created_by, and every
 * change is still written to the audit log with before and after values. Existing outlets start with their creator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $t) {
            $t->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('locations')->whereNull('updated_by')->whereNotNull('created_by')->update(['updated_by' => DB::raw('created_by')]);
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('updated_by');
        });
    }
};
