<?php

namespace App\Domain\Visits;

use App\Domain\Audit\AuditService;
use App\Domain\Forms\FormResponseValidator;
use App\Domain\Locations\OutletService;
use App\Domain\Notifications\NotificationService;
use App\Domain\Projects\ProjectSettings;
use App\Models\Campaign;
use App\Enums\AssignmentStatus;
use App\Enums\GpsOutcome;
use App\Enums\VisitStatus;
use App\Models\Assignment;
use App\Models\Attendance;
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
    public function __construct(private FormResponseValidator $forms, private OutletService $outlets) {}

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

        $capturing = !$visit->location->isMapped();
        if ($capturing && $this->officeMustSetPosition($visit)) {
            // The project wants every outlet positioned by the office, so an agent can never create (or fake) a position.
            throw new DomainRuleException('This outlet has no position yet. Your office must set it before it can be visited. Tell your supervisor.', 'outlet_not_mapped', 422);
        }
        if ($capturing) {
            $this->assertCapturable($visit, $agent, $gps);
        }

        $evaluation = $this->evaluate($visit, $agent, $gps, true, $offlineReplay);
        $this->enforceCheckinRules($visit, $agent, $gps, $evaluation, $capturing);

        if ($evaluation->outcome === GpsOutcome::Rejected) {
            $this->event($visit, 'checkin_rejected', $visit->status, $visit->status, $agent, $evaluation->summary(), [
                'gps' => $evaluation->toArray(), 'submitted' => ['lat' => $gps['latitude'], 'lng' => $gps['longitude'], 'accuracy_m' => $gps['accuracy_m'] ?? null],
            ]);
            AuditService::record('visit.checkin_rejected', 'visits', $visit, null, ['outcome' => 'rejected'], ['distance_m' => $evaluation->distanceM]);
            throw new DomainRuleException('Check-in rejected: '.$evaluation->summary(), 'gps_rejected', 422, ['gps' => $evaluation->toArray()]);
        }

        return DB::transaction(function () use ($visit, $agent, $gps, $evaluation, $offlineReplay) {
            $visit = Visit::query()->whereKey($visit->getKey())->lockForUpdate()->firstOrFail();
            $this->assertStatus($visit, VisitStatus::Scheduled, 'check in');

            VisitCheckin::query()->create($this->gpsRow($visit, $gps, $evaluation) + [
                'provider' => $gps['provider'] ?? null, 'device_id' => $gps['device_id'] ?? null,
                'network_type' => $gps['network_type'] ?? null, 'app_version' => $gps['app_version'] ?? null,
            ]);

            $mapped = $this->outlets->captureFromCheckIn($visit->location, $agent, $gps);

            $this->markWorkStarted($agent, $gps, $offlineReplay);
            $visit->update(['started_at' => now(), 'gps_outcome' => $evaluation->outcome, 'device_id' => $gps['device_id'] ?? $visit->device_id]);
            $this->move($visit, VisitStatus::Started, $agent, 'checked_in', $evaluation->outcome === GpsOutcome::Valid ? null : $evaluation->summary(), ['gps' => $evaluation->toArray()]);

            $assignment = $visit->assignment;
            if ($assignment->status !== AssignmentStatus::InProgress && $assignment->status->canMoveTo(AssignmentStatus::InProgress)) {
                $assignment->update(['status' => AssignmentStatus::InProgress]);
            }
            if ($mapped) {
                NotificationService::notify(NotificationService::visitReviewers($visit), 'location.geo_captured', 'Outlet position captured',
                    "“{$visit->location->name}” was mapped from {$agent->name}'s check-in. Please confirm the position.", ['location_id' => $visit->location_id, 'visit_id' => $visit->id]);
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
            $this->markWorkEnded($agent, $gps);

            return $visit->refresh();
        });
    }

    /**
     * Hard limits that no scoring can soften. Both are recorded (event + audit) and the team is told, so a refused attempt is never silent.
     *  - A mock / fake-GPS reading never checks anyone in.
     *  - A mapped outlet can only be checked in within `field.checkin.max_distance_m` (200 m), allowing a small credit for a noisy fix.
     */
    private function enforceCheckinRules(Visit $visit, User $agent, array $gps, GpsEvaluation $evaluation, bool $capturing): void
    {
        $rules = config('field.checkin');
        $submitted = ['lat' => $gps['latitude'], 'lng' => $gps['longitude'], 'accuracy_m' => $gps['accuracy_m'] ?? null];

        if (($rules['block_mock_location'] ?? true) && ($gps['mock_flag'] ?? null) === true) {
            $this->event($visit, 'fake_gps_blocked', $visit->status, $visit->status, $agent, 'A mock / fake GPS location was detected at check-in.', ['submitted' => $submitted, 'provider' => $gps['provider'] ?? null]);
            AuditService::record('visit.fake_gps', 'visits', $visit, null, ['outcome' => 'blocked'], ['agent_id' => $agent->getKey(), 'provider' => $gps['provider'] ?? null]);
            NotificationService::notify(NotificationService::visitReviewers($visit), 'visit.fake_gps', 'Fake GPS detected',
                "{$agent->name} tried to check in at “{$visit->location->name}” with a mock location.", ['visit_id' => $visit->id, 'agent_id' => $agent->getKey()]);
            throw new DomainRuleException('Check-in blocked: your phone is using a fake (mock) location. Turn off any location-spoofing app or developer mock-location setting and try again. Your supervisor has been told.', 'fake_gps', 422);
        }

        if ($capturing) {
            return;     // no position on file yet: this check-in maps the outlet (accuracy rules apply in assertCapturable)
        }
        $max = (float) $rules['max_distance_m'];
        $accuracy = isset($gps['accuracy_m']) ? (float) $gps['accuracy_m'] : 0.0;
        $effective = $evaluation->distanceM - min($accuracy, (float) $rules['accuracy_credit_m']);
        if ($effective > $max) {
            $this->event($visit, 'checkin_blocked_distance', $visit->status, $visit->status, $agent, sprintf('%.0f m from the outlet (limit %.0f m).', $evaluation->distanceM, $max), ['submitted' => $submitted, 'distance_m' => $evaluation->distanceM]);
            AuditService::record('visit.checkin_too_far', 'visits', $visit, null, ['outcome' => 'blocked'], ['distance_m' => $evaluation->distanceM]);
            throw new DomainRuleException(sprintf('You are %.0f m from %s. Move to within %.0f m of the outlet to check in.', $evaluation->distanceM, $visit->location->name, $max), 'outside_radius', 422, ['distance_m' => $evaluation->distanceM, 'limit_m' => $max]);
        }
    }

    /**
     * Working time starts at the FIRST outlet check-in of the day (clocking in can be done from home, so it proves nothing).
     * The attendance row is created if the agent never clocked in.
     */
    private function markWorkStarted(User $agent, array $gps, bool $offlineReplay): void
    {
        $at = $this->moment($gps, $offlineReplay);
        $row = Attendance::query()->firstOrCreate(
            ['user_id' => $agent->getKey(), 'attendance_date' => $at->copy()->setTimezone(config('app.timezone'))->toDateString()],
            ['status' => 'present'],
        );
        if (!$row->work_started_at || $at->lessThan($row->work_started_at)) {
            // Lateness is judged on when work actually began, not on the clock-in.
            $local = $at->copy()->setTimezone(config('app.timezone'));
            $earliest = DB::table('assignments')->where('agent_id', $agent->getKey())->whereDate('scheduled_date', $local->toDateString())
                ->where('status', '!=', 'cancelled')->whereNotNull('start_time')->min('start_time');
            $late = $earliest && $local->greaterThan($local->copy()->setTimeFromTimeString($earliest)->addMinutes(\App\Http\Controllers\Api\V1\AttendanceController::LATE_AFTER_MINUTES));
            $row->update(['work_started_at' => $at, 'status' => $late ? 'late' : 'present']);
        }
    }

    /** The last check-out of the day ends working time; worked minutes = first check-in -> last check-out. */
    private function markWorkEnded(User $agent, array $gps): void
    {
        $at = $this->moment($gps, !empty($gps['offline_replay']));
        $row = Attendance::query()->where('user_id', $agent->getKey())->where('attendance_date', $at->copy()->setTimezone(config('app.timezone'))->toDateString())->first();
        if (!$row || !$row->work_started_at) {
            return;
        }
        $end = $row->work_ended_at && $row->work_ended_at->greaterThan($at) ? $row->work_ended_at : $at;
        $row->update([
            'work_ended_at' => $end,
            'worked_minutes' => max(0, (int) round($row->work_started_at->diffInSeconds($end, false) / 60)),
            'outlets_visited' => $row->outlets_visited + 1,
        ]);
    }

    /** The real time of the action: the device's timestamp when replaying an offline queue (never in the future), otherwise now. */
    private function moment(array $gps, bool $offlineReplay): Carbon
    {
        if ($offlineReplay && !empty($gps['device_ts'])) {
            $t = Carbon::parse($gps['device_ts']);
            return $t->greaterThan(now()) ? now() : $t;
        }
        return now();
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

            // Review by exception: a person looks only at visits that carry a doubt. A visit at an outlet whose position the
            // office has set or confirmed, with clean GPS, is approved automatically (the project can ask for every visit to be reviewed).
            $reason = $this->reviewReason($visit);
            if ($reason === null) {
                $visit->update(['auto_approved' => true, 'review_reason' => null, 'reviewed_at' => now(), 'review_note' => 'Approved automatically: known outlet position and clean GPS.']);
                $this->move($visit, VisitStatus::Approved, null, 'auto_approved', 'Known outlet position and clean GPS.');
                $this->acceptPendingPhotos($visit, null);
            } else {
                $visit->update(['review_reason' => $reason]);
                NotificationService::notify(NotificationService::visitReviewers($visit), 'visit.submitted', 'Visit needs review',
                    $agent->name.' submitted a visit: '.self::REASON_TEXT[$reason].'.', ['visit_id' => $visit->id, 'reason' => $reason]);
            }

            return $visit->refresh();
        });
    }

    public const REASON_TEXT = [
        'new_outlet' => 'first visit to a new outlet, confirm its position',
        'gps_warning' => 'the GPS reading was flagged',
    ];

    /** Why a person must look at this visit, or null when it can be approved automatically. */
    public function reviewReason(Visit $visit): ?string
    {
        $location = $visit->location;
        if (!$location || !$location->isMapped() || $location->needs_review) {
            return 'new_outlet';
        }
        if ($visit->gps_outcome !== GpsOutcome::Valid) {
            return 'gps_warning';
        }
        $checkout = VisitCheckout::query()->where('visit_id', $visit->id)->latest('server_ts')->first();
        if ($checkout && $checkout->outcome !== GpsOutcome::Valid) {
            return 'gps_warning';
        }

        return null;
    }

    /** Approving a visit accepts the photos nobody rejected, so the client sees them without a second round of approvals. */
    private function acceptPendingPhotos(Visit $visit, ?User $by): void
    {
        Photo::query()->where('visit_id', $visit->id)->where('review_status', 'pending')
            ->update(['review_status' => 'accepted', 'reviewed_by' => $by?->getKey(), 'reviewed_at' => now()]);
    }

    private function officeMustSetPosition(Visit $visit): bool
    {
        $campaign = Campaign::query()->find($visit->campaign_id);
        if (!$campaign || ($visit->location->created_via ?? 'office') === 'field') {
            return false;       // an outlet the agent added in the field is positioned by their first check-in
        }

        return (ProjectSettings::for($campaign)['capture_geo'] ?? 'office_only') === 'office_only';
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
            $this->acceptPendingPhotos($visit, $reviewer);      // approving the visit covers its photos: no photo-by-photo approval
            // Approving the first visit to a new outlet also confirms the position that visit captured: one decision, not two.
            if ($visit->location && $visit->location->isMapped() && $visit->location->needs_review) {
                $this->outlets->confirm($visit->location, $reviewer);
            }
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
        $minPhotos = (int) (\App\Domain\Projects\ProjectSettings::for($visit->campaign)['min_photos'] ?? 0);
        if ($minPhotos > 0) {
            $have = Photo::query()->where('visit_id', $visit->id)->where('kind', 'evidence')->where('review_status', '!=', 'rejected')->count();
            if ($have < $minPhotos) {
                $errors['evidence.minimum'] = ["This project needs at least {$minPhotos} photo(s) per visit; there are {$have}."];
            }
        }
        if (!$visit->checkin()->exists()) {
            $errors['checkin'] = ['A GPS check-in is required.'];
        }
        if (\App\Domain\Projects\ProjectSettings::for($visit->campaign)['visit_photos'] ?? true) {
            foreach (['checkin' => 'check-in', 'checkout' => 'check-out'] as $kind => $label) {
                if (!Photo::query()->where('visit_id', $visit->id)->where('kind', $kind)->where('review_status', '!=', 'rejected')->exists()) {
                    $errors["{$kind}_photo"] = ["A {$label} photo is required. It proves the assigned agent is the one at the outlet."];
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function move(Visit $visit, VisitStatus $to, ?User $actor, string $type, ?string $note = null, array $meta = []): void
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

    /** The reading becomes the outlet's position, so it must be a trustworthy fix. */
    private function assertCapturable(Visit $visit, User $agent, array $gps): void
    {
        $max = (float) config('field.gps.capture_max_accuracy_m', 50);
        $accuracy = isset($gps['accuracy_m']) ? (float) $gps['accuracy_m'] : null;
        $problem = match (true) {
            ($gps['mock_flag'] ?? null) === true => 'a mock location cannot be used to map an outlet',
            $accuracy === null => 'the device did not report a GPS accuracy',
            $accuracy > $max => sprintf('GPS accuracy is %.0f m; at least %.0f m is needed to map a new outlet', $accuracy, $max),
            default => null,
        };
        if ($problem !== null) {
            $this->event($visit, 'checkin_rejected', $visit->status, $visit->status, $agent, "Cannot map outlet: $problem.", ['submitted' => ['lat' => $gps['latitude'], 'lng' => $gps['longitude'], 'accuracy_m' => $accuracy]]);
            throw new DomainRuleException("This outlet has no position yet, and {$problem}. Step outside or move to open sky, wait for a better fix and try again.", 'gps_rejected', 422);
        }
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
            // An unmapped outlet has no position to compare with: the reading becomes the position (distance 0),
            // every other signal (accuracy, clock, travel, mock, device) is still judged.
            $location->isMapped()
                ? ['lat' => $location->latitude, 'lng' => $location->longitude, 'radius_m' => $location->geofence_radius_m]
                : ['lat' => (float) $gps['latitude'], 'lng' => (float) $gps['longitude'], 'radius_m' => $location->geofence_radius_m],
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
