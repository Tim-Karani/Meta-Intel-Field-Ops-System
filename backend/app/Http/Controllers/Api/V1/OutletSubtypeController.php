<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Location;
use App\Models\OutletSubtype;
use App\Models\OutletType;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class OutletSubtypeController extends ResourceController
{
    protected string $model = OutletSubtype::class;
    protected array $with = ['type:id,name,slug'];
    protected array $search = ['name'];
    protected array $filters = ['outlet_type_id' => 'outlet_type_id', 'status' => 'status'];
    protected array $sortable = ['name', 'created_at'];
    protected string $defaultSort = 'name';

    protected function rules(?Model $model = null): array
    {
        $type = $model?->outlet_type_id ?? request()->input('outlet_type_id');

        return [
            'outlet_type_id' => ['required', 'uuid', new Accessible(OutletType::class)],
            'name' => ['required', 'string', 'max:120', Rule::unique('outlet_subtypes', 'name')->where('outlet_type_id', request()->input('outlet_type_id', $type))->ignore($model?->getKey())],
            'status' => ['in:active,inactive'],
        ];
    }

    protected function deleting(Model $model): void
    {
        if (Location::query()->where('outlet_subtype_id', $model->getKey())->exists()) {
            throw DomainRuleException::conflict('Outlets use this sub-type. Mark it inactive, or change those outlets first.', 'outlet_subtype_in_use');
        }
    }
}
