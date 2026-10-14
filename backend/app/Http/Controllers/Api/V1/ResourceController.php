<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Generic, secure CRUD:
 *  - every query starts from AccessScope (a hidden row behaves exactly like a missing row: 404);
 *  - every action is authorised by the model's Policy (RBAC permission + scope);
 *  - input is validated against rules() (foreign keys use the Accessible rule);
 *  - mass-assignment is limited to validated keys; sorting/filtering use allow-lists.
 */
abstract class ResourceController extends ApiController
{
    /** @var class-string<Model> */
    protected string $model;
    protected array $with = [];
    protected array $withCount = [];
    protected array $search = [];
    /** request key => column */
    protected array $filters = [];
    protected array $sortable = ['created_at'];
    protected string $defaultSort = '-created_at';

    abstract protected function rules(?Model $model = null): array;

    protected function present(Model $model): array
    {
        return $model->toArray();
    }

    protected function creating(array $data, Request $request): array
    {
        if (in_array('created_by', (new $this->model)->getFillable(), true)) {
            $data['created_by'] = $request->user()->getKey();
        }
        return $data;
    }

    protected function afterCreate(Model $model, array $data, Request $request): void {}

    protected function afterUpdate(Model $model, array $data, Request $request): void {}

    protected function deleting(Model $model): void {}

    /** Extra constraints on the list query (beyond scope). */
    protected function indexQuery(Builder $query, Request $request): Builder
    {
        return $query;
    }

    protected function scoped(Request $request): Builder
    {
        return AccessScope::scope(($this->model)::query(), $request->user());
    }

    protected function findScoped(Request $request, string $id): Model
    {
        $this->assertUuid($id);
        return $this->scoped($request)->with($this->with)->withCount($this->withCount)->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', $this->model);

        $query = $this->indexQuery($this->scoped($request)->with($this->with)->withCount($this->withCount), $request);
        $model = $query->getModel();

        if (($term = $this->searchTerm($request)) !== '' && $this->search) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $grammar = $query->getQuery()->getGrammar();
            $query->where(function (Builder $w) use ($like, $grammar, $model) {
                foreach ($this->search as $col) {
                    $w->orWhereRaw('CAST('.$grammar->wrap($model->qualifyColumn($col)).' AS TEXT) ILIKE ?', [$like]);
                }
            });
        }

        foreach ($this->filters as $key => $column) {
            $value = $this->filterValue($request, $key, str_ends_with($column, '_id'));
            if ($value !== null) {
                $query->where($model->qualifyColumn($column), $value);
            }
        }

        $sort = is_string($request->query('sort')) ? $request->query('sort') : $this->defaultSort;
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $col = ltrim($sort, '-');
        if (!in_array($col, $this->sortable, true)) {
            [$col, $dir] = [ltrim($this->defaultSort, '-'), str_starts_with($this->defaultSort, '-') ? 'desc' : 'asc'];
        }
        $query->orderBy($model->qualifyColumn($col), $dir)->orderBy($model->qualifyColumn($model->getKeyName()));

        $page = $query->paginate($this->perPage($request));

        return ApiResponse::paginated($page, $page->getCollection()->map(fn ($m) => $this->present($m))->all());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $model = $this->findScoped($request, $id);
        $this->authorize('view', $model);

        return $this->ok($this->present($model));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', $this->model);
        $data = $this->creating($request->validate($this->rules()), $request);

        $model = DB::transaction(function () use ($data, $request) {
            $model = new $this->model;
            $model->forceFill(array_intersect_key($data, array_flip($model->getFillable())))->save();
            $this->afterCreate($model, $data, $request);
            return $model;
        });

        return ApiResponse::created($this->present($model->fresh($this->with)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $model = $this->findScoped($request, $id);
        $this->authorize('update', $model);

        $rules = collect($this->rules($model))->map(fn ($r) => array_merge(['sometimes'], (array) $r))->all();
        $data = $request->validate($rules);

        DB::transaction(function () use ($model, $data, $request) {
            $model->forceFill(array_intersect_key($data, array_flip($model->getFillable())))->save();
            $this->afterUpdate($model, $data, $request);
        });

        return $this->ok($this->present($model->fresh($this->with)), 'Updated successfully.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $model = $this->findScoped($request, $id);
        $this->authorize('delete', $model);

        DB::transaction(function () use ($model) {
            $this->deleting($model);
            $model->delete();
        });

        return $this->ok(null, 'Deleted successfully.');
    }
}
