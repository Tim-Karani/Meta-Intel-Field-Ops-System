<?php

namespace App\Domain\Reports;

use App\Domain\Access\AccessScope;
use App\Models\Campaign;
use App\Models\Issue;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(private ReportService $reports) {}

    public function summary(User $user): array
    {
        $today = now()->toDateString();
        $from30 = now()->subDays(30)->toDateString();
        $from14 = now()->subDays(13)->toDateString();
        $approvedOnly = $user->scopeType() === 'client';

        $visits = fn (array $f = []) => $this->reports->visitBase($user, $f, $approvedOnly);
        $campaignStatus = AccessScope::scope(Campaign::query(), $user)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $planned = $approvedOnly ? collect() : $this->reports->assignmentBase($user, ['date_from' => $from14])
            ->groupBy('a.scheduled_date')->selectRaw('a.scheduled_date as d, count(*) as n')->pluck('n', 'd');
        $done = $visits(['date_from' => $from14])->whereIn('v.status', ['submitted', 'under_review', 'approved'])
            ->groupBy('v.visit_date')->selectRaw('v.visit_date as d, count(*) as n')->pluck('n', 'd');
        $trend = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $trend[] = ['date' => $d, 'planned' => (int) ($planned[$d] ?? 0), 'completed' => (int) ($done[$d] ?? 0)];
        }

        $sales = (float) $visits(['date_from' => $from30])->join('sales as s', 's.visit_id', '=', 'v.id')->sum('s.total_value');
        $osa = $visits(['date_from' => $from30])->join('osa_records as o', 'o.visit_id', '=', 'v.id')->selectRaw('count(*) as n, count(*) filter (where o.available) as ok')->first();

        $pending = AccessScope::scope(Visit::query(), $user)->whereIn('status', ['submitted', 'under_review'])->count();
        $flags = AccessScope::scope(Visit::query(), $user)->where('gps_outcome', 'suspicious')->where('visit_date', '>=', now()->subDays(7)->toDateString())->count();
        $todayVisits = $visits(['date_from' => $today, 'date_to' => $today])->count();

        $issues = $approvedOnly ? collect() : AccessScope::scope(Issue::query(), $user)->whereNotIn('status', ['resolved', 'closed'])
            ->selectRaw('priority, count(*) as n')->groupBy('priority')->pluck('n', 'priority');

        $events = $approvedOnly ? [] : DB::table('visit_events as e')->join('visits as v', 'v.id', '=', 'e.visit_id')
            ->join('users as ag', 'ag.id', '=', 'v.agent_id')->join('locations as l', 'l.id', '=', 'v.location_id')
            ->whereIn('v.id', AccessScope::scope(Visit::query(), $user)->select('visits.id'))
            ->orderByDesc('e.id')->limit(12)->get(['e.type', 'e.created_at', 'e.note', 'v.id as visit_id', 'ag.name as agent', 'l.name as location']);

        $points = $approvedOnly ? [] : $visits(['date_from' => now()->subDays(7)->toDateString()])->join('visit_checkins as ci', 'ci.visit_id', '=', 'v.id')
            ->orderByDesc('ci.server_ts')->limit(200)->get(['ci.latitude as lat', 'ci.longitude as lng', 'ci.outcome', 'l.name as location', 'ag.name as agent']);

        return [
            'scope' => $user->scopeType(),
            'cards' => [
                'active_campaigns' => (int) ($campaignStatus['active'] ?? 0), 'visits_today' => $todayVisits, 'pending_reviews' => $pending,
                'sales_value_30d' => round($sales, 2), 'osa_availability_30d' => $osa && $osa->n ? round($osa->ok / $osa->n * 100, 1) : null,
                'gps_flags_7d' => $flags, 'open_issues' => (int) collect($issues)->sum(),
            ],
            'campaigns_by_status' => $campaignStatus, 'open_issues_by_priority' => $issues, 'visit_trend' => $trend,
            'recent_activity' => $events, 'map_points' => $points,
        ];
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
