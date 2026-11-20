<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->where('slug', 'operations_manager')->update(['name' => 'Agency']);
    }

    public function down(): void
    {
        DB::table('roles')->where('slug', 'operations_manager')->update(['name' => 'Agency / Operations Manager']);
    }
};
