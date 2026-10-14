<?php

namespace App\Domain\Visits;

use App\Domain\Audit\AuditService;
use App\Domain\Forms\FormResponseValidator;
use App\Domain\Notifications\NotificationService;
use App\Enums\AssignmentStatus;
use App\Enums\GpsOutcome;
use App\Enums\VisitStatus;
use App\Models\Assignment;
use App\Models\GpsRule;
use App\Models\Photo;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitCheckin;
use App\Models\VisitCheckout;
use App\Models\VisitEvent;
use App\Models\VisitResponse;
use App\Support\DomainRuleException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The visit workflow. Every state change goes through here so the rules live in one place:
 * scheduled -> started (check-in) -> completed (check-out) -> submitted -> under_review -> approved | rejected.
 * The backend, never the client, decides transitions.
 */
class VisitService
{
    public function __construct(private FormResponseValidator $forms) {}

    /** Create (or return, when the same client_uuid was already received) a scheduled visit. */
    public function start(User $agent, Assignment $assignment, ?string $clientUuid = null, ?string $deviceId = null): Visit
    {
        return DB::transaction(function () use ($agent, $assignment, $clientUuid, $deviceId) {
            if ($clientUuid) {
                $existing = Visit::query()->where('agent_id', $agent->getKey())->where('client_uuid', $clientUuid)->first();
                if ($existing) {
                    return $existing;   // idempotent replay
                }
            }

            $assignment = Assignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();

            if ($assignment->agent_id !== $agent->getKey()) {
                throw new DomainRuleException('This assignment belongs to another agent.', 'forbidden', 403);
            }
            if (!$assignment->status->isOpen()) {
                throw DomainRuleException::conflict("A visit cannot be started for a {$assignment->status->value} assignment.", 'assignment_not_open');
            }
            $active = Visit::query()->where('assignment_id', $assignment->id)
                ->whereIn('status', [VisitStatus::Scheduled->value, VisitStatus::Started->value, VisitStatus::Completed->value])->first();
            if ($active) {
                throw DomainRuleException::conflict('A visit for this assignment is already in progress.', 'visit_in_progress', ['visit_id' => $active->id]);
            }

            $visit = Visit::query()->create([
                'client_uuid' => $clientUuid, 'assignment_id' => $assignment->id, 'campaign_id' => $assignment->campaign_id,
                'activity_id' => $assignment->activity_id, 'agent_id' => $agent->getKey(), 'location_id' => $assignment->location_id,
                'form_id' => $assignment->form_id, 'status' => VisitStatus::Scheduled, 'visit_date' => $assignment->scheduled_date, 'device_id' => $deviceId,
            ]);
            $this->event($visit, 'created', null, VisitStatus::Scheduled, $agent);

            return $visit;
        });
    }

    /** GPS check-in. A rejected attempt is recorded (outside the transaction) and surfaced as a 422. */
    public function checkIn(Visit $visit, User $agent, array $gps, bool $offlineReplay = false): Visit
    {
        $visit->refresh();
        $this->assertStatus($visit, VisitStatus::Scheduled, 'check in');

        $evaluation = $this->evaluate($visit, $agent, $gps, true, $offlineReplay);

        if ($evaluation->outcome === GpsOutcome::Rejected) {
            $this->event($visit, 'checkin_rejected', $visit->status, $visit->status, $agent, $evaluation->summary(), [
                'gps' => $evaluation->toArray(), 'submitted' => ['lat' => $gps['latitude'], 'lng' => $gps['longitude'], 'accuracy_m' => $gps['accuracy_m'] ?? null],
            ]);
            AuditService::record('visit.checkin_rejected', 'visits', $visit, null, ['outcome' => 'rejected'], ['distance_m' => $evaluation->distanceM]);
            throw new DomainRuleException('Check-in rejected: '.$evaluation->summary(), 'gps_rejected', 422, ['gps' => $evaluation->toArray()]);
        }

        return DB::transaction(function () use ($visit, $agent, $gps, $evaluation) {
            $visit = Visit::query()->whereKey($visit->getKey())->lockForUpdate()->firstOrFail();
            $this->assertStatus($visit, VisitStatus::Scheduled, 'check in');

            VisitCheckin::query()->create($this->gpsRow($visit, $gps, $evaluation) + [
                'provider' => $gps['provider'] ?? null, 'device_id' => $gps['device_id'] ?? null,
                'network_type' => $gps['network_type'] ?? null, 'app_version' => $gps['app_version'] ?? null,
            ]);

            $visit->update(['started_at' => now(), 'gps_outcome' => $evaluation->outcome, 'device_id' => $gps['device_id'] ?? $visit->device_id]);
            $this->move($visit, VisitStatus::Started, $agent, 'checked_in', $evaluation->outcome === GpsOutcome::Valid ? null : $evaluation->summary(), ['gps' => $evaluation->toArray()]);

            $assignment = $visit->assignment;
            if ($assignment->status !== AssignmentStatus::InProgress && $assignment->status->canMoveTo(AssignmentStatus::InProgress)) {
                $assignment->update(['status' => AssignmentStatus::InProgress]);
            }
            if ($evaluation->outcome === GpsOutcome::Suspicious) {
                NotificationService::notify(NotificationService::visitReviewers($visit), 'visit.gps_suspicious', 'Suspicious GPS check-in', $evaluation->summary(), ['visit_id' => $visit->id]);
            }

            return $visit->refresh();
        });
    }

    public function checkOut(Visit $visit, User $agent, array $gps): Visit
    {
        return DB::transaction(function () use ($visit, $agent, $gps) {
            $visit = Visit::query()->whereKey($visit->getKey())->lockForUpdate()->firstOrFail();
            $this->assertStatus($visit, VisitStatus::Started, 'check out');

            // Check-out is recorded as evidence but never blocks the agent from leaving the store.
            $evaluation = $this->evaluate($visit, $agent, $gps, false, false);
            VisitCheckout::query()->create($this->gpsRow($visit, $gps, $evaluation));

            $visit->update(['completed_at' => now()]);
            $this->move($visit, VisitStatus::Completed, $agent, 'checked_out', null, ['gps' => $evaluation->toArray()]);

            return $visit->refresh();
        });
    }

    /** Validate and upsert form answers while the visit is still editable. */
    public function saveResponses(Visit $visit, array $answers): Visit
    {
        $this->assertEditable($visit);
        if (!$visit->form_id) {
            throw new DomainRuleException('This visit has no form.', 'no_form');
        }

        $result = $this->forms->validate($visit->form, $answers, $visit->campaign_id, false);
        if ($result['errors']) {
            throw ValidationException::withMessages(collect($result['errors'])->mapWithKeys(fn ($m, $q) => ["answers.$q" => [$m]])->all());
        }

        DB::transaction(function () use ($visit, $result) {
            foreach ($result['normalized'] as $qid => $value) {
                VisitResponse::query()->updateOrCreate(['visit_id' => $visit->id, 'question_id' => $qid], ['value' => $value]);
            }
        });

        return $visit->refresh();
    }

    public function submit(Visit $visit, User $agent): Visit
    {
        return DB::transaction(function () use ($visit, $agent) {
            $visit = Visit::query()->whereKey($visit->getKey())->lockForUpdate()->firstOrFail();
            $this->assertStatus($visit, VisitStatus::Completed, 'submit');
            $this->assertSubmittable($visit);

            $visit->update(['submitted_at' => now()]);
            $this->move($visit, VisitStatus::Submitted, $agent, 'submitted');

            $assignment = $visit->assignment;
            if ($assignment->status->canMoveTo(AssignmentStatus::Completed)) {
                $assignment->update(['status' => AssignmentStatus::Completed, 'completed_at' => now()]);
            }
            NotificationService::notify(NotificationService::visitReviewers($visit), 'visit.submitted', 'Visit submitted for review', $agent->name.' submitted a visit.', ['visit_id' => $visit->id]);

            return $visit->refresh();
        });
    }

    public function startReview(Visit $visit, User $reviewer): Visit
    {
        return DB::transaction(function () use ($visit, $reviewer) {
            $visit = Visit::query()->whereKey($visit->getKey())->lockForUpdate()->firstOrFail();
            $this->move($visit, VisitStatus::UnderReview, $reviewer, 'review_started');
            $visit->update(['reviewed_by' => $reviewer->getKey()]);
            return $visit->refresh();
        });
    }

    public function approve(Visit $visit, User $reviewer, ?string $note = null): Visit
    {
        return DB::transaction(function () use ($visit, $reviewer, $note) {
            $visit = Visit::query()->whereKey($visit->getKey())->lockForUpdate()->firstOrFail();
            $this->move($visit, VisitStatus::Approved, $reviewer, 'approved', $note);
            $visit->update(['reviewed_by' => $reviewer->getKey(), 'reviewed_at' => now(), 'review_note' => $note]);
            NotificationService::notify($visit->agent_id, 'visit.approved', 'Visit approved', $note, ['visit_id' => $visit->id]);
            return $visit->refresh();
        });
    }

    public function reject(Visit $visit, User $reviewer, string $note): Visit
    {
        if (trim($note) === '') {
            throw new DomainRuleException('A reason is required to reject a visit.', 'reason_required');
        }

        return DB::transaction(function () use ($visit, $reviewer, $note) {
            $visit = Visit::query()->whereKey($visit->getKey())->lockForUpdate()->firstOrFail();
            $this->move($visit, VisitStatus::Rejected, $reviewer, 'rejected', $note);
            $visit->update(['reviewed_by' => $reviewer->getKey(), 'reviewed_at' => now(), 'review_note' => $note]);

            // Re-open the assignment so the agent can perform a corrected visit.
            $assignment = $visit->assignment;
            if ($assignment->status->canMoveTo(AssignmentStatus::InProgress)) {
                $assignment->update(['status' => AssignmentStatus::InProgress, 'completed_at' => null]);
            }
            NotificationService::notify($visit->agent_id, 'visit.rejected', 'Visit rejected', $note, ['visit_id' => $visit->id]);
            return $visit->refresh();
        });
    }

    /* ---------------------------------------------------------------- internals */

    public function assertEditable(Visit $visit): void
    {
        if (!$visit->status->isEditable()) {
            throw DomainRuleException::conflict('This visit can no longer be edited.', 'visit_locked');
        }
    }

    private function assertStatus(Visit $visit, VisitStatus $expected, string $action): void
    {
        if ($visit->status !== $expected) {
            throw DomainRuleException::conflict("Cannot {$action}: the visit is {$visit->status->value}.", 'invalid_state');
        }
    }

    private function assertSubmittable(Visit $visit): void
    {
        $errors = [];
        if ($visit->form_id) {
            $answers = $visit->responses()->pluck('value', 'question_id')->all();
            $result = $this->forms->validate($visit->form, $answers, $visit->campaign_id, true);
            foreach ($result['errors'] as $qid => $msg) {
                $errors["answers.$qid"] = [$msg];
            }

            // Required evidence: photo/signature questions that are required, or flagged evidence_required.
            foreach ($visit->form->questions as $q) {
                if (!($result['visible'][$q->id] ?? true)) {
                    continue;
                }
                if (!($q->evidence_required || ($q->type->isEvidence() && $q->required))) {
                    continue;
                }
                $min = (int) ($q->settings['min_photos'] ?? 1);
                $have = Photo::query()->where('visit_id', $visit->id)->where('question_id', $q->id)->where('review_status', '!=', 'rejected')->count();
                if ($have < $min) {
                    $errors["evidence.{$q->id}"] = ["At least {$min} photo(s) required for \"{$q->label}\"."];
                }
            }
        }
        if (!$visit->checkin()->exists()) {
            $errors['checkin'] = ['A GPS check-in is required.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function move(Visit $visit, VisitStatus $to, User $actor, string $type, ?string $note = null, array $meta = []): void
    {
        if (!$visit->status->canMoveTo($to)) {
            throw DomainRuleException::conflict("Visit cannot move from {$visit->status->value} to {$to->value}.", 'invalid_transition');
        }
        $from = $visit->status;
        $visit->update(['status' => $to]);
        $this->event($visit, $type, $from, $to, $actor, $note, $meta);
        AuditService::record('visit.'.$type, 'visits', $visit, ['status' => $from->value], ['status' => $to->value], array_filter(['note' => $note]));
    }

    private function event(Visit $visit, string $type, ?VisitStatus $from, ?VisitStatus $to, ?User $actor, ?string $note = null, array $meta = []): void
    {
        VisitEvent::query()->create([
            'visit_id' => $visit->id, 'type' => $type, 'from_status' => $from?->value, 'to_status' => $to?->value,
            'actor_id' => $actor?->getKey(), 'note' => $note, 'meta' => $meta ?: null, 'created_at' => now(),
        ]);
    }

    private function gpsRow(Visit $visit, array $gps, GpsEvaluation $e): array
    {
        return [
            'visit_id' => $visit->id, 'latitude' => $gps['latitude'], 'longitude' => $gps['longitude'], 'accuracy_m' => $gps['accuracy_m'] ?? null,
            'device_ts' => isset($gps['device_ts']) ? Carbon::parse($gps['device_ts']) : null, 'server_ts' => now(),
            'distance_m' => $e->distanceM, 'outcome' => $e->outcome, 'reasons' => $e->reasons, 'mock_flag' => $gps['mock_flag'] ?? null,
        ];
    }

    private function evaluate(Visit $visit, User $agent, array $gps, bool $isCheckin, bool $offlineReplay): GpsEvaluation
    {
        $location = $visit->location;
        $assignment = $visit->assignment;

        $overrides = array_replace_recursive(
            GpsRule::query()->whereNull('campaign_id')->value('config') ?? [],
            GpsRule::query()->where('campaign_id', $visit->campaign_id)->value('config') ?? [],
        );

        $deviceTime = isset($gps['device_ts']) ? Carbon::parse($gps['device_ts']) : null;
        $today = ($offlineReplay && $deviceTime ? $deviceTime->copy()->setTimezone(config('app.timezone')) : now())->toDateString();

        $problem = null;
        if ($isCheckin) {
            if ($assignment->agent_id !== $agent->getKey()) {
                $problem = 'The assignment belongs to another agent.';
            } elseif (!$assignment->status->isOpen()) {
                $problem = "The assignment is {$assignment->status->value}.";
            } elseif ($assignment->scheduled_date->toDateString() !== $today) {
                $problem = 'The assignment is scheduled for '.$assignment->scheduled_date->toDateString().', not today.';
            }
        }

        $previous = VisitCheckin::query()->join('visits', 'visits.id', '=', 'visit_checkins.visit_id')
            ->where('visits.agent_id', $agent->getKey())->where('visit_checkins.visit_id', '!=', $visit->id)
            ->orderByDesc('visit_checkins.server_ts')->first(['visit_checkins.latitude', 'visit_checkins.longitude', 'visit_checkins.server_ts', 'visit_checkins.device_id']);

        $recent = VisitCheckin::query()->join('visits', 'visits.id', '=', 'visit_checkins.visit_id')
            ->where('visits.agent_id', $agent->getKey())->where('visits.location_id', '!=', $visit->location_id)
            ->orderByDesc('visit_checkins.server_ts')->limit(5)->get(['visit_checkins.latitude', 'visit_checkins.longitude'])
            ->map(fn ($c) => ['lat' => (float) $c->latitude, 'lng' => (float) $c->longitude])->all();

        return GpsValidator::make($overrides)->evaluate(
            ['lat' => $location->latitude, 'lng' => $location->longitude, 'radius_m' => $location->geofence_radius_m],
            [
                'lat' => (float) $gps['latitude'], 'lng' => (float) $gps['longitude'],
                'accuracy_m' => isset($gps['accuracy_m']) ? (float) $gps['accuracy_m'] : null,
                'device_time' => $deviceTime, 'server_time' => now(), 'mock_flag' => $gps['mock_flag'] ?? null, 'offline_replay' => $offlineReplay,
                'device_changed' => $isCheckin && $previous && !empty($previous->device_id) && !empty($gps['device_id']) && $previous->device_id !== $gps['device_id'],
                'assignment_valid' => $problem === null, 'assignment_problem' => $problem,
                'previous' => $previous ? ['lat' => (float) $previous->latitude, 'lng' => (float) $previous->longitude, 'at' => Carbon::parse($previous->server_ts)] : null,
                'recent_points' => $recent,
            ],
        );
    }
}
