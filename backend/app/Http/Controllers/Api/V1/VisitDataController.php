<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Domain\Visits\VisitDataService;
use App\Models\MerchandisingRecord;
use App\Models\OsaRecord;
use App\Models\Sale;
use App\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sales, OSA and merchandising captured inside a visit: /visits/{visit}/sales|osa|merchandising */
class VisitDataController extends ApiController
{
    public function __construct(private VisitDataService $data) {}

    /* ---- sales ---- */
    public function sales(Request $request, string $visit): JsonResponse
    {
        $v = $this->visit($request, $visit);
        $this->authorize('view', $v);

        return $this->ok(Sale::query()->where('visit_id', $v->id)->with('items.product:id,name,sku')->orderBy('captured_at')->get());
    }

    public function storeSale(Request $request, string $visit): JsonResponse
    {
        $v = $this->visit($request, $visit);
        $this->authorize('work', $v);
        $payload = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'payment_method' => ['nullable', Rule::in(['cash', 'mpesa', 'card', 'credit', 'other'])],
            'captured_at' => ['nullable', 'date', 'before_or_equal:+5 minutes'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'items.*.is_promo' => ['boolean'],
        ]);

        return $this->created($this->data->addSale($v, $payload), 'Sale recorded.');
    }

    /* ---- OSA ---- */
    public function osa(Request $request, string $visit): JsonResponse
    {
        $v = $this->visit($request, $visit);
        $this->authorize('view', $v);

        return $this->ok(OsaRecord::query()->where('visit_id', $v->id)->with('product:id,name,sku')->get());
    }

    public function saveOsa(Request $request, string $visit): JsonResponse
    {
        $v = $this->visit($request, $visit);
        $this->authorize('work', $v);
        $payload = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'available' => ['boolean'], 'out_of_stock' => ['boolean'],
            'shelf_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'backroom_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'facings' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'share_of_shelf' => ['nullable', 'numeric', 'between:0,100'],
            'price_on_shelf' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'display_compliant' => ['nullable', 'boolean'], 'visibility_ok' => ['nullable', 'boolean'],
            'captured_at' => ['nullable', 'date', 'before_or_equal:+5 minutes'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->ok($this->data->saveOsa($v, $payload)->load('product:id,name,sku'), 'OSA recorded.');
    }

    /* ---- merchandising ---- */
    public function merchandising(Request $request, string $visit): JsonResponse
    {
        $v = $this->visit($request, $visit);
        $this->authorize('view', $v);

        return $this->ok(MerchandisingRecord::query()->where('visit_id', $v->id)->get());
    }

    public function storeMerchandising(Request $request, string $visit): JsonResponse
    {
        $v = $this->visit($request, $visit);
        $this->authorize('work', $v);
        $payload = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'posm_present' => ['nullable', 'boolean'], 'posm_type' => ['nullable', 'string', 'max:80'],
            'shelf_position' => ['nullable', 'string', 'max:80'],
            'planogram_compliant' => ['nullable', 'boolean'], 'price_compliant' => ['nullable', 'boolean'],
            'competitor_present' => ['nullable', 'boolean'], 'competitor_notes' => ['nullable', 'string', 'max:1000'],
            'captured_at' => ['nullable', 'date', 'before_or_equal:+5 minutes'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->created($this->data->addMerchandising($v, $payload), 'Merchandising recorded.');
    }

    private function visit(Request $request, string $id): Visit
    {
        $this->assertUuid($id);

        return AccessScope::scope(Visit::query(), $request->user())->findOrFail($id);
    }
}
