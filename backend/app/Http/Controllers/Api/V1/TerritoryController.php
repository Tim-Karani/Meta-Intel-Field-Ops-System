<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Territory;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TerritoryController extends ResourceController
{
    protected string $model = Territory::class;
    protected array $withCount = ['locations', 'routes'];
    protected array $search = ['name', 'code', 'region'];
    protected array $filters = ['status' => 'status'];
    protected array $sortable = ['name', 'created_at'];
    protected string $defaultSort = 'name';

    protected function rules(?Model $model = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('territories', 'code')->ignore($model?->getKey())],
            'region' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['in:active,inactive'],
        ];
    }
}
