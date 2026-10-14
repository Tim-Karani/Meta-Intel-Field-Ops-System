<?php

namespace App\Policies;

use App\Domain\Access\AccessScope;
use App\Models\User;
use App\Models\Visit;

class VisitPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'visits.view', 'create' => 'visits.create'];

    /** Agent working on their own visit (check-in/out, data entry). */
    public function work(User $user, Visit $visit): bool
    {
        return $user->hasPermission('visits.edit') && $visit->agent_id === $user->getKey();
    }

    public function submit(User $user, Visit $visit): bool
    {
        return $user->hasPermission('visits.submit') && $visit->agent_id === $user->getKey();
    }

    /** Reviewers must be in scope and can never review their own visit. */
    public function review(User $user, Visit $visit): bool
    {
        return $this->reviewer($user, $visit, 'visits.review');
    }

    public function approve(User $user, Visit $visit): bool
    {
        return $this->reviewer($user, $visit, 'visits.approve');
    }

    public function reject(User $user, Visit $visit): bool
    {
        return $this->reviewer($user, $visit, 'visits.reject');
    }

    private function reviewer(User $user, Visit $visit, string $permission): bool
    {
        return $user->hasPermission($permission) && $visit->agent_id !== $user->getKey() && AccessScope::allows($user, $visit);
    }
}
