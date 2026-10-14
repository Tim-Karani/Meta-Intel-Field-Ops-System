<?php

namespace App\Policies;

class AuditLogPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'audit.view'];
}
