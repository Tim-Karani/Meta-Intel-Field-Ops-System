<?php

namespace App\Policies;

class FormPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'forms.view', 'create' => 'forms.manage', 'update' => 'forms.manage', 'delete' => 'forms.manage'];
}
