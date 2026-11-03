<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.8: "may add an outlet in the field" becomes a switch on each person (agent_profiles.can_add_outlets) instead of a
 * project-wide rule. Agents who could add outlets under the old project settings keep that ability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $t) {
            $t->boolean('can_add_outlets')->default(false);
        });

        // Keep today's behaviour: anyone who could add outlets in at least one of their projects keeps the switch on.
        $reliever = DB::table('agent_profiles')->where('employment_type', 'reliever')->pluck('user_id')->all();
        $all = DB::table('campaigns')->get(['id', 'settings'])->filter(function ($c) {
            $s = is_string($c->settings) ? json_decode($c->settings, true) : (array) $c->settings;
            return ($s['outlet_adders'] ?? 'all') === 'all';
        })->pluck('id')->all();
        $rel = DB::table('campaigns')->get(['id', 'settings'])->filter(function ($c) {
            $s = is_string($c->settings) ? json_decode($c->settings, true) : (array) $c->settings;
            return ($s['outlet_adders'] ?? 'all') === 'relievers';
        })->pluck('id')->all();

        $onAll = DB::table('campaign_users')->whereIn('campaign_id', $all)->pluck('user_id')->all();
        $onRel = DB::table('campaign_users')->whereIn('campaign_id', $rel)->pluck('user_id')->all();
        $keep = array_unique(array_merge($onAll, array_intersect($onRel, $reliever)));
        if ($keep) {
            DB::table('agent_profiles')->whereIn('user_id', $keep)->update(['can_add_outlets' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('agent_profiles', fn (Blueprint $t) => $t->dropColumn('can_add_outlets'));
    }
};
