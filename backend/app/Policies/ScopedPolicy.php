<?php

namespace App\Policies;

use App\Domain\Access\AccessScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** ability = permission (RBAC) AND row inside the user's scope (resource-level). */
abstract class ScopedPolicy
{
    /** @var array{viewAny:string, create?:string, update?:string, delete?:string} */
    protected array $perm = [];

    public function viewAny(User $user): bool
    {
        return $user->hasPermission($this->perm['viewAny']);
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission($this->perm['viewAny']) && AccessScope::allows($user, $model);
    }

    public function create(User $user): bool
    {
        return isset($this->perm['create']) && $user->hasPermission($this->perm['create']);
    }

    public function update(User $user, Model $model): bool
    {
        return isset($this->perm['update']) && $user->hasPermission($this->perm['update']) && AccessScope::allows($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return isset($this->perm['delete']) && $user->hasPermission($this->perm['delete']) && AccessScope::allows($user, $model);
    }
}
