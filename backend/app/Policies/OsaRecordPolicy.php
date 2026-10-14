<?php

namespace App\Policies;

class OsaRecordPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'osa.view'];
}
