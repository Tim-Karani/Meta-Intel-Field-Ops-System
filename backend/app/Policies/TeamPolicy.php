<?php

namespace App\Policies;

class TeamPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'teams.view', 'create' => 'teams.manage', 'update' => 'teams.manage', 'delete' => 'teams.manage'];
}
