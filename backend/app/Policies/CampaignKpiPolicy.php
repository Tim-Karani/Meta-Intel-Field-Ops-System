<?php

namespace App\Policies;

class CampaignKpiPolicy extends ScopedPolicy
{
    protected array $perm = ['viewAny' => 'campaigns.view', 'create' => 'campaigns.edit', 'update' => 'campaigns.edit', 'delete' => 'campaigns.edit'];
}
