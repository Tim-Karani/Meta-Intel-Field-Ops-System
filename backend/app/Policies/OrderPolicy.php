<?php

namespace App\Policies;

class OrderPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'sales.view'];
}
