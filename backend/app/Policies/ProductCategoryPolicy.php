<?php

namespace App\Policies;

class ProductCategoryPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'products.view', 'create' => 'products.manage', 'update' => 'products.manage', 'delete' => 'products.manage'];
}
