<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Distributor;
use App\Models\Location;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DistributorController extends ResourceController
{
    protected string $model = Distributor::class;
    protected array $search = ['name', 'code', 'contact_name'];
    protected array $filters = ['status' => 'status'];
    protected array $sortable = ['name', 'created_at'];
    protected string $defaultSort = 'name';

    protected function rules(?Model $model = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('distributors', 'name')->ignore($model?->getKey())],
            'code' => ['nullable', 'string', 'max:40'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['in:active,inactive'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function indexQuery(Builder $query, Request $request): Builder
    {
        return $query->select('distributors.*')->addSelect(['outlets_count' => Location::query()->selectRaw('count(*)')->whereColumn('locations.distributor_id', 'distributors.id')]);
    }

    protected function deleting(Model $model): void
    {
        if (Location::query()->where('distributor_id', $model->getKey())->exists()) {
            throw DomainRuleException::conflict('Outlets are served by this distributor. Mark it inactive, or move the outlets first.', 'distributor_in_use');
        }
    }
}
