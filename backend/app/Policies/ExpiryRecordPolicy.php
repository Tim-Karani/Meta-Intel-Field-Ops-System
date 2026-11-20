<?php

namespace App\Policies;

class ExpiryRecordPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'stock.view'];
}
