<?php

namespace App\Domain\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Performance score per field agent: completion of assigned outlets, days actually worked (first outlet check-in),
 * approval rate of reviewed visits, and proof compliance (check-in AND check-out photo on the visit).
 * Plain, explainable arithmetic, not a black box: every component is returned next to the score.
 */
class ScoringService
{
    /**
     * @param  list<string>  $agentIds
     * @return array<string,array{score:?float,grade:?string,components:array<string,?float>}>
     */
    public function forAgents(array $agentIds, string $from, string $to): array
    {
        if ($agentIds === []) {
            return [];
        }
        $today = now()->toDateString();

        $asg = DB::table('assignments')->whereIn('agent_id', $agentIds)->whereBetween('scheduled_date', [$from, $to])->where('scheduled_date', '<=', $today)->where('status', '!=', 'cancelled')
            ->groupBy('agent_id')->selectRaw("agent_id, count(*) as total, sum(case when status = 'completed' then 1 else 0 end) as done, count(distinct scheduled_date) as days")->get()->keyBy('agent_id');
        $att = DB::table('attendance')->whereIn('user_id', $agentIds)->whereBetween('attendance_date', [$from, $to])->whereNotNull('work_started_at')
            ->groupBy('user_id')->selectRaw('user_id, count(*) as days')->get()->keyBy('user_id');
        $rev = DB::table('visits')->whereIn('agent_id', $agentIds)->whereBetween('visit_date', [$from, $to])
            ->groupBy('agent_id')->selectRaw("agent_id, sum(case when status = 'approved' then 1 else 0 end) as ok, sum(case when status = 'rejected' then 1 else 0 end) as bad")->get()->keyBy('agent_id');
        $proof = DB::table('visits as v')->whereIn('v.agent_id', $agentIds)->whereBetween('v.visit_date', [$from, $to])->whereIn('v.status', ['completed', 'submitted', 'under_review', 'approved', 'rejected'])
            ->groupBy('v.agent_id')->selectRaw("v.agent_id, count(*) as total, sum(case when exists (select 1 from photos p where p.visit_id = v.id and p.kind = 'checkin') and exists (select 1 from photos q where q.visit_id = v.id and q.kind = 'checkout') then 1 else 0 end) as ok")->get()->keyBy('agent_id');

        $out = [];
        foreach ($agentIds as $id) {
            $a = $asg[$id] ?? null; $t = $att[$id] ?? null; $r = $rev[$id] ?? null; $p = $proof[$id] ?? null;
            $c = [
                'completion' => $a && $a->total > 0 ? round(100 * $a->done / $a->total, 1) : null,
                'attendance' => $a && $a->days > 0 ? min(100, round(100 * ($t->days ?? 0) / $a->days, 1)) : null,
                'approval' => $r && ($r->ok + $r->bad) > 0 ? round(100 * $r->ok / ($r->ok + $r->bad), 1) : null,
                'proof' => $p && $p->total > 0 ? round(100 * $p->ok / $p->total, 1) : null,
            ];
            $out[$id] = ['components' => $c] + $this->combine($c);
        }

        return $out;
    }

    /** @param array<string,?float> $c  @return array{score:?float,grade:?string} */
    public function combine(array $c): array
    {
        $w = config('field.scoring.weights');
        $sum = 0.0; $weight = 0.0;
        foreach ($c as $k => $v) {
            if ($v !== null) {
                $sum += $v * ($w[$k] ?? 0); $weight += $w[$k] ?? 0;
            }
        }
        if ($weight <= 0) {
            return ['score' => null, 'grade' => null];
        }
        $score = round($sum / $weight, 1);
        $grade = 'Needs coaching';
        foreach (config('field.scoring.grades') as $min => $label) {
            if ($score >= $min) { $grade = $label; break; }
        }

        return ['score' => $score, 'grade' => $grade];
    }
}
