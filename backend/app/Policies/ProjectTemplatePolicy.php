<?php

namespace App\Policies;

use App\Models\ProjectTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProjectTemplatePolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'campaigns.view', 'create' => 'campaigns.edit', 'update' => 'campaigns.edit', 'delete' => 'campaigns.archive'];

    /** The five built-in templates are fixed; administrators copy one to make their own. */
    public function update(User $user, Model $model): bool
    {
        return !($model instanceof ProjectTemplate && $model->is_system) && parent::update($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return !($model instanceof ProjectTemplate && $model->is_system) && parent::delete($user, $model);
    }
}
