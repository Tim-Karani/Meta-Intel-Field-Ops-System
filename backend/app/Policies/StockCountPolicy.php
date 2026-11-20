<?php

namespace App\Policies;

class StockCountPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'stock.view'];
}
