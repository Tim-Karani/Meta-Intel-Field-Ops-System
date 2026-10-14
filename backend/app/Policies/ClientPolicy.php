<?php

namespace App\Policies;

class ClientPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'clients.view', 'create' => 'clients.create', 'update' => 'clients.edit', 'delete' => 'clients.delete'];
}
