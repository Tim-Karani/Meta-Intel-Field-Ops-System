<?php

namespace App\Policies;

class SalePolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'sales.view'];
}
