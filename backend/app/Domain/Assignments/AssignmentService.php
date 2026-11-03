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
            $day = \Illuminate\Support\Carbon::parse($data['scheduled_date'])->startOfDay();
            if (($campaign->start_date && $day->lt($campaign->start_date->copy()->startOfDay())) || ($campaign->end_date && $day->gt($campaign->end_date->copy()->startOfDay()))) {
                throw new DomainRuleException('That date is outside the project dates ('.($campaign->start_date?->toFormattedDateString() ?? 'no start').' to '.($campaign->end_date?->toFormattedDateString() ?? 'no end').'). Change the project dates first, or pick a date inside them.', 'outside_campaign_dates');
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

    /**
     * A same-day assignment an agent creates for themselves when adding an outlet in the field. The client may supply
     * the id (offline-created); replays return the existing row. Skips the overlap and future-date checks that
     * apply to office planning.
     *
     * @param array<string,mixed> $data
     */
    public function createForField(array $data, User $agent): Assignment
    {
        if (!empty($data['id']) && ($existing = Assignment::query()->find($data['id']))) {
            if ($existing->agent_id !== $agent->getKey()) {
                throw new DomainRuleException('This assignment id is already in use.', 'conflict', 409);
            }
            return $existing;
        }

        $campaign = Campaign::query()->findOrFail($data['campaign_id']);
        $activity = null;
        if (!empty($data['activity_id'])) {
            $activity = Activity::query()->where('campaign_id', $campaign->id)->find($data['activity_id']);
        }
        $formId = $activity?->form_id
            ?? DB::table('campaign_forms')->join('forms', 'forms.id', '=', 'campaign_forms.form_id')
                ->where('campaign_forms.campaign_id', $campaign->id)->where('forms.status', 'published')
                ->orderByDesc('forms.version')->value('forms.id');

        $assignment = new Assignment();
        $assignment->forceFill(array_filter([
            'id' => $data['id'] ?? null,
            'campaign_id' => $campaign->id, 'activity_id' => $activity?->id, 'agent_id' => $agent->getKey(), 'location_id' => $data['location_id'],
            'supervisor_id' => DB::table('agent_profiles')->where('user_id', $agent->getKey())->value('supervisor_id'), 'form_id' => $formId,
            'scheduled_date' => $data['scheduled_date'], 'status' => AssignmentStatus::Accepted, 'accepted_at' => now(), 'created_by' => $agent->getKey(),
            'instructions' => 'Added in the field by the agent.', 'source' => 'field',
        ], fn ($v) => $v !== null))->save();

        return $assignment;
    }

    /**
     * One visit generated from a fixed journey plan. Quiet (the agent gets one summary notification per generation, not one per
     * visit), already accepted (a fixed plan needs no acceptance) and idempotent: (stop, date) is unique.
     *
     * @return Assignment|null null when that stop already has a visit on that date
     */
    public function createFromJourneyPlan(\App\Models\JourneyPlanStop $stop, \App\Models\JourneyPlan $plan, string $date): ?Assignment
    {
        if (Assignment::query()->where('journey_plan_stop_id', $stop->id)->whereDate('scheduled_date', $date)->exists()) {
            return null;
        }
        $formId = DB::table('activities')->where('id', $stop->activity_id)->value('form_id')
            ?? DB::table('campaign_forms')->join('forms', 'forms.id', '=', 'campaign_forms.form_id')
                ->where('campaign_forms.campaign_id', $plan->campaign_id)->where('forms.status', 'published')
                ->orderByDesc('forms.version')->value('forms.id');

        $a = new Assignment();
        $a->forceFill([
            'campaign_id' => $plan->campaign_id, 'activity_id' => $stop->activity_id, 'agent_id' => $plan->agent_id, 'location_id' => $stop->location_id,
            'supervisor_id' => DB::table('agent_profiles')->where('user_id', $plan->agent_id)->value('supervisor_id'), 'form_id' => $formId,
            'scheduled_date' => $date, 'start_time' => $stop->start_time, 'status' => AssignmentStatus::Accepted, 'accepted_at' => now(),
            'created_by' => $plan->created_by, 'source' => 'journey_plan', 'journey_plan_stop_id' => $stop->id,
            'instructions' => 'From your fixed journey plan.',
        ])->save();

        return $a;
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
