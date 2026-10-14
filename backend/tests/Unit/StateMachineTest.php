<?php

namespace Tests\Unit;

use App\Enums\AssignmentStatus;
use App\Enums\CampaignStatus;
use App\Enums\IssueStatus;
use App\Enums\VisitStatus;
use PHPUnit\Framework\TestCase;

class StateMachineTest extends TestCase
{
    public function test_visit_happy_path_is_allowed_in_order_only(): void
    {
        $path = [VisitStatus::Scheduled, VisitStatus::Started, VisitStatus::Completed, VisitStatus::Submitted, VisitStatus::UnderReview, VisitStatus::Approved];
        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue($path[$i]->canMoveTo($path[$i + 1]), "{$path[$i]->value} -> {$path[$i + 1]->value}");
        }
        $this->assertFalse(VisitStatus::Scheduled->canMoveTo(VisitStatus::Submitted), 'cannot skip check-in/out');
        $this->assertFalse(VisitStatus::Started->canMoveTo(VisitStatus::Approved));
    }

    public function test_terminal_visit_states_are_locked(): void
    {
        foreach ([VisitStatus::Approved, VisitStatus::Rejected] as $s) {
            $this->assertSame([], $s->next());
            $this->assertTrue($s->isLocked());
            $this->assertFalse($s->isEditable());
        }
        $this->assertTrue(VisitStatus::Submitted->isLocked(), 'submitted visits are immutable');
        $this->assertTrue(VisitStatus::Started->isEditable());
        $this->assertTrue(VisitStatus::Completed->isEditable());
    }

    public function test_campaign_lifecycle(): void
    {
        $this->assertTrue(CampaignStatus::Draft->canMoveTo(CampaignStatus::Planned));
        $this->assertTrue(CampaignStatus::Active->canMoveTo(CampaignStatus::Paused));
        $this->assertTrue(CampaignStatus::Paused->canMoveTo(CampaignStatus::Active));
        $this->assertFalse(CampaignStatus::Draft->canMoveTo(CampaignStatus::Active), 'must be planned first');
        $this->assertFalse(CampaignStatus::Archived->canMoveTo(CampaignStatus::Active));
        $this->assertSame([], CampaignStatus::Archived->next());
    }

    public function test_only_planned_or_active_campaigns_accept_assignments(): void
    {
        foreach (CampaignStatus::cases() as $s) {
            $this->assertSame(in_array($s, [CampaignStatus::Planned, CampaignStatus::Active], true), $s->acceptsAssignments(), $s->value);
        }
    }

    public function test_assignment_rules(): void
    {
        $this->assertTrue(AssignmentStatus::Pending->canMoveTo(AssignmentStatus::Accepted));
        $this->assertFalse(AssignmentStatus::Cancelled->canMoveTo(AssignmentStatus::Pending));
        $this->assertFalse(AssignmentStatus::Completed->canMoveTo(AssignmentStatus::Cancelled));
        $this->assertTrue(AssignmentStatus::Completed->canMoveTo(AssignmentStatus::InProgress), 'reopened by a rejected visit');
        $this->assertTrue(AssignmentStatus::Overdue->isOpen());
        $this->assertFalse(AssignmentStatus::Completed->isOpen());
    }

    public function test_issue_cannot_be_reopened_once_closed(): void
    {
        $this->assertTrue(IssueStatus::Open->canMoveTo(IssueStatus::Assigned));
        $this->assertTrue(IssueStatus::Resolved->canMoveTo(IssueStatus::Closed));
        $this->assertTrue(IssueStatus::Resolved->canMoveTo(IssueStatus::InProgress), 'a resolution can be disputed');
        $this->assertSame([], IssueStatus::Closed->next());
        $this->assertFalse(IssueStatus::Open->canMoveTo(IssueStatus::Closed), 'must be resolved first');
    }
}
