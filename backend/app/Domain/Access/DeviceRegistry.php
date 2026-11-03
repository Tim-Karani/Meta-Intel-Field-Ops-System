<?php

namespace App\Domain\Access;

use App\Domain\Audit\SecurityEvents;
use App\Domain\Notifications\NotificationService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Remembers which devices a person signs in from. A device the person has not used before is a security event, and their supervisors are told. */
class DeviceRegistry
{
    public static function touch(User $user, Request $request, ?string $name = null, ?string $platform = null): void
    {
        $ua = (string) $request->userAgent();
        $name = $name ?: (string) $request->input('device_name', 'web');
        $key = substr(sha1(mb_strtolower($name).'|'.$ua), 0, 40);
        $now = now();
        $row = DB::table('user_devices')->where('user_id', $user->getKey())->where('device_key', $key)->first();
        if ($row) {
            DB::table('user_devices')->where('id', $row->id)->update(['last_seen_at' => $now, 'last_ip' => $request->ip(), 'updated_at' => $now]);

            return;
        }
        $known = DB::table('user_devices')->where('user_id', $user->getKey())->exists();
        DB::table('user_devices')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->getKey(), 'device_key' => $key, 'name' => mb_substr($name, 0, 120), 'platform' => $platform ? mb_substr($platform, 0, 60) : mb_substr($ua, 0, 60),
            'last_ip' => $request->ip(), 'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        if ($known) {
            SecurityEvents::log('new_device', $user->getKey(), 'warning', ['device' => $name]);
            $boss = DB::table('agent_profiles')->where('user_id', $user->getKey())->first();
            NotificationService::notify(array_filter([$boss->supervisor_id ?? null, $boss->team_leader_id ?? null]), 'device.new', 'New device signed in', "{$user->name} signed in from a device not seen before ($name).", ['user_id' => $user->getKey()]);
        }
    }
}
