<?php

namespace App\Domain\Locations;

use App\Domain\Assignments\AssignmentService;
use App\Domain\Audit\AuditService;
use App\Domain\Notifications\NotificationService;
use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\Campaign;
use App\Models\Location;
use App\Models\User;
use App\Support\DomainRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Outlets without coordinates, and outlets added by a field agent standing at the outlet.
 *
 *  - An outlet with no coordinates is "unmapped". The first accepted check-in at it stores the agent's GPS fix as the
 *    outlet position (geo_source = first_visit) and flags it for supervisor confirmation.
 *  - A field agent may add an outlet (name + details). It is created unmapped, linked to the campaign and given a
 *    same-day assignment for the agent; the position is captured when they start (check in at) the outlet.
 *  - All ids are client-supplied UUIDs so the offline mobile app can create the outlet and its assignment and
 *    then record the visit before any of it has reached the server. Replaying the request is idempotent.
 */
class OutletService
{
    public function __construct(private AssignmentService $assignments) {}

    /**
     * @param array<string,mixed> $data validated payload (see LocationController::fieldRules)
     * @return array{location:Location,assignment:Assignment,created:bool}
     */
    public function createInField(array $data, User $agent): array
    {
        return DB::transaction(function () use ($data, $agent) {
            $existing = Location::query()->where('client_uuid', $data['location_id'])->first();
            if ($existing) {
                if ($existing->created_by !== $agent->getKey()) {
                    throw new DomainRuleException('This outlet id is already in use.', 'conflict', 409);
                }
                $assignment = Assignment::query()->find($data['assignment_id'] ?? null)
                    ?? Assignment::query()->where('location_id', $existing->id)->where('agent_id', $agent->getKey())->latest()->first();

                return ['location' => $existing, 'assignment' => $assignment, 'created' => false];
            }

            $campaign = Campaign::query()->findOrFail($data['campaign_id']);
            if (!$campaign->status->acceptsAssignments()) {
                throw new DomainRuleException('Outlets can only be added to planned or active campaigns.', 'campaign_not_open');
            }
            if (!DB::table('campaign_users')->where('campaign_id', $campaign->id)->where('user_id', $agent->getKey())->exists()
                && !DB::table('assignments')->where('campaign_id', $campaign->id)->where('agent_id', $agent->getKey())->exists()) {
                throw new DomainRuleException('You are not part of this campaign.', 'forbidden', 403);
            }

            // Same outlet name + town already known to this campaign? Point the agent at it instead of duplicating.
            $dupe = Location::query()->whereIn('id', DB::table('campaign_locations')->where('campaign_id', $campaign->id)->select('location_id'))
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
                ->when(!empty($data['town']), fn ($q) => $q->whereRaw('LOWER(town) = ?', [mb_strtolower(trim($data['town']))]))
                ->first();
            if ($dupe) {
                throw DomainRuleException::conflict('An outlet with this name already exists in this campaign. Pick it from your outlet list instead.', 'duplicate_outlet', ['location_id' => $dupe->id]);
            }

            $location = new Location();
            $location->forceFill([
                'id' => $data['location_id'], 'client_uuid' => $data['location_id'], 'client_id' => $campaign->client_id,
                'name' => trim($data['name']), 'type' => $data['type'] ?? 'retail_store', 'address' => $data['address'] ?? null,
                'county' => $data['county'] ?? null, 'town' => $data['town'] ?? null, 'contact_name' => $data['contact_name'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null, 'notes' => $data['notes'] ?? null, 'status' => 'active',
                'created_by' => $agent->getKey(), 'created_via' => 'field', 'needs_review' => true, 'geo_source' => null,
            ])->save();
            DB::table('campaign_locations')->insert(['campaign_id' => $campaign->id, 'location_id' => $location->id]);

            $assignment = $this->assignments->createForField([
                'id' => $data['assignment_id'] ?? null, 'campaign_id' => $campaign->id, 'activity_id' => $data['activity_id'] ?? null,
                'agent_id' => $agent->getKey(), 'location_id' => $location->id, 'scheduled_date' => now()->toDateString(),
            ], $agent);

            AuditService::record('location.field_added', 'locations', $location, null, ['name' => $location->name], ['campaign_id' => $campaign->id]);
            NotificationService::notify($this->reviewers($campaign->id, $agent), 'location.field_added', 'Outlet added in the field',
                "{$agent->name} added “{$location->name}”. Its position will be captured at their first check-in.", ['location_id' => $location->id]);

            return ['location' => $location->refresh(), 'assignment' => $assignment, 'created' => true];
        });
    }

    /**
     * Called inside the check-in transaction. Stores the reading as the outlet position when it is still unknown.
     * Returns true when this call mapped the outlet.
     */
    public function captureFromCheckIn(Location $location, User $agent, array $gps): bool
    {
        $locked = Location::query()->whereKey($location->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->isMapped()) {
            return false;       // someone else mapped it first; this visit is judged against that position
        }

        $locked->forceFill([
            'latitude' => (float) $gps['latitude'], 'longitude' => (float) $gps['longitude'], 'geo_source' => $locked->created_via === 'field' ? 'field_added' : 'first_visit',
            'geo_captured_at' => now(), 'geo_captured_by' => $agent->getKey(), 'geo_accuracy_m' => isset($gps['accuracy_m']) ? (float) $gps['accuracy_m'] : null,
            'needs_review' => true,
        ])->save();
        AuditService::record('location.geo_captured', 'locations', $locked, null, ['latitude' => $locked->latitude, 'longitude' => $locked->longitude],
            ['source' => $locked->geo_source, 'accuracy_m' => $locked->geo_accuracy_m]);
        $location->setRawAttributes($locked->getAttributes(), true);

        return true;
    }

    public function confirm(Location $location, User $actor, ?float $lat = null, ?float $lng = null): Location
    {
        if (!$location->isMapped() && ($lat === null || $lng === null)) {
            throw new DomainRuleException('This outlet has no position yet. It is captured at the first check-in, or enter coordinates.', 'not_mapped', 422);
        }
        $before = ['latitude' => $location->latitude, 'longitude' => $location->longitude];
        $attrs = ['needs_review' => false];
        if ($lat !== null && $lng !== null) {
            $attrs += ['latitude' => $lat, 'longitude' => $lng, 'geo_source' => 'manual', 'geo_captured_by' => $actor->getKey(), 'geo_captured_at' => now(), 'geo_accuracy_m' => null];
        }
        $location->update($attrs);
        AuditService::record('location.geo_confirmed', 'locations', $location, $before, ['latitude' => $location->latitude, 'longitude' => $location->longitude]);

        return $location->refresh();
    }

    /** Supervisors of the agent plus managers attached to the campaign. */
    private function reviewers(string $campaignId, User $agent)
    {
        return collect([DB::table('agent_profiles')->where('user_id', $agent->getKey())->value('supervisor_id')])
            ->merge(DB::table('campaign_users')->where('campaign_id', $campaignId)->pluck('user_id'))
            ->filter()->reject(fn ($id) => $id === $agent->getKey())->unique()->values();
    }
}
