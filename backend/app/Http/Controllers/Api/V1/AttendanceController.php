<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditService;
use App\Models\Assignment;
use App\Models\Attendance;
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

    /** Today's record for the current user (null data when not clocked in). */
    public function today(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Attendance::class);
        $row = Attendance::query()->where('user_id', $request->user()->getKey())->where('attendance_date', now()->toDateString())->first();

        return $this->ok($row ? $this->present($row) : null);
    }
}
