<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ProductCategory;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductCategoryController extends ResourceController
{
    protected string $model = ProductCategory::class;
    protected array $with = ['parent:id,name'];
    protected array $filters = ['parent_id' => 'parent_id'];
    protected array $search = ['name'];
    protected array $sortable = ['name'];
    protected string $defaultSort = 'name';

    protected function rules(?Model $model = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('product_categories', 'name')->ignore($model?->getKey())],
            // One level of sub-categories: a category with a parent cannot itself be a parent.
            'parent_id' => ['nullable', 'uuid', new Accessible(ProductCategory::class, function ($q) use ($model) {
                $q->whereNull('parent_id');
                if ($model) {
                    $q->where('id', '!=', $model->getKey());
                }
            })],
        ];
    }

    protected function deleting(Model $model): void
    {
        if (ProductCategory::query()->where('parent_id', $model->getKey())->exists()) {
            throw DomainRuleException::conflict('This category has sub-categories. Move or delete them first.', 'category_has_children');
        }
    }
}
