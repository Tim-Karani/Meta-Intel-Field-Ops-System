<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Domain\Reports\DashboardService;
use App\Domain\Reports\ReportService;
use App\Models\Campaign;
use App\Models\Photo;
use App\Models\Visit;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Client portal. Everything here is read-only, limited to the user's own client, to APPROVED visits
 * and ACCEPTED photos (AccessScope), and never exposes agent identities or internal review notes.
 */
class PortalController extends ApiController
{
    public function __construct(private DashboardService $dashboard, private ReportService $reports) {}

    public function summary(Request $request): JsonResponse
    {
        return $this->ok($this->dashboard->summary($request->user()));
    }

    public function campaigns(Request $request): JsonResponse
    {
        $rows = AccessScope::scope(Campaign::query(), $request->user())->whereNotIn('status', ['draft'])->orderByDesc('start_date')
            ->get(['id', 'name', 'code', 'campaign_type', 'status', 'start_date', 'end_date', 'objectives']);
        $approved = DB::table('visits')->whereIn('id', AccessScope::scope(Visit::query(), $request->user())->select('visits.id'))
            ->groupBy('campaign_id')->selectRaw('campaign_id, count(*) as n')->pluck('n', 'campaign_id');

        return $this->ok($rows->map(fn ($c) => $c->toArray() + ['approved_visits' => (int) ($approved[$c->id] ?? 0)])->all());
    }

    public function visits(Request $request): JsonResponse
    {
        $request->validate(['date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d']]);
        $q = AccessScope::scope(Visit::query(), $request->user())->with(['campaign:id,name', 'location:id,name,town,county', 'activity:id,name']);
        if (($c = $this->filterValue($request, 'campaign_id', true)) !== null) {
            $q->where('visits.campaign_id', $c);
        }
        if ($from = $request->query('date_from')) {
            $q->where('visits.visit_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $q->where('visits.visit_date', '<=', $to);
        }
        $page = $q->orderByDesc('visits.visit_date')->orderBy('visits.id')->paginate($this->perPage($request));

        return ApiResponse::paginated($page, $page->getCollection()->map(fn ($v) => $this->summaryRow($v))->all());
    }

    public function visit(Request $request, string $id): JsonResponse
    {
        $this->assertUuid($id);
        $v = AccessScope::scope(Visit::query(), $request->user())->with([
            'campaign:id,name', 'activity:id,name', 'location:id,name,address,town,county', 'responses.question:id,label,type',
            'sales.items.product:id,name', 'osaRecords.product:id,name', 'merchandisingRecords',
        ])->findOrFail($id);

        $photos = AccessScope::scope(Photo::query(), $request->user())->where('visit_id', $v->id)->get()
            ->map(fn ($p) => ['id' => $p->id, 'kind' => $p->kind, 'captured_at' => $p->captured_at?->toIso8601String(), 'url' => "/api/v1/photos/{$p->id}/file"]);

        return $this->ok($this->summaryRow($v) + [
            'answers' => $v->responses->map(fn ($r) => ['question' => $r->question?->label, 'value' => $r->value])->values(),
            'sales' => $v->sales->map(fn ($s) => ['captured_at' => $s->captured_at?->toIso8601String(), 'total_value' => $s->total_value,
                'items' => $s->items->map(fn ($i) => ['product' => $i->product?->name, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price, 'line_total' => $i->line_total])]),
            'osa' => $v->osaRecords->map(fn ($o) => ['product' => $o->product?->name, 'available' => $o->available, 'out_of_stock' => $o->out_of_stock, 'facings' => $o->facings, 'share_of_shelf' => $o->share_of_shelf, 'price_on_shelf' => $o->price_on_shelf]),
            'merchandising' => $v->merchandisingRecords->map->only(['posm_present', 'posm_type', 'shelf_position', 'planogram_compliant', 'price_compliant', 'competitor_present']),
            'photos' => $photos,
        ]);
    }

    public function report(Request $request, string $type): JsonResponse
    {
        return $this->ok($this->clientReport($request, $type)[0]);
    }

    /** GET /portal/reports/{type}/export — the same approved-only report as CSV; audited like every export. */
    public function exportReport(Request $request, string $type): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        [$report, $filters] = $this->clientReport($request, $type);
        \App\Models\ReportExport::query()->create([
            'user_id' => $request->user()->getKey(), 'report_type' => $type, 'format' => 'csv', 'filters' => $filters,
            'row_count' => count($report['rows']), 'ip' => $request->ip(),
        ]);
        \App\Domain\Audit\AuditService::record('report.exported', 'reports', null, null, ['type' => $type, 'rows' => count($report['rows']), 'portal' => true], ['filters' => $filters]);

        return $this->reports->csv($report);
    }

    /** @return array{0:array,1:array} report + the filters that produced it */
    private function clientReport(Request $request, string $type): array
    {
        $filters = array_filter($request->validate(['date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'], 'campaign_id' => ['nullable', 'uuid']]));
        // Only reports that make sense without internal workforce data are offered to clients.
        abort_unless(in_array($type, ['campaign_performance', 'sales', 'osa', 'merchandising', 'location_performance', 'visit_completion', 'activity_performance'], true), 404);
        $report = $this->reports->run($type, $request->user(), $filters, true);
        $report['columns'] = array_values(array_filter($report['columns'], fn ($c) => !in_array($c['key'], ['agent', 'agent_name', 'supervisor'], true)));

        return [$report, $filters];
    }

    private function summaryRow(Visit $v): array
    {
        return [
            'id' => $v->id, 'visit_date' => $v->visit_date?->toDateString(), 'campaign' => $v->campaign?->only(['id', 'name']),
            'activity' => $v->activity?->only(['id', 'name']), 'location' => $v->location?->only(['id', 'name', 'town', 'county']),
        ];
    }
}
