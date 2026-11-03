<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\DeviceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The phone reports where it is, its battery and what it is: powers the live agent view. Sent when the app opens, after sync and every few minutes while open. */
class AgentPingController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'], 'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'battery_pct' => ['nullable', 'integer', 'between:0,100'],
            'device_model' => ['nullable', 'string', 'max:120'], 'os_version' => ['nullable', 'string', 'max:60'], 'app_version' => ['nullable', 'string', 'max:30'],
            'recorded_at' => ['nullable', 'date'],
        ]);
        $at = isset($d['recorded_at']) ? \Carbon\Carbon::parse($d['recorded_at']) : now();
        if ($at->gt(now()->addMinutes(5))) {
            $at = now();    // a clock running ahead cannot place the agent in the future
        }
        $user = $request->user();
        DB::table('agent_pings')->insert(['user_id' => $user->getKey(), 'latitude' => $d['latitude'] ?? null, 'longitude' => $d['longitude'] ?? null, 'accuracy_m' => $d['accuracy_m'] ?? null,
            'battery_pct' => $d['battery_pct'] ?? null, 'device_model' => $d['device_model'] ?? null, 'os_version' => $d['os_version'] ?? null, 'app_version' => $d['app_version'] ?? null,
            'recorded_at' => $at, 'created_at' => now()]);
        DeviceRegistry::touch($user, $request, $d['device_model'] ?? null, $d['os_version'] ?? null);

        return $this->ok(null, 'Recorded.');
    }
}
