<?php

namespace App\Policies;

class LocationPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'locations.view', 'create' => 'locations.manage', 'update' => 'locations.manage', 'delete' => 'locations.manage'];
}
