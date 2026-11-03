<?php

namespace App\Policies;

class JourneyPlanPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'assignments.create', 'create' => 'assignments.create', 'update' => 'assignments.edit', 'delete' => 'assignments.edit'];
}
