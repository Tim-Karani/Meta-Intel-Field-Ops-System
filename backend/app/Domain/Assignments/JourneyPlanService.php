<?php

namespace App\Domain\Assignments;

use App\Domain\Audit\AuditService;
use App\Domain\Notifications\NotificationService;
use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\Campaign;
use App\Models\JourneyPlan;
use App\Models\User;
use App\Support\DomainRuleException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fixed Journey Plans: an agent's recurring outlets by weekday.
 *
 * The plan is the single source of truth. Visits are generated from it into the normal assignments table (so the mobile
 * app, reviews and reports need no special case) for a rolling window, daily by the scheduler and immediately whenever the
 * plan is edited. Removing a stop cancels that stop's future visits that nobody has started.
 */
class JourneyPlanService
{
    public const WINDOW_DAYS = 14;      // matches the mobile sync window (14 days ahead)

    public function __construct(private AssignmentService $assignments) {}

    /**
     * Replaces the plan's stops. $stops = [['location_id'=>..., 'weekdays'=>[1,3,5], 'activity_id'=>?, 'start_time'=>?], ...]
     * Stops are kept by (location, weekday) so existing generated visits keep their link; removed ones are cancelled.
     */
    public function saveStops(JourneyPlan $plan, array $stops, User $actor): JourneyPlan
    {
        return DB::transaction(function () use ($plan, $stops, $actor) {
            $campaign = Campaign::query()->findOrFail($plan->campaign_id);
            $linked = DB::table('campaign_locations')->where('campaign_id', $campaign->id)->pluck('location_id')->flip();

            $wanted = [];
            foreach (array_values($stops) as $i => $s) {
                if (!isset($linked[$s['location_id']])) {
                    throw new DomainRuleException('An outlet in the plan is not linked to this campaign.', 'location_not_in_campaign');
                }
                foreach (array_unique($s['weekdays']) as $d) {
                    $wanted["{$s['location_id']}|$d"] = ['location_id' => $s['location_id'], 'weekday' => (int) $d, 'sequence' => $i,
                        'activity_id' => $s['activity_id'] ?? null, 'start_time' => $s['start_time'] ?? null];
                }
            }

            $existing = $plan->stops()->get()->keyBy(fn ($st) => "{$st->location_id}|{$st->weekday}");
            foreach ($existing as $key => $stop) {
                if (!isset($wanted[$key])) {
                    $this->cancelFuture($stop->id, 'Removed from the journey plan');
                    $stop->delete();
                }
            }
            foreach ($wanted as $key => $row) {
                $stop = $existing[$key] ?? null;
                if ($stop) {
                    $stop->update(array_diff_key($row, ['location_id' => 1, 'weekday' => 1]));
                } else {
                    $plan->stops()->create($row);
                }
            }
            AuditService::record('journey_plan.stops_saved', 'assignments', $plan, null, ['stops' => count($wanted)], ['agent_id' => $plan->agent_id]);

            $this->generate($plan->refresh(), $actor);

            return $plan->refresh()->load('stops.location:id,name,town');
        });
    }

    /** Creates the missing visits for the next window. Returns how many were created. */
    public function generate(JourneyPlan $plan, ?User $actor = null, ?Carbon $from = null, ?Carbon $to = null): int
    {
        $campaign = Campaign::query()->find($plan->campaign_id);
        if ($plan->status !== 'active' || !$campaign || !$campaign->status->acceptsAssignments()) {
            return 0;
        }
        $from = ($from ?? today())->copy()->startOfDay();
        $to = ($to ?? today()->addDays(self::WINDOW_DAYS))->copy()->startOfDay();
        if ($plan->starts_on && $plan->starts_on->gt($from)) {
            $from = $plan->starts_on->copy();
        }
        if ($plan->ends_on && $plan->ends_on->lt($to)) {
            $to = $plan->ends_on->copy();
        }
        if ($campaign->start_date && $campaign->start_date->gt($from)) {
            $from = $campaign->start_date->copy();
        }
        if ($campaign->end_date && $campaign->end_date->lt($to)) {
            $to = $campaign->end_date->copy();
        }

        $stops = $plan->stops()->get()->groupBy('weekday');
        $made = 0;
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            foreach ($stops->get($day->isoWeekday(), collect()) as $stop) {
                if ($this->assignments->createFromJourneyPlan($stop, $plan, $day->toDateString())) {
                    $made++;
                }
            }
        }
        if ($made > 0) {
            NotificationService::notify($plan->agent_id, 'journey_plan.updated', 'Journey plan updated',
                "{$made} visit".($made === 1 ? '' : 's').' from your fixed journey plan were added to your schedule.', ['campaign_id' => $plan->campaign_id]);
        }

        return $made;
    }

    /** Scheduler entry point. */
    public function generateAll(): int
    {
        $total = 0;
        JourneyPlan::query()->where('status', 'active')->chunkById(100, function ($plans) use (&$total) {
            foreach ($plans as $plan) {
                $total += $this->generate($plan);
            }
        }, 'id');

        return $total;
    }

    /** Pausing or ending a plan stops new visits and cancels future untouched ones. */
    public function setStatus(JourneyPlan $plan, string $status): JourneyPlan
    {
        $plan->update(['status' => $status]);
        if ($status !== 'active') {
            $this->cancelFuture($plan->stops()->pluck('id')->all(), 'Journey plan '.$status);
        } else {
            $this->generate($plan);
        }

        return $plan->refresh();
    }

    /** @param string|list<string> $stopIds */
    private function cancelFuture(string|array $stopIds, string $reason): void
    {
        Assignment::query()->whereIn('journey_plan_stop_id', (array) $stopIds)
            ->whereDate('scheduled_date', '>=', today())
            ->whereIn('status', [AssignmentStatus::Pending->value, AssignmentStatus::Accepted->value])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('visits')->whereColumn('visits.assignment_id', 'assignments.id'))
            ->update(['status' => AssignmentStatus::Cancelled->value, 'cancel_reason' => $reason, 'journey_plan_stop_id' => null]);
    }
}
