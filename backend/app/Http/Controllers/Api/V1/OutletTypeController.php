<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Location;
use App\Models\OutletType;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Outlet types the office defines (supermarket, kiosk, ...). The slug is made once from the name and never changes. */
class OutletTypeController extends ResourceController
{
    protected string $model = OutletType::class;
    protected array $withCount = ['subtypes'];
    protected array $search = ['name'];
    protected array $filters = ['status' => 'status'];
    protected array $sortable = ['name', 'created_at'];
    protected string $defaultSort = 'name';

    protected function rules(?Model $model = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('outlet_types', 'name')->ignore($model?->getKey())],
            'status' => ['in:active,inactive'],
        ];
    }

    protected function creating(array $data, Request $request): array
    {
        $base = Str::slug($data['name'], '_') ?: 'type';
        $slug = $base;
        for ($i = 2; OutletType::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'_'.$i;
        }

        return parent::creating($data, $request) + ['slug' => $slug];
    }

    protected function present(Model $model): array
    {
        return $model->toArray() + ['outlets_count' => Location::query()->where('type', $model->slug)->count()];
    }

    protected function deleting(Model $model): void
    {
        if (Location::query()->where('type', $model->slug)->exists()) {
            throw DomainRuleException::conflict('Outlets use this type. Mark it inactive, or change those outlets first.', 'outlet_type_in_use');
        }
    }
}
