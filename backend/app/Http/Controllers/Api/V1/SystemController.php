<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditService;
use App\Models\AgencySetting;
use App\Models\Campaign;
use App\Models\GpsRule;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SystemController extends ApiController
{
    /** Public liveness probe: deliberately reveals nothing but up/down. */
    public function health(): JsonResponse
    {
        try {
            DB::select('select 1');
            $db = true;
        } catch (\Throwable) {
            $db = false;
        }

        return response()->json(['status' => $db ? 'ok' : 'degraded', 'time' => now()->toIso8601String()], $db ? 200 : 503);
    }

    /** Administrator view of the same checks, with the detail an operator needs. */
    public function details(Request $request): JsonResponse
    {
        $checks = [];
        $run = function (string $name, callable $fn) use (&$checks) {
            $t = microtime(true);
            try {
                $detail = $fn();
                $checks[$name] = ['ok' => true, 'detail' => $detail, 'ms' => round((microtime(true) - $t) * 1000, 1)];
            } catch (\Throwable $e) {
                $checks[$name] = ['ok' => false, 'detail' => 'Check failed', 'ms' => round((microtime(true) - $t) * 1000, 1)];
            }
        };

        $run('database', fn () => DB::selectOne('select version() as v')->v ?? 'connected');
        $run('migrations', fn () => DB::table('migrations')->count().' applied');
        $run('evidence_storage', function () {
            $disk = Storage::disk(config('field.uploads.disk'));
            $probe = '.healthcheck-'.bin2hex(random_bytes(4));
            $disk->put($probe, 'ok');
            $ok = $disk->get($probe) === 'ok';
            $disk->delete($probe);
            if (!$ok) {
                throw new \RuntimeException('read-back mismatch');
            }

            return 'writable';
        });
        $run('queue', fn () => DB::table('jobs')->count().' pending, '.DB::table('failed_jobs')->count().' failed');
        $run('scheduler', function () {
            $beat = \Illuminate\Support\Facades\Cache::get('scheduler:heartbeat');
            if (!$beat || now()->diffInMinutes(\Carbon\Carbon::parse($beat)) > 5) {
                throw new \RuntimeException('no recent heartbeat');
            }

            return 'heartbeat '.$beat;
        });

        return $this->ok([
            'status' => collect($checks)->every(fn ($c) => $c['ok']) ? 'ok' : 'degraded',
            'checks' => $checks,
            'environment' => app()->environment(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'debug' => (bool) config('app.debug'),
            'time' => now()->toIso8601String(),
            'counts' => [
                'users' => DB::table('users')->whereNull('deleted_at')->count(),
                'campaigns' => DB::table('campaigns')->whereNull('deleted_at')->count(),
                'visits' => DB::table('visits')->count(),
                'photos' => DB::table('photos')->count(),
                'audit_logs' => DB::table('audit_logs')->count(),
            ],
        ]);
    }

    public function settings(): JsonResponse
    {
        return $this->ok($this->currentSettings());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_name' => ['sometimes', 'string', 'max:120'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);
        $before = $this->currentSettings();
        foreach ($data as $key => $value) {
            AgencySetting::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
        }
        AuditService::record('settings.updated', 'system', null, $before, $this->currentSettings());

        return $this->ok($this->currentSettings(), 'Settings saved.');
    }

    /** GET /gps-rules[?campaign_id=] — effective rules: config defaults overlaid with overrides. */
    public function gpsRules(Request $request): JsonResponse
    {
        $campaignId = $this->campaignId($request);

        return $this->ok([
            'defaults' => config('field.gps'),
            'agency_override' => GpsRule::query()->whereNull('campaign_id')->value('config') ?? (object) [],
            'campaign_override' => $campaignId ? (GpsRule::query()->where('campaign_id', $campaignId)->value('config') ?? (object) []) : null,
        ]);
    }

    public function updateGpsRules(Request $request): JsonResponse
    {
        $campaignId = $this->campaignId($request);
        $data = $request->validate([
            'default_radius_m' => ['sometimes', 'integer', 'between:10,5000'],
            'accuracy_poor_m' => ['sometimes', 'integer', 'between:10,1000'],
            'accuracy_unusable_m' => ['sometimes', 'integer', 'between:50,5000'],
            'accuracy_credit_cap_m' => ['sometimes', 'integer', 'between:0,500'],
            'near_multiplier' => ['sometimes', 'numeric', 'between:1,10'],
            'far_distance_m' => ['sometimes', 'integer', 'between:100,50000'],
            'far_action' => ['sometimes', 'in:reject,flag'],
            'skew_warn_s' => ['sometimes', 'integer', 'between:30,3600'],
            'skew_suspicious_s' => ['sometimes', 'integer', 'between:60,86400'],
            'max_speed_kmh' => ['sometimes', 'integer', 'between:20,1000'],
            'suspicious_threshold' => ['sometimes', 'integer', 'between:1,10'],
        ]);
        $before = GpsRule::query()->where('campaign_id', $campaignId)->value('config');
        GpsRule::query()->updateOrCreate(['campaign_id' => $campaignId], ['config' => $data]);
        AuditService::record('gps_rules.updated', 'system', null, $before, $data, ['campaign_id' => $campaignId]);

        return $this->ok(['config' => $data], 'GPS rules saved.');
    }

    private function campaignId(Request $request): ?string
    {
        $id = $request->query('campaign_id') ?? $request->input('campaign_id');
        if ($id === null || $id === '') {
            return null;
        }
        $this->assertUuid((string) $id);
        \App\Domain\Access\AccessScope::scope(Campaign::query(), $request->user())->findOrFail($id);

        return (string) $id;
    }

    private function currentSettings(): array
    {
        $stored = AgencySetting::query()->get()->mapWithKeys(fn ($s) => [$s->key => $s->value['v'] ?? null])->all();

        return $stored + ['agency_name' => config('app.name'), 'contact_email' => null, 'currency' => 'KES'];
    }
}
