<?php

namespace Tests\Security;

use App\Models\Visit;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** GPS is evidence, not proof: manipulation signals raise flags for reviewers; hard failures are rejected. */
class GpsManipulationTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
        $this->s->actingAs($this->s->agent);
    }

    private function newVisit(): string
    {
        return $this->api('POST', 'visits', ['assignment_id' => $this->s->assignmentA->id, 'client_uuid' => (string) Str::uuid()])->json('data.id');
    }

    public function test_a_mock_location_is_blocked_recorded_and_reported_to_the_team(): void
    {
        $v = $this->newVisit();
        $this->api('POST', "visits/$v/check-in", Scenario::gps(0, ['mock_flag' => true]))->assertStatus(422)->assertJsonPath('code', 'fake_gps');
        $this->assertDatabaseMissing('visit_checkins', ['visit_id' => $v]);
        $this->assertSame('scheduled', Visit::query()->find($v)->status->value);
        $this->assertDatabaseHas('visit_events', ['visit_id' => $v, 'type' => 'fake_gps_blocked']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->s->supervisor->id, 'type' => 'visit.fake_gps']);
        // Once the spoofing is switched off the agent can check in.
        $this->api('POST', "visits/$v/check-in", Scenario::gps(0))->assertOk();
    }

    public function test_several_weak_signals_together_are_suspicious_and_notify_reviewers(): void
    {
        $v = $this->newVisit();
        $this->api('POST', "visits/$v/check-in", Scenario::gps(150, ['device_ts' => now()->subHours(2)->toIso8601String()]))->assertOk()->assertJsonPath('data.gps_outcome', 'suspicious');
        $this->assertDatabaseHas('notifications', ['user_id' => $this->s->supervisor->id, 'type' => 'visit.gps_suspicious']);
    }

    public function test_a_stale_device_clock_is_flagged_and_clients_cannot_disable_that_check(): void
    {
        $v = $this->newVisit();
        $res = $this->api('POST', "visits/$v/check-in", Scenario::gps(0, ['device_ts' => now()->subHours(3)->toIso8601String(), 'offline_replay' => true]))->assertOk();
        $this->assertContains($res->json('data.gps_outcome'), ['warning', 'suspicious']);
        $codes = array_column($res->json('data.checkin.reasons'), 'code');
        $this->assertContains('timestamp_skew_large', $codes, 'a client-supplied offline_replay flag must be ignored');
    }

    public function test_more_than_200_m_from_the_outlet_is_refused_and_never_stored_as_a_check_in(): void
    {
        $v = $this->newVisit();
        foreach ([2000, 50000, 1_000_000] as $m) {
            $this->api('POST', "visits/$v/check-in", Scenario::gps($m))->assertStatus(422)->assertJsonPath('code', 'outside_radius');
        }
        $this->assertDatabaseMissing('visit_checkins', ['visit_id' => $v]);
        $this->assertSame('scheduled', Visit::query()->find($v)->status->value);
        $this->assertSame(3, \App\Models\VisitEvent::query()->where('visit_id', $v)->where('type', 'checkin_blocked_distance')->count());
    }

    public function test_impossible_values_are_validation_errors(): void
    {
        $v = $this->newVisit();
        foreach ([['latitude' => 91], ['latitude' => -91], ['longitude' => 181], ['latitude' => 'abc'], ['accuracy_m' => -5], ['latitude' => null]] as $bad) {
            $this->api('POST', "visits/$v/check-in", $bad + Scenario::gps())->assertStatus(422);
        }
    }

    public function test_unusable_accuracy_is_rejected_not_trusted(): void
    {
        $v = $this->newVisit();
        $this->api('POST', "visits/$v/check-in", Scenario::gps(0, ['accuracy_m' => 5000]))->assertStatus(422)->assertJsonPath('code', 'gps_rejected');
    }

    public function test_checking_in_on_the_wrong_day_is_rejected(): void
    {
        $this->s->assignmentA->update(['scheduled_date' => now()->addDays(3)->toDateString()]);
        $v = $this->newVisit();
        $this->api('POST', "visits/$v/check-in", Scenario::gps())->assertStatus(422)->assertJsonPath('code', 'gps_rejected');
    }

    public function test_campaign_gps_policy_can_be_tightened_by_administrators_only(): void
    {
        $this->s->actingAs($this->s->manager);
        $this->api('PUT', 'system/gps-rules', ['campaign_id' => $this->s->campaignA->id, 'default_radius_m' => 20])->assertForbidden();

        $this->s->actingAs($this->s->admin);
        $this->api('PUT', 'system/gps-rules', ['campaign_id' => $this->s->campaignA->id, 'near_multiplier' => 1, 'far_distance_m' => 300])->assertOk();
        $this->api('PUT', 'system/gps-rules', ['far_distance_m' => 99999999])->assertStatus(422);

        $this->s->actingAs($this->s->agent);
        $v = $this->newVisit();
        $this->api('POST', "visits/$v/check-in", Scenario::gps(400))->assertStatus(422);   // beyond the stricter 300 m limit
    }
}
