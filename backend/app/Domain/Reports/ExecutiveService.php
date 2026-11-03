<?php

namespace App\Domain\Reports;

use App\Domain\Access\AccessScope;
use App\Models\Assignment;
use App\Models\Campaign;
use App\Models\User;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Executive summary: coverage, active people, journey-plan status, on-shelf availability, and share of shelf. */
class ExecutiveService
{
    private const VALID = ['submitted', 'under_review', 'approved'];    // what counts as captured shelf data

    public function summary(User $user, array $f): array
    {
        $month = !empty($f['month']) ? Carbon::createFromFormat('Y-m-d', $f['month'].'-01')->startOfDay() : now()->startOfMonth();
        $campaignIds = $this->campaignIds($user, $f);

        $out = [
            'month' => $month->format('Y-m'),
            'coverage' => $this->coverage($user, $f, $month, $campaignIds),
            'people' => $this->people($user),
            'journey_plan' => $this->journeyPlan($user, $f, $month),
            'targets' => !empty($f['campaign_id']) && $campaignIds !== [] ? \App\Domain\Projects\ProjectSettings::targetsFor($f['campaign_id']) : null,
        ];
        $period = ($f['period'] ?? 'month') === 'week' ? [now()->startOfWeek(), now()->endOfWeek()] : [$month->copy(), $month->copy()->endOfMonth()];
        $out['shelf'] = $this->shelf($user, $f, $period[0]->toDateString(), $period[1]->toDateString());
        $out['period'] = ['from' => $period[0]->toDateString(), 'to' => $period[1]->toDateString(), 'kind' => ($f['period'] ?? 'month') === 'week' ? 'week' : 'month'];

        return $out;
    }

    /** @return list<string> */
    private function campaignIds(User $user, array $f): array
    {
        return AccessScope::scope(Campaign::query(), $user)
            ->when(!empty($f['client_id']), fn ($q) => $q->where('campaigns.client_id', $f['client_id']))
            ->when(!empty($f['campaign_id']), fn ($q) => $q->where('campaigns.id', $f['campaign_id']))
            ->pluck('campaigns.id')->all();
    }

    private function visits(User $user, array $campaignIds): Builder
    {
        return AccessScope::scope(Visit::query(), $user)->whereIn('visits.campaign_id', $campaignIds ?: ['00000000-0000-0000-0000-000000000000']);
    }

    private function coverage(User $user, array $f, Carbon $month, array $campaignIds): array
    {
        $network = $campaignIds === [] ? 0 : DB::table('campaign_locations as cl')->join('locations as l', 'l.id', '=', 'cl.location_id')->whereNull('l.deleted_at')
            ->whereIn('cl.campaign_id', $campaignIds)->distinct()->count('cl.location_id');
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $m = $month->copy()->subMonthsNoOverflow($i);
            $base = fn () => $this->visits($user, $campaignIds)->where('visits.status', '!=', 'scheduled')
                ->whereBetween('visits.visit_date', [$m->toDateString(), $m->copy()->endOfMonth()->toDateString()]);
            $visited = $campaignIds === [] ? 0 : $base()->distinct()->count('visits.location_id');
            $visits = $campaignIds === [] ? 0 : $base()->count();
            $rows[] = ['month' => $m->format('Y-m'), 'label' => $m->format('F'), 'visited' => $visited, 'network' => $network, 'pct' => $network > 0 ? round(100 * $visited / $network, 1) : null,
                'visits' => $visits, 'per_outlet' => $network > 0 ? round($visits / $network, 1) : null];
        }

        return $rows;
    }

    private function people(User $user): array
    {
        $agents = app(AgentTrackingService::class)->agentIdsQuery($user);
        $ids = (clone $agents)->pluck('users.id')->all();
        $today = now()->toDateString();
        $active = $ids === [] ? [] : array_unique(array_merge(
            DB::table('attendance')->whereIn('user_id', $ids)->where('attendance_date', $today)->whereNotNull('clock_in_at')->pluck('user_id')->all(),
            DB::table('agent_pings')->whereIn('user_id', $ids)->where('recorded_at', '>=', now()->startOfDay())->pluck('user_id')->all(),
        ));
        $activeSet = array_flip($active);

        $teams = [];
        $profiles = DB::table('agent_profiles as ap')->leftJoin('users as tl', 'tl.id', '=', 'ap.team_leader_id')->whereIn('ap.user_id', $ids)
            ->select('ap.user_id', 'ap.team_leader_id', 'tl.name as tl_name')->get();
        foreach ($profiles as $p) {
            $key = $p->team_leader_id ?: 'none';
            $teams[$key] ??= ['team' => $p->tl_name ?: 'No team leader', 'total' => 0, 'active' => 0];
            $teams[$key]['total']++;
            $teams[$key]['active'] += isset($activeSet[$p->user_id]) ? 1 : 0;
        }
        $teams = array_values(array_map(fn ($t) => $t + ['pct' => $t['total'] ? round(100 * $t['active'] / $t['total']) : null], $teams));
        usort($teams, fn ($a, $b) => strcmp($a['team'], $b['team']));

        return ['active_today' => count($active), 'total' => count($ids), 'pct' => $ids ? round(100 * count($active) / count($ids)) : null, 'teams' => $teams, 'date' => $today];
    }

    private function journeyPlan(User $user, array $f, Carbon $month): array
    {
        $q = AccessScope::scope(Assignment::query(), $user)->whereBetween('assignments.scheduled_date', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->when(!empty($f['client_id']), fn ($w) => $w->whereIn('assignments.campaign_id', Campaign::query()->where('client_id', $f['client_id'])->select('id')))
            ->when(!empty($f['campaign_id']), fn ($w) => $w->where('assignments.campaign_id', $f['campaign_id']));
        $today = now()->toDateString();
        $row = $q->selectRaw("
            sum(case when assignments.status = 'completed' then 1 else 0 end) as complete,
            sum(case when assignments.status = 'in_progress' then 1 else 0 end) as in_progress,
            sum(case when assignments.status = 'overdue' or (assignments.status in ('pending','accepted') and assignments.scheduled_date < ?) then 1 else 0 end) as not_completed,
            sum(case when assignments.status in ('pending','accepted') and assignments.scheduled_date >= ? then 1 else 0 end) as not_started,
            sum(case when assignments.status = 'cancelled' then 1 else 0 end) as other,
            count(*) as total", [$today, $today])->first();
        $total = (int) ($row->total ?? 0);
        $labels = ['complete' => 'Complete', 'in_progress' => 'In progress', 'not_completed' => 'Not completed', 'not_started' => 'Not started', 'other' => 'Cancelled'];
        $items = [];
        foreach ($labels as $k => $label) {
            $n = (int) ($row->$k ?? 0);
            $items[] = ['key' => $k, 'label' => $label, 'total' => $n, 'pct' => $total ? round(100 * $n / $total, 1) : 0];
        }

        return ['total' => $total, 'items' => $items];
    }

    private function shelf(User $user, array $f, string $from, string $to): array
    {
        $campaignIds = $this->campaignIds($user, $f);
        $empty = ['osa_brands' => [], 'osa_pct' => null, 'sos_categories' => [], 'sos_pct' => null, 'channels' => []];
        if ($campaignIds === []) {
            return $empty;
        }
        $visitIds = $this->visits($user, $campaignIds)->whereIn('visits.status', self::VALID)->whereBetween('visits.visit_date', [$from, $to])
            ->when(!empty($f['channel']), fn ($q) => $q->whereIn('visits.location_id', DB::table('locations')->where('type', $f['channel'])->select('id')))
            ->select('visits.id');

        $base = fn () => DB::table('osa_records as o')->join('products as p', 'p.id', '=', 'o.product_id')->whereIn('o.visit_id', $visitIds);

        $brands = $base()->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')->groupBy('b.id', 'b.name')
            ->selectRaw("coalesce(b.name, 'No brand') as name, count(*) as checks, sum(case when o.available then 1 else 0 end) as ok")->orderBy('name')->get();
        $osaBrands = $brands->map(fn ($r) => ['name' => $r->name, 'checks' => (int) $r->checks, 'pct' => $r->checks ? round(100 * $r->ok / $r->checks, 1) : null])->all();
        $all = $base()->selectRaw('count(*) as checks, sum(case when o.available then 1 else 0 end) as ok')->first();

        // Share of shelf: our facings in a category / all facings counted on the shelf in the same visits.
        $totals = DB::table('merchandising_records')->whereIn('visit_id', $visitIds)->groupBy('visit_id')->selectRaw('visit_id, max(total_facings) as tf')->pluck('tf', 'visit_id');
        $cat = DB::table('osa_records as o')->join('products as p', 'p.id', '=', 'o.product_id')->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')
            ->whereIn('o.visit_id', $visitIds)->whereNotNull('o.facings')->groupBy('o.visit_id', 'c.id', 'c.name')
            ->selectRaw("o.visit_id, coalesce(c.name, 'Uncategorised') as name, sum(o.facings) as facings")->get();
        $targets = DB::table('products as p')->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')->selectRaw("coalesce(c.name, 'Uncategorised') as name, avg(p.sos_target_pct) as target")->groupBy('c.id', 'c.name')->pluck('target', 'name');
        $num = []; $den = []; $allNum = 0; $allDen = [];
        foreach ($cat as $r) {
            $tf = (float) ($totals[$r->visit_id] ?? 0);
            if ($tf <= 0) {
                continue;
            }
            $num[$r->name] = ($num[$r->name] ?? 0) + $r->facings;
            $den[$r->name] = ($den[$r->name] ?? 0) + $tf;
            $allNum += $r->facings; $allDen[$r->visit_id] = $tf;
        }
        $sos = [];
        foreach ($num as $name => $n) {
            $sos[] = ['name' => $name, 'actual' => round(100 * $n / $den[$name], 1), 'expected' => (float) ($targets[$name] ?? config('field.default_sos_target_pct'))];
        }
        usort($sos, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $allDenSum = array_sum($allDen);

        return [
            'osa_brands' => $osaBrands, 'osa_pct' => $all && $all->checks ? round(100 * $all->ok / $all->checks, 1) : null,
            'sos_categories' => $sos, 'sos_pct' => $allDenSum > 0 ? round(100 * $allNum / $allDenSum, 1) : null,
            'channels' => DB::table('locations')->whereNull('deleted_at')->distinct()->orderBy('type')->pluck('type')->all(),
        ];
    }
}
