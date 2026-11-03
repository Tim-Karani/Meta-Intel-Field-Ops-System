<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Channel;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChannelController extends ResourceController
{
    protected string $model = Channel::class;
    protected array $search = ['name', 'code', 'channel_group'];
    protected array $filters = ['status' => 'status', 'channel_group' => 'channel_group'];
    protected array $sortable = ['name', 'channel_group', 'created_at'];
    protected string $defaultSort = 'name';

    protected function indexQuery(Builder $query, Request $request): Builder
    {
        return $query->withCount('locations');
    }

    protected function rules(?Model $model = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('channels', 'name')->ignore($model?->getKey())],
            'channel_group' => ['nullable', 'string', 'max:80'],
            'code' => ['nullable', 'string', 'max:40'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'status' => ['in:active,inactive'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function deleting(Model $model): void
    {
        if ($model->locations()->exists()) {
            throw DomainRuleException::conflict('Outlets still belong to this channel. Mark it inactive, or move the outlets first.', 'channel_in_use');
        }
    }
}
