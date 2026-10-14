<?php

namespace App\Policies;

class UserPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'users.view', 'create' => 'users.create', 'update' => 'users.edit', 'delete' => 'users.disable'];
}
