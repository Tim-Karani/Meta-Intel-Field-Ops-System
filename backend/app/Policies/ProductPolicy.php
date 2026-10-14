<?php

namespace App\Policies;

class ProductPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'products.view', 'create' => 'products.manage', 'update' => 'products.manage', 'delete' => 'products.manage'];
}
