<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Brand;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BrandController extends ResourceController
{
    protected string $model = Brand::class;
    protected array $with = ['client:id,name'];
    protected array $search = ['name'];
    protected array $filters = ['client_id' => 'client_id'];
    protected array $sortable = ['name'];
    protected string $defaultSort = 'name';

    protected function rules(?Model $model = null): array
    {
        return [
            'client_id' => ['nullable', 'uuid', new Accessible(\App\Models\Client::class)],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
