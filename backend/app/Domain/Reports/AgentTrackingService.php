<?php

namespace App\Domain\Reports;

use App\Domain\Access\AccessScope;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Where is everyone and what are they doing": the live agent list and one agent's day.
 * Signals, strongest first: a location ping from the phone, then check-in/out positions and visit activity.
 * An agent counts as "in app" if anything was heard from them in the last 30 minutes.
 */
class AgentTrackingService
{
    public const ONLINE_MINUTES = 30;
    private const FIELD_ROLES = ['field_agent', 'team_leader'];

    public function __construct(private ScoringService $scoring) {}

    /** Field agents and Team Leaders this user may track. */
    public function agentIdsQuery(User $user): Builder
    {
        $q = User::query()->whereNull('users.client_id')->where('users.status', 'active')->whereHas('roles', fn ($r) => $r->whereIn('slug', self::FIELD_ROLES));

        return match ($user->scopeType()) {
            'all' => $q,
            'campaigns' => $q->where(fn ($w) => $w
                ->whereIn('users.id', DB::table('assignments')->whereIn('campaign_id', AccessScope::campaignIds($user))->select('agent_id'))
                ->orWhereIn('users.id', DB::table('campaign_users')->whereIn('campaign_id', AccessScope::campaignIds($user))->select('user_id'))),
            'team' => $q->where(fn ($w) => $w->whereIn('users.id', AccessScope::supervisedAgentIds($user))->orWhere('users.id', $user->getKey())),
            default => $q->whereRaw('1 = 0'),
        };
    }

    public function list(User $user, array $f): array
    {
        $q = $this->agentIdsQuery($user)->with('agentProfile')
            ->when(!empty($f['search']), function ($w) use ($f) {
                $like = '%'.mb_strtolower($f['search']).'%';
                $w->where(fn ($x) => $x->whereRaw('lower(users.name) like ?', [$like])->orWhereRaw('lower(coalesce(users.phone, \'\')) like ?', [$like])->orWhereRaw('lower(users.email) like ?', [$like]));
            })
            ->when(\App\Domain\Access\ProjectFocus::campaignIds($f), fn ($w, $c) => $w->whereIn('users.id', \App\Domain\Access\ProjectFocus::agentIds($c)))
            ->when(!empty($f['team_leader_id']), fn ($w) => $w->whereIn('users.id', DB::table('agent_profiles')->where('team_leader_id', $f['team_leader_id'])->select('user_id')));
        $users = $q->orderBy('users.name')->limit(500)->get();
        if ($users->isEmpty()) {
            return ['data' => [], 'summary' => ['total' => 0, 'in_app' => 0, 'offline' => 0, 'not_started' => 0]];
        }
        $rows = $this->rows($users);
        if (!empty($f['seen_after'])) {
            $cut = Carbon::parse($f['seen_after'])->startOfDay();
            $rows = array_values(array_filter($rows, fn ($r) => $r['last_seen_at'] && Carbon::parse($r['last_seen_at'])->gte($cut)));
        }
        if (!empty($f['status'])) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['status'] === $f['status']));
        }
        usort($rows, fn ($a, $b) => strcmp((string) $b['last_seen_at'], (string) $a['last_seen_at']));
        $count = fn ($s) => count(array_filter($rows, fn ($r) => $r['status'] === $s));

        return ['data' => $rows, 'summary' => ['total' => count($rows), 'in_app' => $count('in_app'), 'offline' => $count('offline'), 'not_started' => $count('not_started')]];
    }

    /** @param \Illuminate\Support\Collection<int,User> $users */
    private function rows($users): array
    {
        $ids = $users->pluck('id')->all();
        $today = now()->toDateString();

        $pingId = DB::table('agent_pings')->whereIn('user_id', $ids)->groupBy('user_id')->selectRaw('max(id) as id')->pluck('id');
        $pings = DB::table('agent_pings')->whereIn('id', $pingId)->get()->keyBy('user_id');
        $att = DB::table('attendance')->whereIn('user_id', $ids)->where('attendance_date', $today)->get()->keyBy('user_id');
        $evId = DB::table('visit_events as e')->join('visits as v', 'v.id', '=', 'e.visit_id')->whereIn('v.agent_id', $ids)->groupBy('v.agent_id')->selectRaw('max(e.id) as id')->pluck('id');
        $events = DB::table('visit_events as e')->join('visits as v', 'v.id', '=', 'e.visit_id')->join('locations as l', 'l.id', '=', 'v.location_id')->whereIn('e.id', $evId)
            ->select('v.agent_id', 'e.type', 'e.created_at', 'l.name as location', 'l.town')->get()->keyBy('agent_id');
        $latest = DB::table('visit_checkins as c')->join('visits as v', 'v.id', '=', 'c.visit_id')->whereIn('v.agent_id', $ids)->groupBy('v.agent_id')->selectRaw('v.agent_id, max(c.server_ts) as t');
        $checkins = DB::table('visit_checkins as c')->join('visits as v', 'v.id', '=', 'c.visit_id')
            ->joinSub($latest, 'm', fn ($j) => $j->on('m.agent_id', '=', 'v.agent_id')->on('m.t', '=', 'c.server_ts'))
            ->select('v.agent_id', 'c.latitude', 'c.longitude', 'c.server_ts')->get()->keyBy('agent_id')->all();
        $visitsToday = DB::table('visits')->whereIn('agent_id', $ids)->where('visit_date', $today)->where('status', '!=', 'scheduled')->groupBy('agent_id')->selectRaw('agent_id, count(*) as n')->pluck('n', 'agent_id');
        $assignedToday = DB::table('assignments')->whereIn('agent_id', $ids)->where('scheduled_date', $today)->where('status', '!=', 'cancelled')->groupBy('agent_id')->selectRaw('agent_id, count(*) as n')->pluck('n', 'agent_id');
        $tlNames = DB::table('agent_profiles as ap')->join('users as tl', 'tl.id', '=', 'ap.team_leader_id')->whereIn('ap.user_id', $ids)->pluck('tl.name', 'ap.user_id');
        $scores = $this->scoring->forAgents($ids, now()->subDays(29)->toDateString(), $today);

        $rows = [];
        foreach ($users as $u) {
            $times = array_filter([
                $pings[$u->id]->recorded_at ?? null, $att[$u->id]->clock_in_at ?? null, $att[$u->id]->clock_out_at ?? null, $events[$u->id]->created_at ?? null, $checkins[$u->id]->server_ts ?? null,
            ]);
            $seen = $times ? collect($times)->map(fn ($t) => Carbon::parse($t))->max() : null;
            $ev = $events[$u->id] ?? null;
            $pos = $this->position($pings[$u->id] ?? null, $checkins[$u->id] ?? null);
            $active = $seen && $seen->gte(now()->subMinutes(self::ONLINE_MINUTES));
            $status = $active ? 'in_app' : (($seen && $seen->toDateString() === $today) ? 'offline' : 'not_started');
            $rows[] = [
                'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'role' => $u->roles()->value('slug'), 'team_leader' => $tlNames[$u->id] ?? null,
                'region' => $u->agentProfile?->region, 'status' => $status, 'last_seen_at' => $seen?->toIso8601String(),
                'battery_pct' => $pings[$u->id]->battery_pct ?? null, 'device_model' => $pings[$u->id]->device_model ?? null,
                'last_action' => $ev ? ['type' => $ev->type, 'at' => Carbon::parse($ev->created_at)->toIso8601String(), 'location' => trim($ev->location.($ev->town ? ", {$ev->town}" : ''))] : null,
                'position' => $pos, 'visits_today' => (int) ($visitsToday[$u->id] ?? 0), 'assigned_today' => (int) ($assignedToday[$u->id] ?? 0),
                'score' => $scores[$u->id]['score'] ?? null, 'grade' => $scores[$u->id]['grade'] ?? null,
            ];
        }

        return $rows;
    }

    private function position(?object $ping, ?object $checkin): ?array
    {
        $p = $ping && $ping->latitude !== null ? ['lat' => (float) $ping->latitude, 'lng' => (float) $ping->longitude, 'at' => Carbon::parse($ping->recorded_at), 'source' => 'ping'] : null;
        $c = $checkin ? ['lat' => (float) $checkin->latitude, 'lng' => (float) $checkin->longitude, 'at' => Carbon::parse($checkin->server_ts), 'source' => 'check-in'] : null;
        $best = $p && $c ? ($p['at']->gte($c['at']) ? $p : $c) : ($p ?? $c);

        return $best ? ['lat' => $best['lat'], 'lng' => $best['lng'], 'at' => $best['at']->toIso8601String(), 'source' => $best['source']] : null;
    }

    /** One agent's day: profile, last action, distance, and an outlet-by-outlet timeline with task completion. */
    public function detail(User $viewer, string $agentId, string $date): array
    {
        $agent = $this->agentIdsQuery($viewer)->with('agentProfile')->whereKey($agentId)->firstOrFail();
        $row = $this->rows(collect([$agent]))[0] ?? [];

        $assignments = DB::table('assignments as a')->join('locations as l', 'l.id', '=', 'a.location_id')->join('campaigns as c', 'c.id', '=', 'a.campaign_id')
            ->leftJoin('visits as v', 'v.assignment_id', '=', 'a.id')->leftJoin('visit_checkins as ci', 'ci.visit_id', '=', 'v.id')->leftJoin('visit_checkouts as co', 'co.visit_id', '=', 'v.id')
            ->where('a.agent_id', $agent->id)->where('a.scheduled_date', $date)->where('a.status', '!=', 'cancelled')
            ->orderByRaw('a.start_time asc nulls last')->orderBy('a.created_at')
            ->select('a.id as assignment_id', 'a.start_time', 'a.end_time', 'a.status as assignment_status', 'l.id as location_id', 'l.name as location', 'l.town', 'l.latitude as lat', 'l.longitude as lng',
                'c.name as campaign', 'c.settings', 'v.id as visit_id', 'v.status as visit_status', 'v.gps_outcome', 'v.started_at', 'v.completed_at', 'ci.server_ts as checkin_at', 'ci.distance_m as checkin_distance_m',
                'ci.latitude as ci_lat', 'ci.longitude as ci_lng', 'co.server_ts as checkout_at', 'co.latitude as co_lat', 'co.longitude as co_lng')->get();

        $visitIds = $assignments->pluck('visit_id')->filter()->all();
        $has = fn (string $table) => $visitIds ? DB::table($table)->whereIn('visit_id', $visitIds)->distinct()->pluck('visit_id')->flip()->all() : [];
        $forms = $has('visit_responses'); $sales = $has('sales'); $osa = $has('osa_records'); $merch = $has('merchandising_records');
        $evidence = $visitIds ? DB::table('photos')->whereIn('visit_id', $visitIds)->where('kind', 'evidence')->groupBy('visit_id')->selectRaw('visit_id, count(*) as n')->pluck('n', 'visit_id') : collect();
        $photoRows = $visitIds ? DB::table('photos')->whereIn('visit_id', $visitIds)->whereIn('kind', ['checkin', 'checkout'])->orderBy('created_at')->get(['id', 'visit_id', 'kind']) : collect();
        $photoOf = [];
        foreach ($photoRows as $p) {
            $photoOf[$p->visit_id][$p->kind] = $p->id;
        }

        $points = []; $timeline = [];
        foreach ($assignments as $a) {
            $settings = is_string($a->settings) ? (json_decode($a->settings, true) ?: []) : [];
            $tasks = array_keys(array_filter($settings['tasks'] ?? ['form' => true, 'sales' => true, 'osa' => true, 'merchandising' => true, 'photos' => true]));
            $done = 0;
            foreach ($tasks as $t) {
                $done += match ($t) {
                    'form' => isset($forms[$a->visit_id]) ? 1 : 0, 'sales' => isset($sales[$a->visit_id]) ? 1 : 0, 'osa' => isset($osa[$a->visit_id]) ? 1 : 0,
                    'merchandising' => isset($merch[$a->visit_id]) ? 1 : 0, 'photos' => ((int) ($evidence[$a->visit_id] ?? 0)) > 0 ? 1 : 0, default => 0,
                };
            }
            $completion = $a->visit_id && $tasks ? (int) round(100 * $done / count($tasks)) : 0;
            $in = $a->checkin_at ? Carbon::parse($a->checkin_at) : null; $out = $a->checkout_at ? Carbon::parse($a->checkout_at) : null;
            if ($a->ci_lat !== null) { $points[] = [(float) $a->ci_lat, (float) $a->ci_lng, $in]; }
            if ($a->co_lat !== null) { $points[] = [(float) $a->co_lat, (float) $a->co_lng, $out]; }
            $state = $out || in_array($a->visit_status, ['completed', 'submitted', 'under_review', 'approved', 'rejected'], true) ? 'completed' : ($in ? 'in_progress' : 'planned');
            $timeline[] = [
                'assignment_id' => $a->assignment_id, 'visit_id' => $a->visit_id, 'state' => $state, 'location' => $a->location, 'town' => $a->town, 'campaign' => $a->campaign,
                'planned_start' => $a->start_time ? substr($a->start_time, 0, 5) : null, 'planned_end' => $a->end_time ? substr($a->end_time, 0, 5) : null,
                'checkin_at' => $in?->toIso8601String(), 'checkout_at' => $out?->toIso8601String(), 'duration_min' => $in && $out ? (int) $in->diffInMinutes($out) : null,
                'distance_m' => $a->checkin_distance_m !== null ? (int) round($a->checkin_distance_m) : null, 'gps_outcome' => $a->gps_outcome, 'visit_status' => $a->visit_status,
                'completion_pct' => $completion, 'checkin_photo_id' => $photoOf[$a->visit_id]['checkin'] ?? null, 'checkout_photo_id' => $photoOf[$a->visit_id]['checkout'] ?? null,
                'lat' => $a->lat !== null ? (float) $a->lat : null, 'lng' => $a->lng !== null ? (float) $a->lng : null,
            ];
        }
        $pingPoints = DB::table('agent_pings')->where('user_id', $agent->id)->whereDate('recorded_at', $date)->whereNotNull('latitude')->orderBy('recorded_at')->get(['latitude', 'longitude', 'recorded_at']);
        foreach ($pingPoints as $p) {
            $points[] = [(float) $p->latitude, (float) $p->longitude, Carbon::parse($p->recorded_at)];
        }
        usort($points, fn ($x, $y) => $x[2] <=> $y[2]);
        $km = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $km += self::haversineKm($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
        }
        $att = DB::table('attendance')->where('user_id', $agent->id)->where('attendance_date', $date)->first();
        $lastPing = DB::table('agent_pings')->where('user_id', $agent->id)->orderByDesc('id')->first();
        $scores = $this->scoring->forAgents([$agent->id], Carbon::parse($date)->subDays(29)->toDateString(), $date);
        $selfie = collect($timeline)->pluck('checkin_photo_id')->filter()->last();

        return [
            'id' => $agent->id, 'name' => $agent->name, 'email' => $agent->email, 'phone' => $agent->phone, 'region' => $agent->agentProfile?->region, 'agent_code' => $agent->agentProfile?->agent_code,
            'team_leader' => $row['team_leader'] ?? null, 'status' => $row['status'] ?? 'not_started', 'last_seen_at' => $row['last_seen_at'] ?? null, 'last_action' => $row['last_action'] ?? null,
            'position' => $row['position'] ?? null, 'battery_pct' => $lastPing->battery_pct ?? null, 'device_model' => $lastPing->device_model ?? null, 'os_version' => $lastPing->os_version ?? null, 'app_version' => $lastPing->app_version ?? null,
            'date' => $date, 'distance_km' => round($km, 1), 'selfie_photo_id' => $selfie,
            'attendance' => $att ? ['clock_in_at' => $att->clock_in_at, 'work_started_at' => $att->work_started_at, 'last_check_out' => $att->work_ended_at, 'worked_minutes' => $att->worked_minutes, 'outlets_visited' => $att->outlets_visited, 'status' => $att->status] : null,
            'timeline' => $timeline, 'score' => $scores[$agent->id] ?? null,
        ];
    }

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0088; $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
