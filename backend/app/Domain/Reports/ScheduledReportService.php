<?php

namespace App\Domain\Reports;

use App\Domain\Audit\AuditService;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Builds scheduled reports and emails them as CSV attachments.
 *
 * Rules that keep this safe:
 *  - the report is built with the OWNER's access (AccessScope), so a schedule can never reveal more than its owner may see;
 *  - if the owner is disabled or loses the reports permission the schedule is paused, not sent;
 *  - each recipient gets their own email (no visible address list).
 */
class ScheduledReportService
{
    public const PERIODS = [
        'yesterday' => 'Yesterday', 'today' => 'Today', 'last_7_days' => 'Last 7 days', 'last_30_days' => 'Last 30 days',
        'month_to_date' => 'Month to date', 'last_month' => 'Last month',
    ];

    public function __construct(private ReportService $reports) {}

    /** @return array{date_from:string,date_to:string} */
    public static function range(string $period, Carbon $now): array
    {
        $d = fn (Carbon $c) => $c->toDateString();

        return match ($period) {
            'today' => ['date_from' => $d($now), 'date_to' => $d($now)],
            'last_7_days' => ['date_from' => $d($now->copy()->subDays(7)), 'date_to' => $d($now->copy()->subDay())],
            'last_30_days' => ['date_from' => $d($now->copy()->subDays(30)), 'date_to' => $d($now->copy()->subDay())],
            'month_to_date' => ['date_from' => $d($now->copy()->startOfMonth()), 'date_to' => $d($now)],
            'last_month' => ['date_from' => $d($now->copy()->subMonthNoOverflow()->startOfMonth()), 'date_to' => $d($now->copy()->subMonthNoOverflow()->endOfMonth())],
            default => ['date_from' => $d($now->copy()->subDay()), 'date_to' => $d($now->copy()->subDay())],
        };
    }

    /** Is this schedule due at $now? Sends once per day, at or after its send time. */
    public static function isDue(ReportSchedule $s, Carbon $now): bool
    {
        if (!$s->is_active || $now->format('H:i') < $s->send_time) {
            return false;
        }
        if ($s->last_run_at && $s->last_run_at->copy()->setTimezone($now->timezone)->isSameDay($now)) {
            return false;
        }

        return match ($s->frequency) {
            'weekly' => (int) $s->weekday === $now->isoWeekday(),
            'monthly' => (int) $s->month_day === $now->day,
            default => true,
        };
    }

    /** @return int number of schedules processed */
    public function runDue(?Carbon $now = null): int
    {
        $now ??= now();
        $n = 0;
        foreach (ReportSchedule::query()->where('is_active', true)->get() as $schedule) {
            if (self::isDue($schedule, $now)) {
                $this->send($schedule, $now);
                $n++;
            }
        }

        return $n;
    }

    public function send(ReportSchedule $s, ?Carbon $now = null): ReportRun
    {
        $now ??= now();
        $owner = $s->owner;
        try {
            if (!$owner || $owner->status->value !== 'active' || !$owner->hasPermission('reports.view')) {
                $s->update(['is_active' => false]);
                throw new \RuntimeException('The schedule owner can no longer view reports, so this schedule was paused.');
            }
            $filters = ($s->filters ?? []) + self::range($s->period, $now);
            $report = $this->reports->run($s->report_type, $owner, $filters);
            $csv = $this->reports->csvString($report);
            $file = $s->report_type.'_'.$filters['date_from'].($filters['date_from'] === $filters['date_to'] ? '' : '_to_'.$filters['date_to']).'.csv';
            $body = $this->body($s, $report, $filters);

            foreach ($s->recipients as $to) {
                Mail::raw($body, function ($m) use ($to, $s, $report, $csv, $file) {
                    $m->to($to)->subject('MetaIntel report: '.$s->name.' — '.$report['title'])->attachData($csv, $file, ['mime' => 'text/csv']);
                });
            }
            $status = count($report['rows']) === 0 ? 'empty' : 'sent';
            $run = ReportRun::query()->create(['schedule_id' => $s->id, 'status' => $status, 'row_count' => count($report['rows']), 'recipient_count' => count($s->recipients), 'created_at' => now()]);
            $s->update(['last_run_at' => now(), 'last_status' => $status, 'last_error' => null]);
            AuditService::record('report.scheduled_sent', 'reports', null, null, ['schedule' => $s->id, 'type' => $s->report_type, 'rows' => count($report['rows']), 'recipients' => count($s->recipients)]);
        } catch (Throwable $e) {
            report($e);
            $run = ReportRun::query()->create(['schedule_id' => $s->id, 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'created_at' => now()]);
            $s->update(['last_run_at' => now(), 'last_status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        return $run;
    }

    private function body(ReportSchedule $s, array $report, array $filters): string
    {
        $lines = ["MetaIntel Field Operation System", '', $s->name, $report['title'].': '.$filters['date_from'].($filters['date_from'] === $filters['date_to'] ? '' : ' to '.$filters['date_to']), ''];
        foreach ($report['summary'] as $k => $v) {
            $lines[] = ucfirst(str_replace('_', ' ', (string) $k)).': '.(is_scalar($v) || $v === null ? ($v ?? '—') : json_encode($v));
        }
        $lines[] = 'Rows: '.count($report['rows']);
        $lines[] = '';
        $lines[] = 'The full data is attached as a CSV file that opens in Excel.';
        $lines[] = 'This report is sent automatically. To stop or change it, ask the person who set it up.';

        return implode("\n", $lines);
    }
}
