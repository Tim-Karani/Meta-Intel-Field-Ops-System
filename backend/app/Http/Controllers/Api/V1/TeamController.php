<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Team;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeamController extends ResourceController
{
    protected string $model = Team::class;
    protected array $with = ['supervisor:id,name', 'members:id,name'];
    protected array $search = ['name', 'region'];
    protected array $filters = ['status' => 'status', 'supervisor_id' => 'supervisor_id'];
    protected array $sortable = ['name', 'created_at'];
    protected string $defaultSort = 'name';

    protected function rules(?Model $model = null): array
    {
        $agent = Rule::exists('user_roles', 'user_id')->where(fn ($q) => $q->whereIn('role_id', DB::table('roles')->where('slug', 'field_agent')->select('id')));
        return [
            'name' => ['required', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'supervisor_id' => ['nullable', 'uuid', Rule::exists('users', 'id')->where('status', 'active')],
            'status' => ['in:active,inactive'],
            'members' => ['sometimes', 'array', 'max:500'],
            'members.*' => ['uuid', 'distinct', $agent],
        ];
    }

    protected function afterCreate(Model $model, array $data, Request $request): void
    {
        $this->syncMembers($model, $data);
    }

    protected function afterUpdate(Model $model, array $data, Request $request): void
    {
        $this->syncMembers($model, $data);
    }

    private function syncMembers(Model $team, array $data): void
    {
        if (array_key_exists('members', $data)) {
            $team->members()->sync($data['members'] ?? []);
        }
    }
}
