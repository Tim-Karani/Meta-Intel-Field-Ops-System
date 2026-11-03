<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditService;
use App\Domain\Reports\ReportService;
use App\Domain\Reports\ScheduledReportService;
use App\Models\ReportSchedule;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Scheduled reports. Everyone with `reports.schedule` manages THEIR OWN schedules; an administrator (scope "all") sees and manages all.
 * Reports are emailed from the schedule owner's access (see ScheduledReportService).
 */
class ReportScheduleController extends ApiController
{
    public function __construct(private ScheduledReportService $service) {}

    public function options(): JsonResponse
    {
        return $this->ok([
            'types' => collect(ReportService::TYPES)->map(fn ($label, $type) => ['value' => $type, 'label' => $label])->values(),
            'periods' => collect(ScheduledReportService::PERIODS)->map(fn ($label, $v) => ['value' => $v, 'label' => $label])->values(),
            'frequencies' => [['value' => 'daily', 'label' => 'Every day'], ['value' => 'weekly', 'label' => 'Every week'], ['value' => 'monthly', 'label' => 'Every month']],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $q = $this->visible($request)->with('owner:id,name');
        // Focus on a client / project: schedules aimed at it, plus schedules that are not limited to one project.
        if ($focus = \App\Domain\Access\ProjectFocus::campaignIds($request->query())) {
            $ids = $focus->pluck('id')->all();
            $client = $request->query('client_id');
            $q->where(fn ($w) => $w->whereIn(\Illuminate\Support\Facades\DB::raw("report_schedules.filters->>'campaign_id'"), $ids)
                ->orWhere(fn ($x) => is_string($client) && !$request->query('campaign_id') ? $x->whereRaw("report_schedules.filters->>'client_id' = ?", [$client]) : $x->whereRaw('false'))
                ->orWhereRaw("coalesce(report_schedules.filters->>'campaign_id', report_schedules.filters->>'client_id') is null"));
        }
        $rows = $q->orderBy('name')->get()->map(fn ($s) => $this->present($s));

        return $this->ok($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $schedule = ReportSchedule::query()->create($data + ['owner_id' => $request->user()->getKey()]);
        AuditService::record('report.schedule_created', 'reports', $schedule, null, ['type' => $schedule->report_type, 'recipients' => count($schedule->recipients)]);

        return $this->created($this->present($schedule->load('owner:id,name')), 'Report schedule created.');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $schedule = $this->find($request, $id);
        $schedule->update($this->validated($request));
        AuditService::record('report.schedule_updated', 'reports', $schedule);

        return $this->ok($this->present($schedule->load('owner:id,name')), 'Report schedule updated.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $schedule = $this->find($request, $id);
        AuditService::record('report.schedule_deleted', 'reports', $schedule);
        $schedule->delete();

        return $this->ok(null, 'Report schedule deleted.');
    }

    /** POST /report-schedules/{id}/send — send it right now (also a quick way to test the recipients). */
    public function sendNow(Request $request, string $id): JsonResponse
    {
        $schedule = $this->find($request, $id);
        $run = $this->service->send($schedule);

        return $this->ok($run, $run->status === 'failed' ? 'Sending failed: '.$run->error : 'Report sent to '.$run->recipient_count.' recipient(s).');
    }

    public function runs(Request $request, string $id): JsonResponse
    {
        return $this->ok($this->find($request, $id)->runs()->limit(30)->get());
    }

    /* ------------------------------------------------------------------ */

    private function visible(Request $request)
    {
        $q = ReportSchedule::query();

        return $request->user()->scopeType() === 'all' ? $q : $q->where('owner_id', $request->user()->getKey());
    }

    private function find(Request $request, string $id): ReportSchedule
    {
        $this->assertUuid($id);

        return $this->visible($request)->findOrFail($id);
    }

    private function present(ReportSchedule $s): array
    {
        return $s->toArray() + ['owner_name' => $s->owner?->name];
    }

    private function validated(Request $request): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'report_type' => ['required', Rule::in(array_keys(ReportService::TYPES))],
            'period' => ['required', Rule::in(array_keys(ScheduledReportService::PERIODS))],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'weekday' => ['nullable', 'integer', 'between:1,7', Rule::requiredIf($request->input('frequency') === 'weekly')],
            'month_day' => ['nullable', 'integer', 'between:1,28', Rule::requiredIf($request->input('frequency') === 'monthly')],
            'send_time' => ['required', 'date_format:H:i'],
            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['required', 'email:rfc', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'filters' => ['nullable', 'array'],
        ];
        foreach (ReportController::FILTERS as $f) {
            $rules["filters.$f"] = ['nullable', 'uuid'];
        }
        $data = $request->validate($rules);
        if ($request->user()->scopeType() === 'client') {
            throw new DomainRuleException('Client users cannot schedule reports.', 'forbidden', 403);
        }
        $data['recipients'] = array_values(array_unique(array_map('strtolower', $data['recipients'])));
        $data['filters'] = array_filter($data['filters'] ?? [], fn ($v) => $v !== null && $v !== '');
        if ($data['frequency'] !== 'weekly') {
            $data['weekday'] = null;
        }
        if ($data['frequency'] !== 'monthly') {
            $data['month_day'] = null;
        }

        return $data;
    }
}
