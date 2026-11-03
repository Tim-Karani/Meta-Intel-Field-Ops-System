<?php

namespace App\Domain\Payroll;

use App\Models\PayrollLine;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\DB;

/**
 * Turns attendance into pay: days worked (first outlet check-in), hours worked (first check-in to last check-out) and late days,
 * priced with each person's pay type (daily / hourly / monthly) and rate. HR can add an adjustment (deduction or bonus) per person.
 * Clocking in alone is never paid time; that rule lives in the attendance data this reads.
 */
class PayrollService
{
    /** (Re)builds the lines of a draft run. Adjustments already typed in are kept. */
    public function calculate(PayrollRun $run): void
    {
        DB::transaction(function () use ($run) {
            $kept = $run->lines()->get()->keyBy('user_id');
            $stats = DB::table('attendance')->whereBetween('attendance_date', [$run->period_start->toDateString(), $run->period_end->toDateString()])->whereNotNull('work_started_at')
                ->groupBy('user_id')->selectRaw("user_id, count(*) as days, coalesce(sum(worked_minutes), 0) as minutes, sum(case when status = 'late' then 1 else 0 end) as late, coalesce(sum(outlets_visited), 0) as outlets")->get()->keyBy('user_id');
            $profiles = DB::table('agent_profiles')->whereIn('user_id', $stats->keys()->all())->get()->keyBy('user_id');

            $seen = [];
            foreach ($stats as $uid => $s) {
                $p = $profiles[$uid] ?? null;
                $type = $p->pay_type ?? 'daily'; $rate = (float) ($p->pay_rate ?? 0);
                $hours = round($s->minutes / 60, 2);
                $gross = match ($type) { 'hourly' => $hours * $rate, 'monthly' => $s->days > 0 ? $rate : 0.0, default => $s->days * $rate };
                $adj = (float) ($kept[$uid]->adjustment ?? 0);
                PayrollLine::query()->updateOrCreate(['payroll_run_id' => $run->id, 'user_id' => $uid], [
                    'days_worked' => (int) $s->days, 'hours_worked' => $hours, 'late_days' => (int) $s->late, 'outlets_visited' => (int) $s->outlets,
                    'pay_type' => $type, 'pay_rate' => $rate, 'gross' => round($gross, 2), 'adjustment' => $adj, 'adjustment_note' => $kept[$uid]->adjustment_note ?? null, 'net' => round($gross + $adj, 2),
                ]);
                $seen[] = $uid;
            }
            // Someone with no attendance this time but a manual adjustment stays on the run.
            $run->lines()->whereNotIn('user_id', $seen)->where('adjustment', 0)->delete();
        });
    }

    public function updateLine(PayrollLine $line, float $adjustment, ?string $note): PayrollLine
    {
        $line->update(['adjustment' => $adjustment, 'adjustment_note' => $note, 'net' => round($line->gross + $adjustment, 2)]);

        return $line;
    }

    public function csv(PayrollRun $run): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['Name', 'Agent code', 'Days worked', 'Hours worked', 'Late days', 'Outlets visited', 'Pay type', 'Rate', 'Gross', 'Adjustment', 'Adjustment note', 'Net']);
        foreach ($run->lines()->with('user:id,name')->get()->sortBy(fn ($l) => $l->user?->name) as $l) {
            $code = DB::table('agent_profiles')->where('user_id', $l->user_id)->value('agent_code');
            fputcsv($fh, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, [$l->user?->name, $code, $l->days_worked, $l->hours_worked, $l->late_days, $l->outlets_visited, $l->pay_type, $l->pay_rate, $l->gross, $l->adjustment, $l->adjustment_note, $l->net]));
        }
        rewind($fh);

        return stream_get_contents($fh);
    }
}
