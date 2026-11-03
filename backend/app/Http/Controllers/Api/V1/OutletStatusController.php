<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Location;
use App\Models\OutletStatus;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Outlet statuses the office defines. "counts_as_active" decides whether outlets in that status can be planned and
 * visited; exactly one status is the default for new outlets.
 */
class OutletStatusController extends ResourceController
{
    protected string $model = OutletStatus::class;
    protected array $search = ['name'];
    protected array $sortable = ['name', 'created_at'];
    protected string $defaultSort = 'created_at';

    protected function rules(?Model $model = null): array
    {
        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('outlet_statuses', 'name')->ignore($model?->getKey())],
            'tone' => ['in:ok,warn,info,danger,neutral'],
            'counts_as_active' => ['boolean'],
            'is_default' => ['boolean'],
        ];
    }

    protected function present(Model $model): array
    {
        return $model->toArray() + ['outlets_count' => Location::query()->where('outlet_status_id', $model->getKey())->count()];
    }

    protected function afterCreate(Model $model, array $data, Request $request): void
    {
        $this->settle($model);
    }

    protected function afterUpdate(Model $model, array $data, Request $request): void
    {
        $this->settle($model);
        // Outlets follow the status: their plain active/inactive flag is kept in step.
        Location::query()->where('outlet_status_id', $model->getKey())->update(['status' => $model->counts_as_active ? 'active' : 'inactive']);
    }

    /** One default only, and it must be a status that counts as active. */
    private function settle(OutletStatus $model): void
    {
        if ($model->is_default && !$model->counts_as_active) {
            throw DomainRuleException::conflict('The default status for new outlets must count as active.', 'default_must_be_active');
        }
        if ($model->is_default) {
            OutletStatus::query()->where('id', '!=', $model->getKey())->update(['is_default' => false]);
        } elseif (!OutletStatus::query()->where('is_default', true)->exists()) {
            throw DomainRuleException::conflict('Keep one default status for new outlets.', 'default_required');
        }
    }

    protected function deleting(Model $model): void
    {
        if ($model->is_default) {
            throw DomainRuleException::conflict('This is the default status. Make another status the default first.', 'default_status');
        }
        if (Location::query()->where('outlet_status_id', $model->getKey())->exists()) {
            throw DomainRuleException::conflict('Outlets are in this status. Move them to another status first.', 'outlet_status_in_use');
        }
    }
}
