<?php

namespace App\Policies;

class AppNotificationPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'notifications.view'];
}
