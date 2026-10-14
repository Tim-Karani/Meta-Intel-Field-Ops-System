<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Read-only listing; records are created through the visit workflow. */
class SaleController extends ResourceController
{
    use \App\Http\Controllers\Api\V1\Concerns\FiltersByVisit;

    protected string $model = Sale::class;
    protected array $with = ['items.product:id,name,sku', 'visit:id,visit_date,campaign_id,location_id,agent_id', 'visit.location:id,name', 'visit.agent:id,name'];
    protected array $filters = ['visit_id' => 'visit_id'];
    protected array $sortable = ['captured_at', 'total_value'];
    protected string $defaultSort = '-captured_at';

    protected function rules(?Model $model = null): array
    {
        return [];
    }
}
