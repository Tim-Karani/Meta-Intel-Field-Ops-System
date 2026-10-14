<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = ['agency_name' => config('app.name'), 'currency' => 'KES'];
        foreach ($defaults as $key => $value) {
            // Never overwrite a value an administrator has already set.
            if (!DB::table('agency_settings')->where('key', $key)->exists()) {
                DB::table('agency_settings')->insert(['key' => $key, 'value' => json_encode(['v' => $value]), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
