<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;

class AssignmentPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'assignments.view', 'create' => 'assignments.create', 'update' => 'assignments.edit', 'delete' => 'assignments.edit'];

    /** Only the assigned agent can accept their own assignment. */
    public function respond(User $user, Assignment $assignment): bool
    {
        return $user->hasPermission('assignments.respond') && $assignment->agent_id === $user->getKey();
    }
}
