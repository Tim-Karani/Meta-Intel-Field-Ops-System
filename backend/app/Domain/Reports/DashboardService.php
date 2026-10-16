<?php

namespace App\Domain\Reports;

use App\Domain\Access\AccessScope;
use App\Models\Attendance;
use App\Models\Campaign;
use App\Models\Issue;
use App\Models\Photo;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Command Center": one focus (client / campaign / period) applied to every figure so different clients' work is
 * never mixed, with period-over-period deltas, today's pulse, execution health against targets, store coverage,
 * agent ranking, photo proof and a map. Everything starts from AccessScope, so it can never exceed what the user may see.
 */
class DashboardService
{
    /** Visits that count as "executed": the agent has finished and submitted them. */
    private const DONE = ['submitted', 'under_review', 'approved'];
    private const MAX_DAYS = 92;

    public function __construct(private ReportService $reports) {}

    /**
     * @param array{client_id?:string,campaign_id?:string,date_from?:string,date_to?:string} $filters
     */
    public function summary(User $user, array $filters = []): array
    {
        $approvedOnly = $user->scopeType() === 'client';
        $today = CarbonImmutable::today();

        // ---- period (default: last 14 days) and the equally long period before it ---------------------------
        $to = isset($filters['date_to']) ? CarbonImmutable::parse($filters['date_to']) : $today;
        $from = isset($filters['date_from']) ? CarbonImmutable::parse($filters['date_from']) : $to->subDays(13);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }
        $days = (int) $from->diffInDays($to) + 1;
        $prevTo = $from->subDay();
        $prevFrom = $prevTo->subDays($days - 1);

        $focus = array_filter(['client_id' => $filters['client_id'] ?? null, 'campaign_id' => $filters['campaign_id'] ?? null]);
        $range = fn (CarbonImmutable $a, CarbonImmutable $b) => ['date_from' => $a->toDateString(), 'date_to' => $b->toDateString()] + $focus;

        $visits = fn (array $f) => $this->reports->visitBase($user, $f, $approvedOnly);
        $assignments = fn (array $f) => $this->reports->assignmentBase($user, $f);
        $focusCampaignIds = fn () => AccessScope::scope(Campaign::query(), $user)
            ->when($focus['client_id'] ?? null, fn ($q, $v) => $q->where('campaigns.client_id', $v))
            ->when($focus['campaign_id'] ?? null, fn ($q, $v) => $q->where('campaigns.id', $v));
        $focusVisits = fn () => AccessScope::scope(Visit::query(), $user)
            ->when($focus['campaign_id'] ?? null, fn ($q, $v) => $q->where('visits.campaign_id', $v))
            ->when($focus['client_id'] ?? null, fn ($q, $v) => $q->whereIn('visits.campaign_id', Campaign::query()->where('client_id', $v)->select('id')));
        $focusIssues = fn () => AccessScope::scope(Issue::query(), $user)
            ->when($focus['campaign_id'] ?? null, fn ($q, $v) => $q->where('issues.campaign_id', $v))
            ->when($focus['client_id'] ?? null, fn ($q, $v) => $q->whereIn('issues.campaign_id', Campaign::query()->where('client_id', $v)->select('id')));

        // ---- KPI block with deltas -------------------------------------------------------------------------
        $measure = function (array $f) use ($visits, $assignments, $approvedOnly): array {
            $v = $visits($f)->selectRaw("count(*) filter (where v.status in ('submitted','under_review','approved')) as executed,
                count(*) filter (where v.status = 'approved') as approved, count(*) filter (where v.status = 'rejected') as rejected,
                count(distinct v.agent_id) as agents, count(*) filter (where v.gps_outcome = 'suspicious') as flags")->first();
            $sales = (float) $visits($f)->join('sales as s', 's.visit_id', '=', 'v.id')->sum('s.total_value');
            $osa = $visits($f)->join('osa_records as o', 'o.visit_id', '=', 'v.id')->selectRaw('count(*) as n, count(*) filter (where o.available) as ok')->first();
            $plan = $approvedOnly ? null : $assignments($f)->selectRaw("count(*) as planned, count(*) filter (where a.status = 'completed') as done")->first();

            $judged = (int) $v->approved + (int) $v->rejected;

            return [
                'visits_executed' => (int) $v->executed,
                'completion_rate' => $plan && $plan->planned ? round($plan->done / $plan->planned * 100, 1) : null,
                'approval_rate' => $judged ? round($v->approved / $judged * 100, 1) : null,
                'active_agents' => (int) $v->agents,
                'sales_value' => round($sales, 2),
                'osa_availability' => $osa && $osa->n ? round($osa->ok / $osa->n * 100, 1) : null,
                'gps_flags' => (int) $v->flags,
                'assignments_planned' => $plan ? (int) $plan->planned : null,
            ];
        };
        $now = $measure($range($from, $to));
        $before = $measure($range($prevFrom, $prevTo));
        $rates = ['completion_rate', 'approval_rate', 'osa_availability'];
        $kpis = [];
        foreach ($now as $key => $value) {
            $prev = $before[$key];
            $delta = null;
            if ($value !== null && $prev !== null) {
                // Rates move in percentage points; counts and money in percent of the previous figure.
                $delta = in_array($key, $rates, true) ? round($value - $prev, 1) : ($prev > 0 ? round(($value - $prev) / $prev * 100, 1) : null);
            }
            $kpis[$key] = ['value' => $value, 'previous' => $prev, 'delta' => $delta, 'delta_unit' => in_array($key, $rates, true) ? 'pts' : '%'];
        }

        // ---- daily trend ----------------------------------------------------------------------------------
        $planned = $approvedOnly ? collect() : $assignments($range($from, $to))->groupBy('a.scheduled_date')->selectRaw('a.scheduled_date as d, count(*) as n')->pluck('n', 'd');
        $done = $visits($range($from, $to))->whereIn('v.status', self::DONE)->groupBy('v.visit_date')->selectRaw('v.visit_date as d, count(*) as n')->pluck('n', 'd');
        $trend = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            $trend[] = ['date' => $key, 'planned' => (int) ($planned[$key] ?? 0), 'completed' => (int) ($done[$key] ?? 0)];
        }

        // ---- today's pulse (always "today", within the client/campaign focus) -----------------------------
        $pulse = null;
        if (!$approvedOnly) {
            $t = $assignments(['date_from' => $today->toDateString(), 'date_to' => $today->toDateString()] + $focus)
                ->selectRaw("count(*) as total, count(*) filter (where a.status = 'completed') as completed, count(*) filter (where a.status = 'in_progress') as in_progress,
                    count(*) filter (where a.status in ('pending','accepted')) as todo")->first();
            $attendance = AccessScope::scope(Attendance::query(), $user)->where('attendance.attendance_date', $today->toDateString())
                ->when($focus, fn ($q) => $q->whereIn('attendance.user_id', DB::table('assignments')->whereIn('campaign_id', $focusCampaignIds()->select('campaigns.id'))->select('agent_id')));
            $pulse = [
                'assignments_total' => (int) $t->total, 'completed' => (int) $t->completed, 'in_progress' => (int) $t->in_progress, 'to_do' => (int) $t->todo,
                'agents_clocked_in' => (clone $attendance)->count(), 'late_arrivals' => (clone $attendance)->where('attendance.status', 'late')->count(),
                'visits_submitted' => $focusVisits()->whereDate('visits.submitted_at', $today->toDateString())->count(),
                'issues_opened' => $focusIssues()->whereDate('issues.created_at', $today->toDateString())->count(),
            ];
        }

        // ---- campaigns: status mix and execution health ---------------------------------------------------
        $campaignStatus = $focusCampaignIds()->selectRaw('campaigns.status as status, count(*) as n')->groupBy('campaigns.status')->pluck('n', 'status');
        $health = $approvedOnly ? [] : $this->executionHealth($user, $focusCampaignIds, $visits, $assignments, $range($from, $to));

        // ---- store coverage ---------------------------------------------------------------------------------
        $coverage = $approvedOnly ? null : $this->coverage($focusCampaignIds, $visits, $range($from, $to));

        // ---- agents, photos, issues, activity, map --------------------------------------------------------
        $agents = $approvedOnly ? [] : $visits($range($from, $to))->groupBy('v.agent_id', 'ag.name')
            ->selectRaw("ag.name as agent, count(*) filter (where v.status in ('submitted','under_review','approved')) as visits,
                count(*) filter (where v.status = 'approved') as approved, count(*) filter (where v.status = 'rejected') as rejected")
            ->orderByDesc('visits')->orderBy('ag.name')->limit(8)->get()
            ->map(fn ($r) => ['agent' => $r->agent, 'visits' => (int) $r->visits, 'approved' => (int) $r->approved, 'rejected' => (int) $r->rejected])->all();

        $photos = AccessScope::scope(Photo::query(), $user)
            ->whereIn('photos.visit_id', $visits($range($from, $to))->select('v.id'))->where('photos.review_status', '!=', 'rejected')
            ->join('visits as pv', 'pv.id', '=', 'photos.visit_id')->join('locations as pl', 'pl.id', '=', 'pv.location_id')
            ->orderByDesc('photos.created_at')->limit(8)->get(['photos.id', 'photos.kind', 'photos.review_status', 'photos.visit_id', 'photos.captured_at', 'pl.name as location'])
            ->map(fn ($p) => ['id' => $p->id, 'kind' => $p->kind, 'review_status' => $p->review_status, 'visit_id' => $p->visit_id, 'captured_at' => $p->captured_at, 'location' => $p->location])->all();

        $issues = $approvedOnly ? collect() : $focusIssues()->whereNotIn('issues.status', ['resolved', 'closed'])
            ->selectRaw('issues.priority as priority, count(*) as n')->groupBy('issues.priority')->pluck('n', 'priority');

        $events = $approvedOnly ? [] : DB::table('visit_events as e')->join('visits as v', 'v.id', '=', 'e.visit_id')
            ->join('users as ag', 'ag.id', '=', 'v.agent_id')->join('locations as l', 'l.id', '=', 'v.location_id')
            ->whereIn('v.id', $focusVisits()->select('visits.id'))
            ->orderByDesc('e.id')->limit(10)->get(['e.type', 'e.created_at', 'e.note', 'v.id as visit_id', 'ag.name as agent', 'l.name as location']);

        $points = $approvedOnly ? [] : $visits($range($from, $to))->join('visit_checkins as ci', 'ci.visit_id', '=', 'v.id')
            ->orderByDesc('ci.server_ts')->limit(200)->get(['ci.latitude as lat', 'ci.longitude as lng', 'ci.outcome', 'l.name as location', 'ag.name as agent']);

        // ---- "needs attention" -----------------------------------------------------------------------------
        $attention = [
            'pending_reviews' => $focusVisits()->whereIn('visits.status', ['submitted', 'under_review'])->count(),
            'gps_flags' => $now['gps_flags'],
            'overdue_assignments' => $approvedOnly ? 0 : $assignments($focus)->where('a.status', 'overdue')->count(),
            'urgent_issues' => (int) ($issues['critical'] ?? 0) + (int) ($issues['high'] ?? 0),
        ];

        return [
            'scope' => $user->scopeType(),
            'filters' => (object) $focus,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days, 'previous_from' => $prevFrom->toDateString(), 'previous_to' => $prevTo->toDateString()],
            'kpis' => $kpis,
            'pulse' => $pulse,
            'attention' => $attention,
            'visit_trend' => $trend,
            'campaign_health' => $health,
            'coverage' => $coverage,
            'top_agents' => $agents,
            'photos' => $photos,
            'campaigns_by_status' => $campaignStatus,
            'open_issues_by_priority' => $issues,
            'recent_activity' => $events,
            'map_points' => $points,
            // kept for API compatibility with earlier clients
            'cards' => [
                'active_campaigns' => (int) ($campaignStatus['active'] ?? 0), 'pending_reviews' => $attention['pending_reviews'],
                'open_issues' => (int) collect($issues)->sum(), 'gps_flags_7d' => $attention['gps_flags'],
            ],
        ];
    }

    /** One row per live campaign: period activity plus lifetime progress against its "visits_completed" target. */
    private function executionHealth(User $user, \Closure $focusCampaignIds, \Closure $visits, \Closure $assignments, array $period): array
    {
        $campaigns = $focusCampaignIds()->whereIn('campaigns.status', ['active', 'paused', 'planned'])->with('client:id,name')
            ->orderBy('campaigns.name')->limit(12)->get(['campaigns.id', 'campaigns.name', 'campaigns.client_id', 'campaigns.status', 'campaigns.end_date']);
        if ($campaigns->isEmpty()) {
            return [];
        }
        $ids = $campaigns->pluck('id')->all();

        $plan = $assignments($period)->whereIn('a.campaign_id', $ids)->groupBy('a.campaign_id')
            ->selectRaw("a.campaign_id as id, count(*) as planned, count(*) filter (where a.status = 'completed') as done")->get()->keyBy('id');
        $vis = $visits($period)->whereIn('v.campaign_id', $ids)->groupBy('v.campaign_id')
            ->selectRaw("v.campaign_id as id, count(*) filter (where v.status in ('submitted','under_review','approved')) as executed,
                count(*) filter (where v.status = 'approved') as approved, count(*) filter (where v.status = 'rejected') as rejected")->get()->keyBy('id');
        $osa = $visits($period)->whereIn('v.campaign_id', $ids)->join('osa_records as o', 'o.visit_id', '=', 'v.id')->groupBy('v.campaign_id')
            ->selectRaw('v.campaign_id as id, count(*) as n, count(*) filter (where o.available) as ok')->get()->keyBy('id');
        $sales = $visits($period)->whereIn('v.campaign_id', $ids)->join('sales as s', 's.visit_id', '=', 'v.id')->groupBy('v.campaign_id')
            ->selectRaw('v.campaign_id as id, sum(s.total_value) as value')->get()->keyBy('id');
        $lifetime = $this->reports->visitBase($user, [])->whereIn('v.campaign_id', $ids)->whereIn('v.status', self::DONE)->groupBy('v.campaign_id')
            ->selectRaw('v.campaign_id as id, count(*) as n')->pluck('n', 'id');
        $targets = DB::table('campaign_kpis')->whereIn('campaign_id', $ids)->where('metric', 'visits_completed')->groupBy('campaign_id')
            ->selectRaw('campaign_id as id, sum(target) as target')->pluck('target', 'id');

        return $campaigns->map(function ($c) use ($plan, $vis, $osa, $sales, $lifetime, $targets) {
            $p = $plan[$c->id] ?? null;
            $v = $vis[$c->id] ?? null;
            $o = $osa[$c->id] ?? null;
            $judged = $v ? (int) $v->approved + (int) $v->rejected : 0;
            $target = isset($targets[$c->id]) ? (float) $targets[$c->id] : null;
            $life = (int) ($lifetime[$c->id] ?? 0);

            return [
                'id' => $c->id, 'name' => $c->name, 'client' => $c->client?->name, 'status' => $c->status->value ?? $c->status, 'end_date' => $c->end_date?->toDateString(),
                'planned' => $p ? (int) $p->planned : 0, 'completion_rate' => $p && $p->planned ? round($p->done / $p->planned * 100, 1) : null,
                'visits' => $v ? (int) $v->executed : 0, 'approval_rate' => $judged ? round($v->approved / $judged * 100, 1) : null,
                'osa' => $o && $o->n ? round($o->ok / $o->n * 100, 1) : null, 'sales' => $sales[$c->id] ?? null ? round((float) $sales[$c->id]->value, 2) : 0.0,
                'target' => $target, 'lifetime_visits' => $life, 'target_progress' => $target ? min(100, round($life / $target * 100, 1)) : null,
            ];
        })->values()->all();
    }

    /** How much of the campaign store network was actually visited in the period, and which stores were missed. */
    private function coverage(\Closure $focusCampaignIds, \Closure $visits, array $period): array
    {
        $ids = $focusCampaignIds()->whereIn('campaigns.status', ['active', 'paused', 'planned'])->select('campaigns.id');
        $total = DB::table('campaign_locations')->whereIn('campaign_id', $ids)->distinct()->count('location_id');
        $visited = $visits($period)->whereIn('v.status', self::DONE)->select('v.location_id');

        $reached = DB::table('campaign_locations')->whereIn('campaign_id', $ids)->whereIn('location_id', $visited)->distinct()->count('location_id');
        $missed = DB::table('campaign_locations as cl')->join('locations as l', 'l.id', '=', 'cl.location_id')->whereNull('l.deleted_at')
            ->whereIn('cl.campaign_id', $ids)->whereNotIn('cl.location_id', $visited)
            ->distinct()->select('l.id', 'l.name', 'l.town')
            ->selectSub(fn (Builder $q) => $q->from('visits as lv')->selectRaw('max(lv.visit_date)')->whereColumn('lv.location_id', 'l.id')->whereIn('lv.status', self::DONE), 'last_visit')
            ->orderBy('l.name')->limit(8)->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'town' => $r->town, 'last_visit' => $r->last_visit])->all();

        return ['total_stores' => $total, 'visited' => $reached, 'not_visited' => max(0, $total - $reached), 'rate' => $total ? round($reached / $total * 100, 1) : null, 'missed' => $missed];
    }

    /** Field agent home: today's work at a glance. */
    public function agentSummary(User $user): array
    {
        $a = DB::table('assignments')->where('agent_id', $user->getKey())->where('scheduled_date', now()->toDateString())
            ->selectRaw("count(*) as total, count(*) filter (where status = 'completed') as completed, count(*) filter (where status = 'in_progress') as in_progress, count(*) filter (where status in ('pending','accepted')) as todo")->first();

        return [
            'scope' => 'own', 'today' => $a,
            'pending_review' => DB::table('visits')->where('agent_id', $user->getKey())->whereIn('status', ['submitted', 'under_review'])->count(),
            'recently_rejected' => DB::table('visits')->where('agent_id', $user->getKey())->where('status', 'rejected')->where('reviewed_at', '>=', now()->subDays(7))->count(),
        ];
    }
}
