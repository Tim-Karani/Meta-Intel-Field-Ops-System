<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Campaign;
use App\Models\CampaignKpi;
use App\Rules\Accessible;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class CampaignKpiController extends ResourceController
{
    public const METRICS = ['visits_completed', 'visit_completion_rate', 'sales_value', 'units_sold', 'osa_rate', 'attendance_rate', 'photos_submitted', 'issues_resolved'];

    protected string $model = CampaignKpi::class;
    protected array $with = ['campaign:id,name'];
    protected array $filters = ['campaign_id' => 'campaign_id'];
    protected array $sortable = ['name', 'created_at'];
    protected string $defaultSort = 'name';

    protected function rules(?Model $model = null): array
    {
        return [
            'campaign_id' => ['required', 'uuid', new Accessible(Campaign::class)],
            'name' => ['required', 'string', 'max:255'],
            'metric' => ['required', Rule::in(self::METRICS)],
            'target' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'unit' => ['nullable', 'string', 'max:20'],
        ];
    }
}
