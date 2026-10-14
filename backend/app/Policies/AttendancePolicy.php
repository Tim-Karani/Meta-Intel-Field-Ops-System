<?php

namespace App\Policies;

class AttendancePolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'attendance.view', 'create' => 'attendance.create'];
}
