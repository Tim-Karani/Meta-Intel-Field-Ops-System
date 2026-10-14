<?php

namespace App\Policies;

use App\Models\Issue;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class IssuePolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'issues.view', 'create' => 'issues.create'];

    public function update(User $user, Model $model): bool
    {
        /** @var Issue $issue */
        $issue = $model;
        if (!$this->view($user, $issue)) {
            return false;
        }
        return $user->hasPermission('issues.assign') || $user->hasPermission('issues.resolve')
            || ($issue->reported_by === $user->getKey() && in_array($issue->status->value, ['open', 'assigned'], true));
    }

    public function assign(User $user, Issue $issue): bool
    {
        return $user->hasPermission('issues.assign') && $this->view($user, $issue);
    }

    public function resolve(User $user, Issue $issue): bool
    {
        return $user->hasPermission('issues.resolve') && $this->view($user, $issue);
    }

    public function comment(User $user, Issue $issue): bool
    {
        return $this->view($user, $issue);
    }
}
