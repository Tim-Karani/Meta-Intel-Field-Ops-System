<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends ResourceController
{
    protected string $model = Product::class;
    protected array $with = ['brand:id,name', 'category:id,name', 'client:id,name'];
    protected array $search = ['name', 'sku'];
    protected array $filters = ['client_id' => 'client_id', 'brand_id' => 'brand_id', 'category_id' => 'category_id', 'status' => 'status'];
    protected array $sortable = ['name', 'sku', 'unit_price', 'created_at'];
    protected string $defaultSort = 'name';

    protected function rules(?Model $model = null): array
    {
        return [
            'client_id' => ['nullable', 'uuid', new Accessible(\App\Models\Client::class)],
            'brand_id' => ['nullable', 'uuid', new Accessible(\App\Models\Brand::class)],
            'category_id' => ['nullable', 'uuid', new Accessible(\App\Models\ProductCategory::class)],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:60', Rule::unique('products', 'sku')->ignore($model?->getKey())],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'status' => ['in:active,inactive'],
        ];
    }

    protected function deleting(Model $model): void
    {
        if (DB::table('sales_items')->where('product_id', $model->getKey())->exists() || DB::table('osa_records')->where('product_id', $model->getKey())->exists()) {
            throw DomainRuleException::conflict('This product has recorded sales or OSA data. Mark it inactive instead.', 'product_in_use');
        }
    }
}
