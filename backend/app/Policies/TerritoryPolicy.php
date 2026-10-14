<?php

namespace App\Policies;

class TerritoryPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'territories.view', 'create' => 'territories.manage', 'update' => 'territories.manage', 'delete' => 'territories.manage'];
}
