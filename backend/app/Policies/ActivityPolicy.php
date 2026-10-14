<?php

namespace App\Policies;

class ActivityPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'activities.view', 'create' => 'activities.create', 'update' => 'activities.edit', 'delete' => 'activities.edit'];
}
