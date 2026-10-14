<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Client;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClientController extends ResourceController
{
    protected string $model = Client::class;
    protected array $withCount = ['campaigns'];
    protected array $search = ['name', 'code', 'company_name', 'email'];
    protected array $filters = ['status' => 'status'];
    protected array $sortable = ['name', 'created_at', 'status'];
    protected string $defaultSort = 'name';

    protected function rules(?Model $model = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('clients', 'code')->ignore($model?->getKey())],
            'company_name' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:1000'],
            'status' => ['in:active,inactive'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function deleting(Model $model): void
    {
        if ($model->campaigns()->exists()) {
            throw DomainRuleException::conflict('This client has campaigns. Archive them instead of deleting the client.', 'client_has_campaigns');
        }
    }
}
