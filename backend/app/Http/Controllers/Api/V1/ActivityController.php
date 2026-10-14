<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Activity;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ActivityController extends ResourceController
{
    protected string $model = Activity::class;
    protected array $with = ['campaign:id,name,client_id', 'form:id,name,version'];
    protected array $search = ['name', 'activity_type'];
    protected array $filters = ['campaign_id' => 'campaign_id', 'status' => 'status'];
    protected array $sortable = ['name', 'start_date', 'created_at'];
    protected string $defaultSort = '-created_at';

    protected function rules(?Model $model = null): array
    {
        $rules = [
            'campaign_id' => ['required', 'uuid', new Accessible(\App\Models\Campaign::class)],
            'form_id' => ['nullable', 'uuid', new Accessible(\App\Models\Form::class, fn ($q) => $q->where('status', 'published'))],
            'name' => ['required', 'string', 'max:255'],
            'activity_type' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:5000'],
            'target' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['in:planned,active,paused,completed'],
        ];
        if ($model) {
            unset($rules['campaign_id']);   // an activity never moves between campaigns
        }
        return $rules;
    }
}
