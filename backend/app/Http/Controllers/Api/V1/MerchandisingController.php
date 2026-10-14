<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MerchandisingRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Read-only listing; records are created through the visit workflow. */
class MerchandisingController extends ResourceController
{
    use \App\Http\Controllers\Api\V1\Concerns\FiltersByVisit;

    protected string $model = MerchandisingRecord::class;
    protected array $with = ['product:id,name,sku', 'visit:id,visit_date,location_id,agent_id', 'visit.location:id,name', 'visit.agent:id,name'];
    protected array $filters = ['visit_id' => 'visit_id'];
    protected array $sortable = ['captured_at'];
    protected string $defaultSort = '-captured_at';

    protected function rules(?Model $model = null): array
    {
        return [];
    }
}
