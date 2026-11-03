<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditService;
use App\Domain\Payroll\PayrollService;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Support\DomainRuleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Payroll runs: draft -> approved -> paid. View needs payroll.view, everything that changes a run needs payroll.manage. */
class PayrollController extends ApiController
{
    public function __construct(private PayrollService $payroll) {}

    private function present(PayrollRun $r, bool $lines = false): array
    {
        $totals = $r->lines()->selectRaw('count(*) as people, coalesce(sum(gross),0) as gross, coalesce(sum(adjustment),0) as adjustment, coalesce(sum(net),0) as net, coalesce(sum(days_worked),0) as days')->first();
        $out = ['id' => $r->id, 'name' => $r->name, 'period_start' => $r->period_start->toDateString(), 'period_end' => $r->period_end->toDateString(), 'status' => $r->status, 'notes' => $r->notes,
            'approved_at' => $r->approved_at?->toIso8601String(), 'paid_at' => $r->paid_at?->toIso8601String(),
            'totals' => ['people' => (int) $totals->people, 'days' => (int) $totals->days, 'gross' => (float) $totals->gross, 'adjustment' => (float) $totals->adjustment, 'net' => (float) $totals->net]];
        if ($lines) {
            $out['lines'] = $r->lines()->with('user:id,name')->get()->sortBy(fn ($l) => $l->user?->name)->values()->map(fn ($l) => [
                'id' => $l->id, 'user_id' => $l->user_id, 'name' => $l->user?->name, 'days_worked' => $l->days_worked, 'hours_worked' => $l->hours_worked, 'late_days' => $l->late_days,
                'outlets_visited' => $l->outlets_visited, 'pay_type' => $l->pay_type, 'pay_rate' => $l->pay_rate, 'gross' => $l->gross, 'adjustment' => $l->adjustment, 'adjustment_note' => $l->adjustment_note, 'net' => $l->net,
            ])->all();
        }

        return $out;
    }

    public function index(): JsonResponse
    {
        return $this->ok(PayrollRun::query()->orderByDesc('period_end')->limit(100)->get()->map(fn ($r) => $this->present($r))->all());
    }

    public function show(string $id): JsonResponse
    {
        $this->assertUuid($id);

        return $this->ok($this->present(PayrollRun::query()->findOrFail($id), true));
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:160'], 'period_start' => ['required', 'date_format:Y-m-d'], 'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $overlap = PayrollRun::query()->where('period_start', '<=', $d['period_end'])->where('period_end', '>=', $d['period_start'])->whereIn('status', ['approved', 'paid'])->exists();
        if ($overlap) {
            throw DomainRuleException::conflict('These dates overlap a payroll run that is already approved or paid.', 'period_overlap');
        }
        $run = PayrollRun::query()->create($d + ['status' => 'draft', 'created_by' => $request->user()->getKey()]);
        $this->payroll->calculate($run);
        AuditService::record('payroll.created', 'payroll', $run, null, ['period' => [$d['period_start'], $d['period_end']]]);

        return $this->created($this->present($run->refresh(), true), 'Payroll run created.');
    }

    public function recalculate(string $id): JsonResponse
    {
        $run = $this->draft($id);
        $this->payroll->calculate($run);
        AuditService::record('payroll.recalculated', 'payroll', $run);

        return $this->ok($this->present($run->refresh(), true), 'Recalculated from attendance.');
    }

    public function updateLine(Request $request, string $id, string $line): JsonResponse
    {
        $run = $this->draft($id);
        $this->assertUuid($line);
        $d = $request->validate(['adjustment' => ['required', 'numeric', 'between:-99999999,99999999'], 'adjustment_note' => ['nullable', 'string', 'max:255']]);
        $line = PayrollLine::query()->where('payroll_run_id', $run->id)->findOrFail($line);
        $before = ['adjustment' => $line->adjustment, 'net' => $line->net];
        $this->payroll->updateLine($line, (float) $d['adjustment'], $d['adjustment_note'] ?? null);
        AuditService::record('payroll.line_adjusted', 'payroll', $run, $before, ['adjustment' => $line->adjustment, 'net' => $line->net], ['user_id' => $line->user_id]);

        return $this->ok($this->present($run->refresh(), true), 'Adjustment saved.');
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $run = $this->draft($id);
        if ($run->lines()->count() === 0) {
            throw new DomainRuleException('There is nothing to approve: no one has attendance in this period.', 'empty_run');
        }
        $run->update(['status' => 'approved', 'approved_by' => $request->user()->getKey(), 'approved_at' => now()]);
        AuditService::record('payroll.approved', 'payroll', $run);

        return $this->ok($this->present($run->refresh(), true), 'Payroll approved.');
    }

    public function markPaid(string $id): JsonResponse
    {
        $this->assertUuid($id);
        $run = PayrollRun::query()->findOrFail($id);
        if ($run->status !== 'approved') {
            throw DomainRuleException::conflict('Only an approved run can be marked as paid.', 'not_approved');
        }
        $run->update(['status' => 'paid', 'paid_at' => now()]);
        AuditService::record('payroll.paid', 'payroll', $run);

        return $this->ok($this->present($run->refresh(), true), 'Marked as paid.');
    }

    public function destroy(string $id): JsonResponse
    {
        $run = $this->draft($id);
        AuditService::record('payroll.deleted', 'payroll', $run);
        $run->delete();

        return $this->ok(null, 'Draft deleted.');
    }

    public function export(string $id): Response
    {
        $this->assertUuid($id);
        $run = PayrollRun::query()->findOrFail($id);
        AuditService::record('payroll.exported', 'payroll', $run);

        return response($this->payroll->csv($run), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="payroll-'.$run->period_start->format('Ymd').'-'.$run->period_end->format('Ymd').'.csv"', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function draft(string $id): PayrollRun
    {
        $this->assertUuid($id);
        $run = PayrollRun::query()->findOrFail($id);
        if ($run->status !== 'draft') {
            throw DomainRuleException::conflict('Only a draft payroll run can be changed.', 'run_locked');
        }

        return $run;
    }
}
