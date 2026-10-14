<?php

namespace App\Domain\Assignments;

use App\Domain\Audit\AuditService;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use App\Support\DomainRuleException;

class CampaignService
{
    public function transition(Campaign $campaign, CampaignStatus $to, User $actor, ?string $reason = null): Campaign
    {
        if (!$campaign->status->canMoveTo($to)) {
            throw DomainRuleException::conflict("Campaign cannot move from {$campaign->status->value} to {$to->value}.", 'invalid_transition');
        }
        if ($to === CampaignStatus::Archived && !$actor->hasPermission('campaigns.archive')) {
            throw new DomainRuleException('You are not allowed to archive campaigns.', 'forbidden', 403);
        }
        if ($to === CampaignStatus::Active && !$campaign->activities()->exists()) {
            throw new DomainRuleException('Add at least one activity before activating the campaign.', 'campaign_needs_activity');
        }
        if (in_array($to, [CampaignStatus::Paused, CampaignStatus::Archived], true) && !$reason) {
            throw new DomainRuleException('A reason is required for this change.', 'reason_required');
        }

        $from = $campaign->status;
        $campaign->update(['status' => $to, 'status_reason' => $reason]);
        AuditService::record('campaign.'.$to->value, 'campaigns', $campaign, ['status' => $from->value], ['status' => $to->value], array_filter(['reason' => $reason]));

        return $campaign->refresh();
    }
}
