<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\GeoArea;
use App\Models\Location;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Geography in four fixed levels: country > region > locality > area. Each area sits under exactly one parent one level up,
 * so reports can roll outlets up cleanly. Outlets point at the most specific area they know.
 */
class GeoAreaController extends ResourceController
{
    public const LEVELS = ['country', 'region', 'locality', 'area'];

    protected string $model = GeoArea::class;
    protected array $with = ['parent:id,name,level'];
    protected array $search = ['name', 'code'];
    protected array $filters = ['level' => 'level', 'parent_id' => 'parent_id', 'status' => 'status'];
    protected array $sortable = ['name', 'level', 'created_at'];
    protected string $defaultSort = 'name';

    protected function rules(?Model $model = null): array
    {
        return [
            'level' => ['required', 'in:'.implode(',', self::LEVELS)],
            'parent_id' => ['nullable', 'uuid', 'exists:geo_areas,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:40'],
            'status' => ['in:active,inactive'],
        ];
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', $this->model);
        $this->check($request->input('level'), $request->input('parent_id'), $request->input('name'), null);

        return parent::store($request);
    }

    public function update(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $model = $this->findScoped($request, $id);
        $this->authorize('update', $model);
        $this->check($request->input('level', $model->level), $request->input('parent_id', $model->parent_id), $request->input('name', $model->name), $model);
        if ($request->has('level') && $request->input('level') !== $model->level && (GeoArea::query()->where('parent_id', $model->id)->exists() || Location::query()->where('geo_area_id', $model->id)->exists())) {
            throw DomainRuleException::conflict('The level cannot change while this place has sub-places or outlets.', 'geo_level_locked');
        }

        return parent::update($request, $id);
    }

    private function check(?string $level, ?string $parentId, ?string $name, ?Model $self): void
    {
        $i = array_search($level, self::LEVELS, true);
        if ($i === false) {
            return;   // the validator reports it
        }
        if ($i === 0 && $parentId) {
            throw \Illuminate\Validation\ValidationException::withMessages(['parent_id' => ['A country has no parent.']]);
        }
        if ($i > 0) {
            $parent = $parentId ? GeoArea::query()->find($parentId) : null;
            if (!$parent || $parent->level !== self::LEVELS[$i - 1]) {
                throw \Illuminate\Validation\ValidationException::withMessages(['parent_id' => ['A '.$level.' belongs under a '.self::LEVELS[$i - 1].'.']]);
            }
        }
        $dup = GeoArea::query()->where('level', $level)->where('parent_id', $parentId)->whereRaw('lower(name) = ?', [mb_strtolower((string) $name)])
            ->when($self, fn ($q) => $q->where('id', '!=', $self->getKey()))->exists();
        if ($dup) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => ['This place already exists here.']]);
        }
    }

    protected function deleting(Model $model): void
    {
        if (GeoArea::query()->where('parent_id', $model->getKey())->exists()) {
            throw DomainRuleException::conflict('This place has sub-places. Remove or move them first.', 'geo_has_children');
        }
        if (Location::query()->where('geo_area_id', $model->getKey())->exists()) {
            throw DomainRuleException::conflict('Outlets are located here. Move them first, or mark this place inactive.', 'geo_in_use');
        }
    }
}
