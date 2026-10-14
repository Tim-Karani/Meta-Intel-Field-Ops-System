<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Route;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RouteController extends ResourceController
{
    protected string $model = Route::class;
    protected array $with = ['territory:id,name', 'locations:id,name,latitude,longitude'];
    protected array $search = ['name', 'code'];
    protected array $filters = ['territory_id' => 'territory_id', 'status' => 'status'];
    protected array $sortable = ['name', 'created_at'];
    protected string $defaultSort = 'name';

    protected function rules(?Model $model = null): array
    {
        return [
            'territory_id' => ['nullable', 'uuid', new Accessible(\App\Models\Territory::class)],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('routes', 'code')->ignore($model?->getKey())],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['in:active,inactive'],
            'locations' => ['sometimes', 'array', 'max:300'],
            'locations.*' => ['uuid', 'distinct', new Accessible(\App\Models\Location::class)],
        ];
    }

    protected function afterCreate(Model $model, array $data, Request $request): void
    {
        $this->syncStops($model, $data);
    }

    protected function afterUpdate(Model $model, array $data, Request $request): void
    {
        $this->syncStops($model, $data);
    }

    /** Stop order is the array order. */
    private function syncStops(Model $route, array $data): void
    {
        if (array_key_exists('locations', $data)) {
            $route->locations()->sync(collect($data['locations'])->values()->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i]])->all());
        }
    }
}
