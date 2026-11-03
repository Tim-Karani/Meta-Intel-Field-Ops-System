<?php

namespace App\Policies;

class OutletStatusPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'locations.view', 'create' => 'locations.manage', 'update' => 'locations.manage', 'delete' => 'locations.manage'];
}
