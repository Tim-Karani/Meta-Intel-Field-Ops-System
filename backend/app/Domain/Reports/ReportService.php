<?php

namespace App\Domain\Reports;

use App\Domain\Access\AccessScope;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Campaign;
use App\Models\Issue;
use App\Models\User;
use App\Models\Visit;
use App\Support\DomainRuleException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report engine. Every query is restricted to rows the user may see (AccessScope);
 * client users only ever see APPROVED visits ($approvedOnly).
 */
class ReportService
{
    public const TYPES = [
        'campaign_performance' => 'Campaign performance',
        'attendance' => 'Attendance',
        'agent_performance' => 'Agent performance',
        'supervisor_performance' => 'Supervisor performance',
        'visit_completion' => 'Visit completion',
        'sales' => 'Sales',
        'osa' => 'On-shelf availability',
        'merchandising' => 'Merchandising compliance',
        'location_performance' => 'Location performance',
        'issue_management' => 'Issue management',
        'evidence' => 'Evidence quality',
        'activity_performance' => 'Activity performance',
        'territory_performance' => 'Territory performance',
    ];

    /** @return array{type:string,title:string,columns:list<array>,rows:list<array>,summary:array,approved_only:bool} */
    public function run(string $type, User $user, array $f = [], bool $approvedOnly = false): array
    {
        if (!isset(self::TYPES[$type])) {
            throw new DomainRuleException('Unknown report type.', 'unknown_report', 404);
        }
        $approvedOnly = $approvedOnly || $user->scopeType() === 'client';
        [$columns, $rows, $summary] = $this->{'report'.str_replace('_', '', ucwords($type, '_'))}($user, $f, $approvedOnly);

        return ['type' => $type, 'title' => self::TYPES[$type], 'columns' => $columns, 'rows' => $rows, 'summary' => $summary, 'approved_only' => $approvedOnly];
    }

    /* ----------------------------------------------------------------- base queries */

    public function visitBase(User $user, array $f, bool $approvedOnly = false): Builder
    {
        $q = DB::table('visits as v')
            ->join('locations as l', 'l.id', '=', 'v.location_id')
            ->join('campaigns as c', 'c.id', '=', 'v.campaign_id')
            ->join('users as ag', 'ag.id', '=', 'v.agent_id')
            ->whereIn('v.id', AccessScope::scope(Visit::query(), $user)->select('visits.id'));
        if ($approvedOnly) {
            $q->where('v.status', 'approved');
        }
        return $this->filters($q, $f, 'v', 'visit_date', 'v.agent_id');
    }

    public function assignmentBase(User $user, array $f): Builder
    {
        $q = DB::table('assignments as a')
            ->join('locations as l', 'l.id', '=', 'a.location_id')
            ->join('campaigns as c', 'c.id', '=', 'a.campaign_id')
            ->join('users as ag', 'ag.id', '=', 'a.agent_id')
            ->where('a.status', '!=', 'cancelled')
            ->whereIn('a.id', AccessScope::scope(Assignment::query(), $user)->select('assignments.id'));
        return $this->filters($q, $f, 'a', 'scheduled_date', 'a.agent_id');
    }

    private function filters(Builder $q, array $f, string $a, string $dateCol, string $agentCol): Builder
    {
        return $q
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where("$a.$dateCol", '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->where("$a.$dateCol", '<=', $v))
            ->when($f['client_id'] ?? null, fn ($q, $v) => $q->where('c.client_id', $v))
            ->when($f['campaign_id'] ?? null, fn ($q, $v) => $q->where("$a.campaign_id", $v))
            ->when($f['activity_id'] ?? null, fn ($q, $v) => $q->where("$a.activity_id", $v))
            ->when($f['agent_id'] ?? null, fn ($q, $v) => $q->where($agentCol, $v))
            ->when($f['location_id'] ?? null, fn ($q, $v) => $q->where("$a.location_id", $v))
            ->when($f['territory_id'] ?? null, fn ($q, $v) => $q->where('l.territory_id', $v))
            ->when($f['route_id'] ?? null, fn ($q, $v) => $q->whereIn("$a.location_id", DB::table('route_locations')->where('route_id', $v)->select('location_id')))
            ->when($f['team_id'] ?? null, fn ($q, $v) => $q->whereIn($agentCol, DB::table('team_members')->where('team_id', $v)->select('user_id')))
            ->when($f['supervisor_id'] ?? null, fn ($q, $v) => $q->whereIn($agentCol, DB::table('agent_profiles')->where('supervisor_id', $v)->select('user_id')));
    }

    private static function pct(int|float|null $num, int|float|null $den): ?float
    {
        return $den ? round(($num ?? 0) / $den * 100, 1) : null;
    }

    private function col(string $key, string $label, string $type = 'int'): array
    {
        return compact('key', 'label', 'type');
    }

    /* ----------------------------------------------------------------- reports */

    private function reportCampaignPerformance(User $u, array $f, bool $approved): array
    {
        $campaigns = AccessScope::scope(Campaign::query(), $u)->with('client:id,name')
            ->when($f['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($f['campaign_id'] ?? null, fn ($q, $v) => $q->whereKey($v))->orderBy('name')->get();

        $assign = $approved ? collect() : $this->assignmentBase($u, $f)->groupBy('a.campaign_id')
            ->selectRaw("a.campaign_id, count(*) as planned, count(*) filter (where a.status = 'completed') as completed, count(*) filter (where a.status = 'overdue') as overdue")
            ->get()->keyBy('campaign_id');
        $visits = $this->visitBase($u, $f, $approved)->groupBy('v.campaign_id')
            ->selectRaw("v.campaign_id, count(*) filter (where v.status in ('submitted','under_review','approved','rejected')) as submitted, count(*) filter (where v.status = 'approved') as approved, count(*) filter (where v.status = 'rejected') as rejected")
            ->get()->keyBy('campaign_id');
        $sales = $this->visitBase($u, $f, $approved)->join('sales as s', 's.visit_id', '=', 'v.id')->groupBy('v.campaign_id')
            ->selectRaw('v.campaign_id, sum(s.total_value) as value')->get()->keyBy('campaign_id');

        $rows = $campaigns->map(function ($c) use ($assign, $visits, $sales, $approved) {
            $a = $assign[$c->id] ?? null;
            $v = $visits[$c->id] ?? null;
            return [
                'campaign' => $c->name, 'client' => $c->client?->name, 'status' => $c->status->value,
                'planned' => $approved ? null : (int) ($a->planned ?? 0), 'completed' => $approved ? null : (int) ($a->completed ?? 0),
                'completion_rate' => $approved ? null : self::pct($a->completed ?? 0, $a->planned ?? 0),
                'overdue' => $approved ? null : (int) ($a->overdue ?? 0),
                'submitted' => (int) ($v->submitted ?? 0), 'approved' => (int) ($v->approved ?? 0), 'rejected' => (int) ($v->rejected ?? 0),
                'sales_value' => round((float) ($sales[$c->id]->value ?? 0), 2),
            ];
        })->values()->all();

        $cols = [$this->col('campaign', 'Campaign', 'text'), $this->col('client', 'Client', 'text'), $this->col('status', 'Status', 'status')];
        if (!$approved) {
            array_push($cols, $this->col('planned', 'Planned'), $this->col('completed', 'Completed'), $this->col('completion_rate', 'Completion', 'percent'), $this->col('overdue', 'Overdue'));
        }
        array_push($cols, $this->col('submitted', 'Submitted'), $this->col('approved', 'Approved'), $this->col('rejected', 'Rejected'), $this->col('sales_value', 'Sales value', 'money'));

        return [$cols, $rows, ['campaigns' => count($rows), 'approved_visits' => array_sum(array_column($rows, 'approved')), 'sales_value' => round(array_sum(array_column($rows, 'sales_value')), 2)]];
    }

    private function reportAttendance(User $u, array $f, bool $approved): array
    {
        $att = DB::table('attendance as at')->join('users as ag', 'ag.id', '=', 'at.user_id')
            ->whereIn('at.id', AccessScope::scope(Attendance::query(), $u)->select('attendance.id'))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('at.attendance_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('at.attendance_date', '<=', $v))
            ->when($f['agent_id'] ?? null, fn ($q, $v) => $q->where('at.user_id', $v))
            ->when($f['team_id'] ?? null, fn ($q, $v) => $q->whereIn('at.user_id', DB::table('team_members')->where('team_id', $v)->select('user_id')))
            ->when($f['supervisor_id'] ?? null, fn ($q, $v) => $q->whereIn('at.user_id', DB::table('agent_profiles')->where('supervisor_id', $v)->select('user_id')))
            ->groupBy('at.user_id')
            ->selectRaw("at.user_id, count(*) filter (where at.status = 'present') as present, count(*) filter (where at.status = 'late') as late, count(*) filter (where at.status = 'absent') as absent, avg(extract(epoch from (at.clock_out_at - at.clock_in_at)) / 3600) as avg_hours")
            ->get()->keyBy('user_id');

        $days = $this->assignmentBase($u, $f)->where('a.scheduled_date', '<=', now()->toDateString())->groupBy('a.agent_id')
            ->selectRaw('a.agent_id, count(distinct a.scheduled_date) as days')->pluck('days', 'agent_id');

        $ids = $att->keys()->merge($days->keys())->unique();
        $names = DB::table('users')->whereIn('id', $ids)->pluck('name', 'id');
        $rows = $ids->map(function ($id) use ($att, $days, $names) {
            $a = $att[$id] ?? null;
            $worked = (int) ($a->present ?? 0) + (int) ($a->late ?? 0);
            return [
                'agent' => $names[$id] ?? '—', 'assigned_days' => (int) ($days[$id] ?? 0), 'present' => (int) ($a->present ?? 0), 'late' => (int) ($a->late ?? 0),
                'absent' => max((int) ($a->absent ?? 0), max(0, (int) ($days[$id] ?? 0) - $worked)),
                'attendance_rate' => self::pct($worked, $days[$id] ?? 0), 'avg_hours' => isset($a->avg_hours) ? round((float) $a->avg_hours, 1) : null,
            ];
        })->sortBy('agent')->values()->all();

        return [[
            $this->col('agent', 'Agent', 'text'), $this->col('assigned_days', 'Assigned days'), $this->col('present', 'Present'), $this->col('late', 'Late'),
            $this->col('absent', 'Absent'), $this->col('attendance_rate', 'Attendance rate', 'percent'), $this->col('avg_hours', 'Avg hours on duty', 'decimal'),
        ], $rows, ['agents' => count($rows)]];
    }

    private function reportAgentPerformance(User $u, array $f, bool $approved): array
    {
        $assign = $this->assignmentBase($u, $f)->groupBy('a.agent_id', 'ag.name')
            ->selectRaw("a.agent_id, ag.name, count(*) as assigned, count(*) filter (where a.status = 'completed') as completed")->get();
        $vis = $this->visitBase($u, $f)->groupBy('v.agent_id')
            ->selectRaw("v.agent_id, count(*) filter (where v.status in ('submitted','under_review','approved','rejected')) as submitted, count(*) filter (where v.status = 'approved') as approved, count(*) filter (where v.status = 'rejected') as rejected, count(*) filter (where v.gps_outcome = 'suspicious') as flagged")->get()->keyBy('agent_id');
        $sales = $this->visitBase($u, $f)->join('sales as s', 's.visit_id', '=', 'v.id')->groupBy('v.agent_id')->selectRaw('v.agent_id, sum(s.total_value) as value')->get()->keyBy('agent_id');
        $photos = $this->visitBase($u, $f)->join('photos as p', 'p.visit_id', '=', 'v.id')->groupBy('v.agent_id')
            ->selectRaw("v.agent_id, count(*) as total, count(*) filter (where p.review_status = 'accepted') as accepted")->get()->keyBy('agent_id');

        $rows = $assign->map(fn ($a) => [
            'agent' => $a->name, 'assigned' => (int) $a->assigned, 'completed' => (int) $a->completed, 'completion_rate' => self::pct($a->completed, $a->assigned),
            'submitted' => (int) ($vis[$a->agent_id]->submitted ?? 0), 'approved' => (int) ($vis[$a->agent_id]->approved ?? 0), 'rejected' => (int) ($vis[$a->agent_id]->rejected ?? 0),
            'approval_rate' => self::pct($vis[$a->agent_id]->approved ?? 0, $vis[$a->agent_id]->submitted ?? 0),
            'gps_flags' => (int) ($vis[$a->agent_id]->flagged ?? 0), 'sales_value' => round((float) ($sales[$a->agent_id]->value ?? 0), 2),
            'photo_acceptance' => self::pct($photos[$a->agent_id]->accepted ?? 0, $photos[$a->agent_id]->total ?? 0),
        ])->sortByDesc('completion_rate')->values()->all();

        return [[
            $this->col('agent', 'Agent', 'text'), $this->col('assigned', 'Assigned'), $this->col('completed', 'Completed'), $this->col('completion_rate', 'Completion', 'percent'),
            $this->col('submitted', 'Submitted'), $this->col('approved', 'Approved'), $this->col('rejected', 'Rejected'), $this->col('approval_rate', 'Approval rate', 'percent'),
            $this->col('gps_flags', 'GPS flags'), $this->col('photo_acceptance', 'Photo acceptance', 'percent'), $this->col('sales_value', 'Sales value', 'money'),
        ], $rows, ['agents' => count($rows)]];
    }

    private function reportSupervisorPerformance(User $u, array $f, bool $approved): array
    {
        $sup = DB::table('agent_profiles')->select('user_id', 'supervisor_id')->whereNotNull('supervisor_id');
        $assign = $this->assignmentBase($u, $f)->joinSub($sup, 'ap', 'ap.user_id', '=', 'a.agent_id')->groupBy('ap.supervisor_id')
            ->selectRaw("ap.supervisor_id, count(distinct a.agent_id) as agents, count(*) as assigned, count(*) filter (where a.status = 'completed') as completed")->get();
        $reviews = DB::table('visits as v')->whereIn('v.id', AccessScope::scope(Visit::query(), $u)->select('visits.id'))->whereNotNull('v.reviewed_by')->whereNotNull('v.reviewed_at')
            ->groupBy('v.reviewed_by')->selectRaw('v.reviewed_by, count(*) as reviewed, avg(extract(epoch from (v.reviewed_at - v.submitted_at)) / 3600) as avg_hours')->get()->keyBy('reviewed_by');
        $names = DB::table('users')->whereIn('id', $assign->pluck('supervisor_id'))->pluck('name', 'id');

        $rows = $assign->map(fn ($a) => [
            'supervisor' => $names[$a->supervisor_id] ?? '—', 'agents' => (int) $a->agents, 'assigned' => (int) $a->assigned, 'completed' => (int) $a->completed,
            'completion_rate' => self::pct($a->completed, $a->assigned), 'reviewed' => (int) ($reviews[$a->supervisor_id]->reviewed ?? 0),
            'avg_review_hours' => isset($reviews[$a->supervisor_id]) ? round((float) $reviews[$a->supervisor_id]->avg_hours, 1) : null,
        ])->sortBy('supervisor')->values()->all();

        return [[
            $this->col('supervisor', 'Supervisor', 'text'), $this->col('agents', 'Agents'), $this->col('assigned', 'Assigned'), $this->col('completed', 'Completed'),
            $this->col('completion_rate', 'Completion', 'percent'), $this->col('reviewed', 'Visits reviewed'), $this->col('avg_review_hours', 'Avg review time (h)', 'decimal'),
        ], $rows, ['supervisors' => count($rows)]];
    }

    private function reportVisitCompletion(User $u, array $f, bool $approved): array
    {
        $rows = $this->visitBase($u, $f, $approved)->groupBy('v.visit_date')->orderBy('v.visit_date')
            ->selectRaw("v.visit_date as date, count(*) as total, count(*) filter (where v.status = 'scheduled') as scheduled, count(*) filter (where v.status = 'started') as started, count(*) filter (where v.status = 'completed') as completed, count(*) filter (where v.status = 'submitted') as submitted, count(*) filter (where v.status = 'under_review') as under_review, count(*) filter (where v.status = 'approved') as approved, count(*) filter (where v.status = 'rejected') as rejected")
            ->get()->map(fn ($r) => (array) $r)->all();

        $cols = [$this->col('date', 'Date', 'date')];
        foreach (['total' => 'Total', 'scheduled' => 'Scheduled', 'started' => 'Started', 'completed' => 'Completed', 'submitted' => 'Submitted', 'under_review' => 'Under review', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $l) {
            $cols[] = $this->col($k, $l);
        }
        return [$cols, $rows, ['visits' => array_sum(array_column($rows, 'total')), 'approved' => array_sum(array_column($rows, 'approved'))]];
    }

    private function reportSales(User $u, array $f, bool $approved): array
    {
        // Dimension expressions come from this allow-list only; user input never reaches SQL.
        $dims = [
            'product' => ['p.name', 'Product'], 'agent' => ['ag.name', 'Agent'], 'location' => ['l.name', 'Location'],
            'campaign' => ['c.name', 'Campaign'], 'date' => ['v.visit_date', 'Date'], 'territory' => ["coalesce(t.name, 'Unassigned')", 'Territory'],
        ];
        [$expr, $label] = $dims[$f['group_by'] ?? 'product'] ?? $dims['product'];

        $rows = $this->visitBase($u, $f, $approved)->join('sales as s', 's.visit_id', '=', 'v.id')->join('sales_items as si', 'si.sale_id', '=', 's.id')
            ->join('products as p', 'p.id', '=', 'si.product_id')->leftJoin('territories as t', 't.id', '=', 'l.territory_id')
            ->groupByRaw($expr)->orderByRaw('sum(si.line_total) desc')
            ->selectRaw("$expr as dimension, sum(si.quantity) as units, sum(si.line_total) as value, count(distinct s.id) as transactions, coalesce(sum(si.quantity) filter (where si.is_promo), 0) as promo_units")
            ->get()->map(fn ($r) => [
                'dimension' => $r->dimension, 'units' => (int) $r->units, 'value' => round((float) $r->value, 2), 'transactions' => (int) $r->transactions, 'promo_units' => (int) $r->promo_units,
            ])->all();

        return [[
            $this->col('dimension', $label, $label === 'Date' ? 'date' : 'text'), $this->col('units', 'Units'), $this->col('value', 'Value', 'money'),
            $this->col('transactions', 'Transactions'), $this->col('promo_units', 'Promo units'),
        ], $rows, ['units' => array_sum(array_column($rows, 'units')), 'value' => round(array_sum(array_column($rows, 'value')), 2)]];
    }

    private function reportOsa(User $u, array $f, bool $approved): array
    {
        $rows = $this->visitBase($u, $f, $approved)->join('osa_records as o', 'o.visit_id', '=', 'v.id')->join('products as p', 'p.id', '=', 'o.product_id')
            ->groupBy('p.id', 'p.name')->orderBy('p.name')
            ->selectRaw('p.name as product, count(*) as records, count(*) filter (where o.available) as available, count(*) filter (where o.out_of_stock) as out_of_stock, avg(o.facings) as avg_facings, avg(o.share_of_shelf) as avg_share')
            ->get()->map(fn ($r) => [
                'product' => $r->product, 'records' => (int) $r->records, 'available' => (int) $r->available, 'out_of_stock' => (int) $r->out_of_stock,
                'availability' => self::pct($r->available, $r->records), 'avg_facings' => $r->avg_facings !== null ? round((float) $r->avg_facings, 1) : null,
                'avg_share' => $r->avg_share !== null ? round((float) $r->avg_share, 1) : null,
            ])->all();

        $tot = array_sum(array_column($rows, 'records'));
        return [[
            $this->col('product', 'Product', 'text'), $this->col('records', 'Checks'), $this->col('available', 'Available'), $this->col('out_of_stock', 'Out of stock'),
            $this->col('availability', 'Availability', 'percent'), $this->col('avg_facings', 'Avg facings', 'decimal'), $this->col('avg_share', 'Avg shelf share', 'percent'),
        ], $rows, ['checks' => $tot, 'availability' => self::pct(array_sum(array_column($rows, 'available')), $tot)]];
    }

    private function reportMerchandising(User $u, array $f, bool $approved): array
    {
        $rows = $this->visitBase($u, $f, $approved)->join('merchandising_records as m', 'm.visit_id', '=', 'v.id')
            ->groupBy('l.id', 'l.name')->orderBy('l.name')
            ->selectRaw('l.name as location, count(*) as records, avg(m.posm_present::int) * 100 as posm, avg(m.planogram_compliant::int) * 100 as planogram, avg(m.price_compliant::int) * 100 as price, avg(m.competitor_present::int) * 100 as competitor')
            ->get()->map(fn ($r) => [
                'location' => $r->location, 'records' => (int) $r->records,
                'posm' => $r->posm !== null ? round((float) $r->posm, 1) : null, 'planogram' => $r->planogram !== null ? round((float) $r->planogram, 1) : null,
                'price' => $r->price !== null ? round((float) $r->price, 1) : null, 'competitor' => $r->competitor !== null ? round((float) $r->competitor, 1) : null,
            ])->all();

        return [[
            $this->col('location', 'Location', 'text'), $this->col('records', 'Records'), $this->col('posm', 'POSM present', 'percent'),
            $this->col('planogram', 'Planogram compliant', 'percent'), $this->col('price', 'Price compliant', 'percent'), $this->col('competitor', 'Competitor present', 'percent'),
        ], $rows, ['records' => array_sum(array_column($rows, 'records'))]];
    }

    private function reportLocationPerformance(User $u, array $f, bool $approved): array
    {
        $vis = $this->visitBase($u, $f, $approved)->groupBy('l.id', 'l.name', 'l.town')
            ->selectRaw("l.id, l.name, l.town, count(*) as visits, count(*) filter (where v.status = 'approved') as approved, count(*) filter (where v.gps_outcome = 'suspicious') as flagged")->get();
        $sales = $this->visitBase($u, $f, $approved)->join('sales as s', 's.visit_id', '=', 'v.id')->groupBy('l.id')->selectRaw('l.id, sum(s.total_value) as value')->pluck('value', 'id');
        $osa = $this->visitBase($u, $f, $approved)->join('osa_records as o', 'o.visit_id', '=', 'v.id')->groupBy('l.id')
            ->selectRaw('l.id, count(*) as n, count(*) filter (where o.available) as ok')->get()->keyBy('id');
        $issues = $this->visitBase($u, $f, $approved)->join('issues as i', 'i.visit_id', '=', 'v.id')->groupBy('l.id')->selectRaw('l.id, count(*) as n')->pluck('n', 'id');

        $rows = $vis->map(fn ($r) => [
            'location' => $r->name, 'town' => $r->town, 'visits' => (int) $r->visits, 'approved' => (int) $r->approved, 'gps_flags' => (int) $r->flagged,
            'sales_value' => round((float) ($sales[$r->id] ?? 0), 2), 'osa_availability' => self::pct($osa[$r->id]->ok ?? 0, $osa[$r->id]->n ?? 0), 'issues' => (int) ($issues[$r->id] ?? 0),
        ])->sortByDesc('visits')->values()->all();

        return [[
            $this->col('location', 'Location', 'text'), $this->col('town', 'Town', 'text'), $this->col('visits', 'Visits'), $this->col('approved', 'Approved'),
            $this->col('sales_value', 'Sales value', 'money'), $this->col('osa_availability', 'OSA', 'percent'), $this->col('issues', 'Issues'), $this->col('gps_flags', 'GPS flags'),
        ], $rows, ['locations' => count($rows)]];
    }

    private function reportIssueManagement(User $u, array $f, bool $approved): array
    {
        if ($approved) {
            return [[$this->col('status', 'Status', 'text')], [], ['note' => 'Issue reports are not available to client users.']];
        }
        $q = DB::table('issues as i')->whereIn('i.id', AccessScope::scope(Issue::query(), $u)->select('issues.id'))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->whereDate('i.created_at', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->whereDate('i.created_at', '<=', $v))
            ->when($f['campaign_id'] ?? null, fn ($q, $v) => $q->where('i.campaign_id', $v))
            ->when($f['agent_id'] ?? null, fn ($q, $v) => $q->where('i.reported_by', $v))
            ->when($f['location_id'] ?? null, fn ($q, $v) => $q->where('i.location_id', $v));
        $rows = (clone $q)->groupBy('i.status', 'i.priority')->orderBy('i.status')->orderBy('i.priority')
            ->selectRaw('i.status, i.priority, count(*) as total, avg(extract(epoch from (coalesce(i.resolved_at, now()) - i.created_at)) / 86400) as avg_age_days')
            ->get()->map(fn ($r) => ['status' => $r->status, 'priority' => $r->priority, 'total' => (int) $r->total, 'avg_age_days' => round((float) $r->avg_age_days, 1)])->all();
        $stats = (clone $q)->selectRaw("count(*) as total, count(*) filter (where i.status not in ('resolved','closed')) as open, count(*) filter (where i.status not in ('resolved','closed') and i.created_at < now() - interval '7 days') as aged, avg(extract(epoch from (i.resolved_at - i.created_at)) / 3600) filter (where i.resolved_at is not null) as avg_resolution_h")->first();

        return [[
            $this->col('status', 'Status', 'status'), $this->col('priority', 'Priority', 'text'), $this->col('total', 'Issues'), $this->col('avg_age_days', 'Avg age (days)', 'decimal'),
        ], $rows, ['total' => (int) $stats->total, 'open' => (int) $stats->open, 'open_over_7_days' => (int) $stats->aged, 'avg_resolution_hours' => $stats->avg_resolution_h !== null ? round((float) $stats->avg_resolution_h, 1) : null]];
    }

    private function reportEvidence(User $u, array $f, bool $approved): array
    {
        $photos = $this->visitBase($u, $f, $approved)->join('photos as p', 'p.visit_id', '=', 'v.id')->groupBy('c.id', 'c.name')
            ->selectRaw("c.id, c.name, count(*) as total, count(*) filter (where p.review_status = 'accepted') as accepted, count(*) filter (where p.review_status = 'rejected') as rejected, count(*) filter (where p.review_status = 'pending') as pending")->get();
        $noPhotos = $this->visitBase($u, $f, $approved)->whereIn('v.status', ['submitted', 'under_review', 'approved', 'rejected'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('photos as px')->whereColumn('px.visit_id', 'v.id'))->groupBy('c.id')->selectRaw('c.id, count(*) as n')->pluck('n', 'id');
        $submitted = $this->visitBase($u, $f, $approved)->whereIn('v.status', ['submitted', 'under_review', 'approved', 'rejected'])->groupBy('c.id')->selectRaw('c.id, count(*) as n')->pluck('n', 'id');

        $rows = $photos->map(fn ($r) => [
            'campaign' => $r->name, 'visits_submitted' => (int) ($submitted[$r->id] ?? 0), 'visits_without_photos' => (int) ($noPhotos[$r->id] ?? 0),
            'photos' => (int) $r->total, 'accepted' => (int) $r->accepted, 'rejected' => (int) $r->rejected, 'pending' => (int) $r->pending, 'acceptance_rate' => self::pct($r->accepted, $r->accepted + $r->rejected),
        ])->all();

        return [[
            $this->col('campaign', 'Campaign', 'text'), $this->col('visits_submitted', 'Visits submitted'), $this->col('visits_without_photos', 'Without photos'), $this->col('photos', 'Photos'),
            $this->col('accepted', 'Accepted'), $this->col('rejected', 'Rejected'), $this->col('pending', 'Pending review'), $this->col('acceptance_rate', 'Acceptance', 'percent'),
        ], $rows, ['photos' => array_sum(array_column($rows, 'photos'))]];
    }

    private function reportActivityPerformance(User $u, array $f, bool $approved): array
    {
        $acts = DB::table('activities as ac')->join('campaigns as c', 'c.id', '=', 'ac.campaign_id')
            ->whereIn('ac.campaign_id', AccessScope::scope(Campaign::query(), $u)->select('campaigns.id'))
            ->when($f['client_id'] ?? null, fn ($q, $v) => $q->where('c.client_id', $v))
            ->when($f['campaign_id'] ?? null, fn ($q, $v) => $q->where('ac.campaign_id', $v))
            ->when($f['activity_id'] ?? null, fn ($q, $v) => $q->where('ac.id', $v))
            ->orderBy('c.name')->orderBy('ac.name')->get(['ac.id', 'ac.name', 'ac.target', 'ac.status', 'c.name as campaign']);
        $vis = $this->visitBase($u, $f, $approved)->whereNotNull('v.activity_id')->groupBy('v.activity_id')
            ->selectRaw("v.activity_id, count(*) filter (where v.status in ('submitted','under_review','approved','rejected')) as submitted, count(*) filter (where v.status = 'approved') as approved")->get()->keyBy('activity_id');
        $planned = $approved ? collect() : $this->assignmentBase($u, $f)->whereNotNull('a.activity_id')->groupBy('a.activity_id')->selectRaw('a.activity_id, count(*) as n')->pluck('n', 'activity_id');

        $rows = $acts->map(fn ($a) => [
            'campaign' => $a->campaign, 'activity' => $a->name, 'status' => $a->status, 'target' => $a->target, 'planned' => $approved ? null : (int) ($planned[$a->id] ?? 0),
            'submitted' => (int) ($vis[$a->id]->submitted ?? 0), 'approved' => (int) ($vis[$a->id]->approved ?? 0), 'attainment' => self::pct($vis[$a->id]->approved ?? 0, $a->target),
        ])->all();

        return [[
            $this->col('campaign', 'Campaign', 'text'), $this->col('activity', 'Activity', 'text'), $this->col('status', 'Status', 'status'), $this->col('target', 'Target'),
            $this->col('planned', 'Planned'), $this->col('submitted', 'Submitted'), $this->col('approved', 'Approved'), $this->col('attainment', 'Attainment', 'percent'),
        ], $rows, ['activities' => count($rows)]];
    }

    private function reportTerritoryPerformance(User $u, array $f, bool $approved): array
    {
        $vis = $this->visitBase($u, $f, $approved)->leftJoin('territories as t', 't.id', '=', 'l.territory_id')->groupByRaw("coalesce(t.name, 'Unassigned')")
            ->selectRaw("coalesce(t.name, 'Unassigned') as territory, count(distinct l.id) as locations, count(*) as visits, count(*) filter (where v.status = 'approved') as approved")->get();
        $sales = $this->visitBase($u, $f, $approved)->join('sales as s', 's.visit_id', '=', 'v.id')->leftJoin('territories as t', 't.id', '=', 'l.territory_id')
            ->groupByRaw("coalesce(t.name, 'Unassigned')")->selectRaw("coalesce(t.name, 'Unassigned') as territory, sum(s.total_value) as value")->pluck('value', 'territory');

        $rows = $vis->map(fn ($r) => [
            'territory' => $r->territory, 'locations' => (int) $r->locations, 'visits' => (int) $r->visits, 'approved' => (int) $r->approved,
            'approval_rate' => self::pct($r->approved, $r->visits), 'sales_value' => round((float) ($sales[$r->territory] ?? 0), 2),
        ])->sortBy('territory')->values()->all();

        return [[
            $this->col('territory', 'Territory', 'text'), $this->col('locations', 'Locations'), $this->col('visits', 'Visits'), $this->col('approved', 'Approved'),
            $this->col('approval_rate', 'Approval rate', 'percent'), $this->col('sales_value', 'Sales value', 'money'),
        ], $rows, ['territories' => count($rows)]];
    }

    /* ----------------------------------------------------------------- export */

    /** Excel-compatible CSV (UTF-8 BOM). Cells are neutralised against spreadsheet formula injection. */
    public function csv(array $report): StreamedResponse
    {
        $name = $report['type'].'_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_column($report['columns'], 'label'));
            foreach ($report['rows'] as $row) {
                fputcsv($out, array_map(fn ($c) => self::safeCell($row[$c['key']] ?? ''), $report['columns']));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function safeCell(mixed $v): string
    {
        $s = is_bool($v) ? ($v ? 'yes' : 'no') : (string) ($v ?? '');
        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($s) ? "'".$s : $s;
    }
}
