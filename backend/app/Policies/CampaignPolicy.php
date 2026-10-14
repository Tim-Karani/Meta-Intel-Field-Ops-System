<?php

namespace App\Policies;

class CampaignPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'campaigns.view', 'create' => 'campaigns.create', 'update' => 'campaigns.edit', 'delete' => 'campaigns.archive'];
}
