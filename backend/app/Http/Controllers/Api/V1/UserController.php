<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\SecurityEvents;
use App\Enums\Role as RoleEnum;
use App\Enums\UserStatus;
use App\Http\Resources\UserResource;
use App\Models\AgentProfile;
use App\Models\Role;
use App\Models\User;
use App\Rules\Accessible;
use App\Rules\UniqueEmail;
use App\Support\DomainRuleException;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends ResourceController
{
    protected string $model = User::class;
    protected array $with = ['roles', 'client:id,name', 'agentProfile'];
    protected array $search = ['name', 'email', 'username', 'phone'];
    protected array $filters = ['status' => 'status', 'client_id' => 'client_id'];
    protected array $sortable = ['name', 'email', 'created_at', 'last_login_at'];
    protected string $defaultSort = 'name';

    private ?string $generatedPassword = null;

    protected function rules(?Model $model = null): array
    {
        $id = $model?->getKey();
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', new UniqueEmail($id)],
            'username' => ['nullable', 'string', 'max:60', 'alpha_dash', Rule::unique('users', 'username')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['nullable', 'string', AuthController::passwordRule()],
            'role' => [Rule::requiredIf($model === null), Rule::in(array_column(RoleEnum::cases(), 'value'))],
            'client_id' => ['nullable', 'uuid', new Accessible(\App\Models\Client::class)],
            'agent_type' => ['nullable', 'string', 'max:30'],
            'employment_type' => ['nullable', Rule::in(\App\Domain\Projects\ProjectSettings::EMPLOYMENT)],
            'can_add_outlets' => ['nullable', 'boolean'],
            'agent_code' => ['nullable', 'string', 'max:40', Rule::unique('agent_profiles', 'agent_code')->ignore($id, 'user_id')],
            'supervisor_id' => ['nullable', 'uuid', Rule::exists('users', 'id')],
            'team_leader_id' => ['nullable', 'uuid', Rule::exists('users', 'id')],
            'region' => ['nullable', 'string', 'max:255'],
            'pay_type' => ['nullable', Rule::in(['daily', 'hourly', 'monthly'])],
            'pay_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    protected function indexQuery(Builder $query, Request $request): Builder
    {
        $role = $request->query('role');

        return $query->when(is_string($role) && $role !== '', fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('slug', $role)));
    }

    protected function present(Model $model): array
    {
        return (new UserResource($model))->resolve();
    }

    protected function creating(array $data, Request $request): array
    {
        $this->assertRoleRules($data['role'] ?? null, $data['client_id'] ?? null);
        $data = $this->resolveChain($data);
        if (empty($data['password'])) {
            $this->generatedPassword = $data['password'] = Str::password(14);
        }
        $data['status'] = UserStatus::Active->value;
        $data['must_change_password'] = $this->generatedPassword !== null;
        return $data;
    }

    protected function afterCreate(Model $model, array $data, Request $request): void
    {
        $this->syncRole($model, $data);
        if (in_array($data['role'] ?? null, [RoleEnum::FieldAgent->value, RoleEnum::TeamLeader->value], true)) {
            AgentProfile::query()->create([
                'user_id' => $model->getKey(), 'agent_type' => $data['agent_type'] ?? 'merchandiser', 'employment_type' => $data['employment_type'] ?? 'permanent', 'can_add_outlets' => (bool) ($data['can_add_outlets'] ?? false), 'agent_code' => $data['agent_code'] ?? null,
                'supervisor_id' => $data['supervisor_id'] ?? null, 'team_leader_id' => $data['team_leader_id'] ?? null, 'region' => $data['region'] ?? null,
                'pay_type' => $data['pay_type'] ?? 'daily', 'pay_rate' => $data['pay_rate'] ?? 0,
            ]);
        }
    }

    protected function afterUpdate(Model $model, array $data, Request $request): void
    {
        // Role/client consistency is re-checked on every update, not only when the role changes.
        $effectiveRole = $data['role'] ?? $model->roles()->value('slug');
        $this->assertRoleRules($effectiveRole, array_key_exists('client_id', $data) ? $data['client_id'] : $model->client_id);
        if (isset($data['role'])) {
            if ($model->getKey() === $request->user()->getKey() && !$model->roles()->where('slug', $data['role'])->exists()) {
                throw new DomainRuleException('You cannot change your own role.', 'self_role_change');
            }
            $this->syncRole($model, $data);
        }
        $model->unsetRelation('roles');
        if ($model->hasRole(RoleEnum::FieldAgent) || $model->hasRole(RoleEnum::TeamLeader)) {
            $data = $this->resolveChain($data, $model);
            AgentProfile::query()->updateOrCreate(['user_id' => $model->getKey()], array_filter(
                array_intersect_key($data, array_flip(['agent_type', 'employment_type', 'can_add_outlets', 'agent_code', 'supervisor_id', 'team_leader_id', 'region', 'pay_type', 'pay_rate'])), fn ($v) => $v !== null
            ));
        }
        $model->forgetPermissionCache();
    }

    /**
     * Supervisor > Team Leader > Agent. The chosen Team Leader must really be a Team Leader and the Supervisor a Supervisor; an agent
     * who has a Team Leader but no Supervisor inherits the Team Leader's Supervisor and region, so the chain is never half-filled.
     */
    private function resolveChain(array $data, ?Model $model = null): array
    {
        $isRole = fn (?string $id, string $slug) => $id && \App\Models\User::query()->whereKey($id)->whereHas('roles', fn ($r) => $r->where('slug', $slug))->exists();
        if (!empty($data['supervisor_id']) && !$isRole($data['supervisor_id'], 'supervisor')) {
            throw ValidationException::withMessages(['supervisor_id' => ['The chosen person is not a Supervisor.']]);
        }
        if (!empty($data['team_leader_id'])) {
            if (!$isRole($data['team_leader_id'], 'team_leader')) {
                throw ValidationException::withMessages(['team_leader_id' => ['The chosen person is not a Team Leader.']]);
            }
            $tl = AgentProfile::query()->find($data['team_leader_id']);
            $data['supervisor_id'] ??= $tl?->supervisor_id;
            $data['region'] ??= $tl?->region;
        }

        return $data;
    }

    public function store(Request $request): JsonResponse
    {
        $response = $this->reportingDuplicateEmail(fn () => parent::store($request));
        if ($this->generatedPassword !== null) {
            $payload = $response->getData(true);
            $payload['data']['temporary_password'] = $this->generatedPassword;   // shown once; the user must change it at first login
            $response->setData($payload);
        }
        return $response;
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->reportingDuplicateEmail(fn () => parent::update($request, $id));
    }

    /** Two requests can both pass validation for the same address; the database stops the second, and the admin sees the usual message. */
    private function reportingDuplicateEmail(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), 'users_email_unique')) {
                throw $e;
            }
            throw ValidationException::withMessages(['email' => ['The email has already been taken.']]);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        throw new DomainRuleException('Users are never deleted. Disable the account instead.', 'users_not_deletable', 405);
    }

    public function disable(Request $request, string $id): JsonResponse
    {
        $user = $this->findScoped($request, $id);
        $this->authorize('delete', $user);   // maps to users.disable
        if ($user->getKey() === $request->user()->getKey()) {
            throw new DomainRuleException('You cannot disable your own account.', 'self_disable');
        }
        $user->update(['status' => UserStatus::Disabled]);
        $user->tokens()->delete();
        SecurityEvents::log('user_disabled', $user->getKey(), 'warning', ['by' => $request->user()->getKey()]);

        return $this->ok($this->present($user->fresh($this->with)), 'User disabled.');
    }

    public function enable(Request $request, string $id): JsonResponse
    {
        $user = $this->findScoped($request, $id);
        $this->authorize('delete', $user);
        $user->forceFill(['status' => UserStatus::Active, 'failed_logins' => 0, 'locked_until' => null])->save();

        return $this->ok($this->present($user->fresh($this->with)), 'User enabled.');
    }

    public function sendResetLink(Request $request, string $id): JsonResponse
    {
        $user = $this->findScoped($request, $id);
        $this->authorize('update', $user);
        Password::sendResetLink(['email' => $user->email]);
        AuditService::record('user.reset_link_sent', 'users', $user);

        return $this->ok(null, 'Password reset link sent.');
    }

    private function syncRole(Model $user, array $data): void
    {
        if (isset($data['role'])) {
            $user->roles()->sync([Role::query()->where('slug', $data['role'])->value('id')]);
            $user->unsetRelation('roles');
        }
    }

    private function assertRoleRules(?string $role, ?string $clientId): void
    {
        if ($role === RoleEnum::ClientUser->value && !$clientId) {
            throw new DomainRuleException('A client user must be linked to a client.', 'client_required');
        }
        if ($role !== null && $role !== RoleEnum::ClientUser->value && $clientId) {
            throw new DomainRuleException('Only client users can be linked to a client.', 'client_not_allowed');
        }
    }
}
