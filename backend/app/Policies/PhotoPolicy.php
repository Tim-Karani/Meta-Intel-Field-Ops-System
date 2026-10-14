<?php

namespace App\Policies;

use App\Domain\Access\AccessScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PhotoPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'evidence.view', 'create' => 'evidence.upload', 'update' => 'evidence.review'];

    /** Client users view APPROVED visits' accepted photos via the portal (the scope enforces this). */
    public function view(User $user, Model $model): bool
    {
        return ($user->hasPermission('evidence.view') || $user->hasPermission('portal.view')) && AccessScope::allows($user, $model);
    }
}
