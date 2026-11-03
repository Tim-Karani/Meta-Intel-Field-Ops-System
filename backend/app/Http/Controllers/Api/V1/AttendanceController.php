<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditService;
use App\Models\Assignment;
use App\Domain\Access\AccessScope;
use App\Models\Attendance;
use App\Models\User;
use App\Models\Visit;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends ResourceController
{
    public const LATE_AFTER_MINUTES = 15;

    protected string $model = Attendance::class;
    protected array $with = ['user:id,name'];
    protected array $filters = ['user_id' => 'user_id', 'status' => 'status'];
    protected array $sortable = ['attendance_date', 'clock_in_at', 'status'];
    protected string $defaultSort = '-attendance_date';

    protected function rules(?Model $model = null): array
    {
        return [];
    }

    protected function indexQuery(Builder $query, Request $request): Builder
    {
        $request->validate(['date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d']]);
        if ($focus = \App\Domain\Access\ProjectFocus::campaignIds($request->query())) {
            $query->whereIn('attendance.user_id', \App\Domain\Access\ProjectFocus::agentIds($focus));
        }
        if ($from = $request->query('date_from')) {
            $query->where('attendance.attendance_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->where('attendance.attendance_date', '<=', $to);
        }

        return $query;
    }

    /** POST /attendance/clock-in — one record per agent per day; replays return the existing record. */
    public function clockIn(Request $request): JsonResponse
    {
        $this->authorize('create', Attendance::class);
        $user = $request->user();
        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'assignment_id' => ['nullable', 'uuid', new Accessible(Assignment::class, fn ($q) => $q->where('assignments.agent_id', $user->getKey()))],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $today = now()->toDateString();
        $existing = Attendance::query()->where('user_id', $user->getKey())->where('attendance_date', $today)->first()
            ?? (!empty($data['client_uuid']) ? Attendance::query()->where('user_id', $user->getKey())->where('client_uuid', $data['client_uuid'])->first() : null);
        if ($existing) {
            return $this->ok($this->present($existing), 'Already clocked in.');
        }

        // Late = more than 15 minutes after the earliest scheduled start time of today's assignments.
        $earliest = DB::table('assignments')->where('agent_id', $user->getKey())->whereDate('scheduled_date', $today)
            ->whereNotIn('status', ['cancelled'])->whereNotNull('start_time')->min('start_time');
        $status = 'present';
        if ($earliest && now()->greaterThan(now()->setTimeFromTimeString($earliest)->addMinutes(self::LATE_AFTER_MINUTES))) {
            $status = 'late';
        }

        $row = Attendance::query()->create([
            'client_uuid' => $data['client_uuid'] ?? null, 'user_id' => $user->getKey(), 'assignment_id' => $data['assignment_id'] ?? null,
            'attendance_date' => $today, 'clock_in_at' => now(), 'clock_in_lat' => $data['latitude'] ?? null, 'clock_in_lng' => $data['longitude'] ?? null,
            'clock_in_accuracy_m' => $data['accuracy_m'] ?? null, 'status' => $status, 'notes' => $data['notes'] ?? null,
        ]);
        AuditService::record('attendance.clock_in', 'attendance', $row, null, ['status' => $status]);

        return $this->created($this->present($row), 'Clocked in.');
    }

    public function clockOut(Request $request): JsonResponse
    {
        $this->authorize('create', Attendance::class);
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $row = Attendance::query()->where('user_id', $request->user()->getKey())->where('attendance_date', now()->toDateString())->first();
        if (!$row || !$row->clock_in_at) {
            throw DomainRuleException::conflict('You have not clocked in today.', 'not_clocked_in');
        }
        if ($row->clock_out_at) {
            return $this->ok($this->present($row), 'Already clocked out.');
        }
        $row->update(['clock_out_at' => now(), 'clock_out_lat' => $data['latitude'] ?? null, 'clock_out_lng' => $data['longitude'] ?? null]);

        return $this->ok($this->present($row->refresh()), 'Clocked out.');
    }

    /**
     * GET /attendance/board?date=YYYY-MM-DD — for Team Leaders, Supervisors, Operations Managers and administrators (each sees
     * their own scope). One row per person: when they clocked in, when they actually STARTED WORK (first outlet check-in), how
     * long they worked, and every outlet check-in of the day with distance, GPS outcome and whether the check-in/out photos exist.
     */
    public function board(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Attendance::class);
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? now()->toDateString();
        $me = $request->user();
        abort_if(in_array($me->scopeType(), ['own', 'client'], true), 403);

        $people = AccessScope::scope(User::query(), $me)->where('users.status', 'active')
            ->whereHas('roles', fn ($r) => $r->whereIn('slug', ['field_agent', 'team_leader']))
            ->when(\App\Domain\Access\ProjectFocus::campaignIds($request->query()), fn ($q, $f) => $q->whereIn('users.id', \App\Domain\Access\ProjectFocus::agentIds($f)))
            ->whereKeyNot($me->getKey())->pluck('users.id');

        $profiles = DB::table('agent_profiles as ap')->leftJoin('users as tl', 'tl.id', '=', 'ap.team_leader_id')->whereIn('ap.user_id', $people)
            ->get(['ap.user_id', 'ap.region', 'ap.employment_type', 'ap.agent_code', 'tl.name as team_leader'])->keyBy('user_id');
        $att = DB::table('attendance')->whereIn('user_id', $people)->where('attendance_date', $date)->get()->keyBy('user_id');

        $visits = DB::table('visits as v')
            ->join('locations as l', 'l.id', '=', 'v.location_id')->join('campaigns as c', 'c.id', '=', 'v.campaign_id')
            ->leftJoin('visit_checkins as vc', 'vc.visit_id', '=', 'v.id')
            ->whereIn('v.id', AccessScope::scope(Visit::query(), $me)->select('visits.id'))
            ->when(\App\Domain\Access\ProjectFocus::campaignIds($request->query()), fn ($q, $f) => $q->whereIn('v.campaign_id', $f))->whereIn('v.agent_id', $people)->where('v.visit_date', $date)->whereNotNull('v.started_at')
            ->orderBy('v.started_at')
            ->get(['v.id', 'v.agent_id', 'v.status', 'v.started_at', 'v.completed_at', 'v.gps_outcome', 'l.name as outlet', 'c.name as campaign',
                'vc.distance_m', 'vc.accuracy_m', 'vc.mock_flag',
                DB::raw("(select id from photos p where p.visit_id = v.id and p.kind = 'checkin' order by p.captured_at limit 1) as checkin_photo_id"),
                DB::raw("(select id from photos p where p.visit_id = v.id and p.kind = 'checkout' order by p.captured_at limit 1) as checkout_photo_id")]);
        $byAgent = $visits->groupBy('agent_id');
        $names = User::query()->whereIn('id', $people)->pluck('name', 'id');

        $rows = $people->map(function ($id) use ($att, $byAgent, $profiles, $names) {
            $a = $att[$id] ?? null;
            $v = $byAgent[$id] ?? collect();
            $open = $v->firstWhere('status', 'started');
            $state = match (true) {
                $open !== null => 'at_outlet',
                $v->isNotEmpty() => 'worked',
                $a && $a->clock_in_at => 'clocked_in_not_working',
                default => 'not_started',
            };
            return [
                'user_id' => $id, 'name' => $names[$id] ?? '—', 'region' => $profiles[$id]->region ?? null, 'team_leader' => $profiles[$id]->team_leader ?? null,
                'employment_type' => $profiles[$id]->employment_type ?? null, 'agent_code' => $profiles[$id]->agent_code ?? null,
                'clock_in_at' => $a?->clock_in_at, 'clock_out_at' => $a?->clock_out_at,
                'work_started_at' => $a?->work_started_at, 'work_ended_at' => $a?->work_ended_at, 'worked_minutes' => $a?->worked_minutes,
                'outlets' => $v->count(), 'current_outlet' => $open?->outlet, 'state' => $state,
            ];
        })->sortBy('name')->values()->all();

        $summary = ['people' => count($rows)] + collect(['at_outlet', 'worked', 'clocked_in_not_working', 'not_started'])
            ->mapWithKeys(fn ($s) => [$s => collect($rows)->where('state', $s)->count()])->all();

        return $this->ok(['date' => $date, 'summary' => $summary, 'people' => $rows, 'checkins' => $visits->values()]);
    }

    /** Today's record for the current user (null data when not clocked in). */
    public function today(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Attendance::class);
        $row = Attendance::query()->where('user_id', $request->user()->getKey())->where('attendance_date', now()->toDateString())->first();

        return $this->ok($row ? $this->present($row) : null);
    }
}
