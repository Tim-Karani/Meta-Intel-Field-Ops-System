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
    protected array $search = ['name'];
    protected array $sortable = ['name'];
    protected string $defaultSort = 'name';

    protected function rules(?Model $model = null): array
    {
        return ['name' => ['required', 'string', 'max:120', Rule::unique('product_categories', 'name')->ignore($model?->getKey())]];
    }
}
