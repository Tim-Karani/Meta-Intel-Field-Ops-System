<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditService;
use App\Domain\Reports\ReportService;
use App\Models\ReportExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends ApiController
{
    public const FILTERS = ['client_id', 'campaign_id', 'activity_id', 'team_id', 'supervisor_id', 'agent_id', 'territory_id', 'route_id', 'location_id'];

    public function __construct(private ReportService $reports) {}

    public function types(): JsonResponse
    {
        return $this->ok(collect(ReportService::TYPES)->map(fn ($label, $key) => ['type' => $key, 'label' => $label])->values());
    }

    public function show(Request $request, string $type): JsonResponse
    {
        return $this->ok($this->reports->run($type, $request->user(), $this->filters($request)));
    }

    /** GET /reports/{type}/export — same filters, same scope, audited. */
    public function export(Request $request, string $type): StreamedResponse
    {
        abort_unless($request->user()->hasPermission('reports.export'), 403, 'You are not allowed to export reports.');
        $filters = $this->filters($request);
        $report = $this->reports->run($type, $request->user(), $filters);

        ReportExport::query()->create([
            'user_id' => $request->user()->getKey(), 'report_type' => $type, 'format' => 'csv', 'filters' => $filters,
            'row_count' => count($report['rows']), 'ip' => $request->ip(),
        ]);
        AuditService::record('report.exported', 'reports', null, null, ['type' => $type, 'rows' => count($report['rows'])], ['filters' => $filters]);

        return $this->reports->csv($report);
    }

    /** Whitelisted, format-checked filters only; anything else is ignored. */
    private function filters(Request $request): array
    {
        $rules = ['date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from']];
        $rules['group_by'] = ['nullable', 'in:product,agent,location,campaign,date,territory'];
        foreach (self::FILTERS as $f) {
            $rules[$f] = ['nullable', 'uuid'];
        }

        return array_filter($request->validate($rules), fn ($v) => $v !== null && $v !== '');
    }
}
