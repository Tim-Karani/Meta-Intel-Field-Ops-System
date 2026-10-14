<?php

namespace App\Domain\Assignments;

use App\Domain\Audit\AuditService;
use App\Domain\Notifications\NotificationService;
use App\Enums\AssignmentStatus;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Campaign;
use App\Models\User;
use App\Support\DomainRuleException;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    /** @param array<string,mixed> $data validated payload */
    public function create(array $data, User $actor): Assignment
    {
        return DB::transaction(function () use ($data, $actor) {
            $campaign = Campaign::query()->findOrFail($data['campaign_id']);
            if (!$campaign->status->acceptsAssignments()) {
                throw new DomainRuleException('Assignments can only be created for planned or active campaigns.', 'campaign_not_open');
            }
            if (!DB::table('campaign_locations')->where('campaign_id', $campaign->id)->where('location_id', $data['location_id'])->exists()) {
                throw new DomainRuleException('The location is not linked to this campaign.', 'location_not_in_campaign');
            }
            $activity = null;
            if (!empty($data['activity_id'])) {
                $activity = Activity::query()->findOrFail($data['activity_id']);
                if ($activity->campaign_id !== $campaign->id) {
                    throw new DomainRuleException('The activity does not belong to this campaign.', 'activity_not_in_campaign');
                }
            }

            $this->assertNoOverlap($data['agent_id'], $data['scheduled_date'], $data['start_time'] ?? null, $data['end_time'] ?? null);

            $formId = $activity?->form_id
                ?? DB::table('campaign_forms')->join('forms', 'forms.id', '=', 'campaign_forms.form_id')
                    ->where('campaign_forms.campaign_id', $campaign->id)->where('forms.status', 'published')
                    ->orderByDesc('forms.version')->value('forms.id');

            $supervisorId = $data['supervisor_id'] ?? DB::table('agent_profiles')->where('user_id', $data['agent_id'])->value('supervisor_id');

            $assignment = Assignment::query()->create([
                'campaign_id' => $campaign->id, 'activity_id' => $activity?->id, 'agent_id' => $data['agent_id'], 'location_id' => $data['location_id'],
                'supervisor_id' => $supervisorId, 'form_id' => $formId, 'scheduled_date' => $data['scheduled_date'],
                'start_time' => $data['start_time'] ?? null, 'end_time' => $data['end_time'] ?? null, 'instructions' => $data['instructions'] ?? null,
                'target' => $data['target'] ?? null, 'status' => AssignmentStatus::Pending, 'created_by' => $actor->getKey(),
            ]);

            NotificationService::notify($assignment->agent_id, 'assignment.created', 'New assignment',
                'You have a new assignment for '.$assignment->scheduled_date->toFormattedDateString().'.', ['assignment_id' => $assignment->id]);

            return $assignment;
        });
    }

    public function transition(Assignment $assignment, AssignmentStatus $to, User $actor, ?string $reason = null): Assignment
    {
        if (!$assignment->status->canMoveTo($to)) {
            throw DomainRuleException::conflict("Assignment cannot move from {$assignment->status->value} to {$to->value}.", 'invalid_transition');
        }
        $from = $assignment->status;
        $attrs = ['status' => $to];
        if ($to === AssignmentStatus::Accepted) {
            $attrs['accepted_at'] = now();
        }
        if ($to === AssignmentStatus::Completed) {
            $attrs['completed_at'] = now();
        }
        if ($to === AssignmentStatus::Cancelled) {
            $attrs['cancel_reason'] = $reason;
        }
        $assignment->update($attrs);
        AuditService::record('assignment.'.$to->value, 'assignments', $assignment, ['status' => $from->value], ['status' => $to->value], array_filter(['reason' => $reason]));

        if ($to === AssignmentStatus::Cancelled && $actor->getKey() !== $assignment->agent_id) {
            NotificationService::notify($assignment->agent_id, 'assignment.cancelled', 'Assignment cancelled', $reason, ['assignment_id' => $assignment->id]);
        }

        return $assignment->refresh();
    }

    public function reassign(Assignment $assignment, string $newAgentId, User $actor): Assignment
    {
        if (!in_array($assignment->status, [AssignmentStatus::Pending, AssignmentStatus::Accepted, AssignmentStatus::Overdue], true)) {
            throw DomainRuleException::conflict('Only assignments that have not started can be reassigned.', 'not_reassignable');
        }
        $this->assertNoOverlap($newAgentId, $assignment->scheduled_date->toDateString(), $assignment->start_time, $assignment->end_time, $assignment->id);

        $old = $assignment->agent_id;
        $assignment->update([
            'agent_id' => $newAgentId, 'status' => AssignmentStatus::Pending, 'accepted_at' => null,
            'supervisor_id' => DB::table('agent_profiles')->where('user_id', $newAgentId)->value('supervisor_id') ?? $assignment->supervisor_id,
        ]);
        AuditService::record('assignment.reassigned', 'assignments', $assignment, ['agent_id' => $old], ['agent_id' => $newAgentId]);
        NotificationService::notify($old, 'assignment.removed', 'Assignment reassigned', 'An assignment was reassigned to another agent.', ['assignment_id' => $assignment->id]);
        NotificationService::notify($newAgentId, 'assignment.created', 'New assignment', 'You have a new assignment.', ['assignment_id' => $assignment->id]);

        return $assignment->refresh();
    }

    /** Nightly job; returns how many assignments were marked overdue. */
    public function markOverdue(): int
    {
        $count = 0;
        Assignment::query()
            ->whereIn('status', [AssignmentStatus::Pending->value, AssignmentStatus::Accepted->value])
            ->whereDate('scheduled_date', '<', now()->toDateString())
            ->chunkById(200, function ($rows) use (&$count) {
                foreach ($rows as $a) {
                    $a->update(['status' => AssignmentStatus::Overdue]);
                    NotificationService::notify(array_filter([$a->agent_id, $a->supervisor_id]), 'assignment.overdue', 'Assignment overdue',
                        'An assignment scheduled for '.$a->scheduled_date->toFormattedDateString().' was not completed.', ['assignment_id' => $a->id]);
                    $count++;
                }
            }, 'id');
        return $count;
    }

    private function assertNoOverlap(string $agentId, string $date, ?string $start, ?string $end, ?string $ignoreId = null): void
    {
        if (!$start || !$end) {
            return;
        }
        $clash = Assignment::query()
            ->where('agent_id', $agentId)->whereDate('scheduled_date', $date)
            ->where('status', '!=', AssignmentStatus::Cancelled->value)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->whereNotNull('start_time')->whereNotNull('end_time')
            ->where('start_time', '<', $end)->where('end_time', '>', $start)
            ->exists();
        if ($clash) {
            throw new DomainRuleException('The agent already has an assignment that overlaps this time window.', 'assignment_overlap');
        }
    }
}
