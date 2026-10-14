<?php

namespace App\Policies;

class MerchandisingRecordPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'merchandising.view'];
}
