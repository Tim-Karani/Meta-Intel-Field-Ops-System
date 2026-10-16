<?php

namespace Tests\Feature;

use App\Models\Location;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** Unmapped outlets get their position from the first check-in; agents can add outlets in the field. */
class OutletCaptureTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    private function unmappedVisit(): array
    {
        $this->s->actingAs($this->s->manager);
        $loc = $this->api('POST', 'locations', ['name' => 'No-coords Shop', 'type' => 'kiosk', 'town' => 'Nairobi'])->assertCreated()->json('data');
        $this->assertNull($loc['latitude']);
        $this->s->campaignA->locations()->attach($loc['id']);
        $a = $this->api('POST', 'assignments', ['campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->agent->id, 'location_id' => $loc['id'], 'scheduled_date' => now()->toDateString()])->assertCreated()->json('data');
        $this->s->actingAs($this->s->agent);
        $v = $this->api('POST', 'visits', ['assignment_id' => $a['id'], 'client_uuid' => (string) Str::uuid()])->assertCreated()->json('data');

        return [$loc['id'], $v['id']];
    }

    public function test_lat_and_lng_must_come_together(): void
    {
        $this->s->actingAs($this->s->manager);
        $this->api('POST', 'locations', ['name' => 'Half', 'latitude' => -1.2])->assertStatus(422);
        $this->api('POST', 'locations', ['name' => 'Full', 'latitude' => -1.2, 'longitude' => 36.8])->assertCreated()->assertJsonPath('data.geo_source', 'manual');
    }

    public function test_first_check_in_maps_the_outlet_and_flags_it_for_review(): void
    {
        [$locId, $visitId] = $this->unmappedVisit();
        $this->api('POST', "visits/$visitId/check-in", Scenario::gps())->assertOk()->assertJsonPath('data.status', 'started')->assertJsonPath('data.gps_outcome', 'valid');

        $loc = Location::query()->findOrFail($locId);
        $this->assertEqualsWithDelta(Scenario::LAT, $loc->latitude, 1e-6);
        $this->assertSame('first_visit', $loc->geo_source);
        $this->assertTrue($loc->needs_review);
        $this->assertSame($this->s->agent->id, $loc->geo_captured_by);

        $this->s->actingAs($this->s->manager);
        $this->api('POST', "locations/$locId/confirm-geo")->assertOk()->assertJsonPath('data.needs_review', false);
    }

    public function test_a_poor_fix_or_mock_location_cannot_map_an_outlet(): void
    {
        [$locId, $visitId] = $this->unmappedVisit();
        $this->api('POST', "visits/$visitId/check-in", Scenario::gps(0, ['accuracy_m' => 90]))->assertStatus(422);
        $this->api('POST', "visits/$visitId/check-in", Scenario::gps(0, ['mock_flag' => true]))->assertStatus(422);
        $this->assertNull(Location::query()->findOrFail($locId)->latitude);
    }

    public function test_agent_adds_an_outlet_in_the_field_idempotently(): void
    {
        $this->s->actingAs($this->s->agent);
        $payload = ['location_id' => (string) Str::uuid(), 'assignment_id' => (string) Str::uuid(), 'campaign_id' => $this->s->campaignA->id,
            'activity_id' => $this->s->activityA->id, 'name' => 'Mama Njeri Duka', 'type' => 'kiosk', 'town' => 'Kasarani', 'contact_phone' => '0700000000'];

        $r = $this->api('POST', 'locations/field', $payload)->assertCreated();
        $r->assertJsonPath('data.location.id', $payload['location_id'])->assertJsonPath('data.assignment.id', $payload['assignment_id'])->assertJsonPath('data.location.needs_review', true);
        $this->assertNotNull($r->json('data.assignment.form_id'));

        $this->api('POST', 'locations/field', $payload)->assertOk();     // replay
        $this->assertSame(1, Location::query()->where('client_uuid', $payload['location_id'])->count());

        // It can be started right away and maps the outlet.
        $v = $this->api('POST', 'visits', ['assignment_id' => $payload['assignment_id'], 'client_uuid' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->api('POST', "visits/$v/check-in", Scenario::gps(40))->assertOk();
        $loc = Location::query()->findOrFail($payload['location_id']);
        $this->assertSame('field_added', $loc->geo_source);
        $this->assertTrue($loc->isMapped());
    }

    public function test_field_add_rules(): void
    {
        $this->s->actingAs($this->s->agent);
        $base = ['campaign_id' => $this->s->campaignA->id, 'name' => 'Dup Shop'];
        $this->api('POST', 'locations/field', $base + ['location_id' => (string) Str::uuid()])->assertCreated();
        $this->api('POST', 'locations/field', $base + ['location_id' => (string) Str::uuid()])->assertStatus(409);   // same name in campaign
        $this->api('POST', 'locations/field', ['location_id' => (string) Str::uuid(), 'campaign_id' => $this->s->campaignB->id, 'name' => 'Elsewhere'])->assertStatus(422);

        $this->s->actingAs($this->s->supervisor);
        $this->api('POST', 'locations/field', $base + ['location_id' => (string) Str::uuid()])->assertForbidden();
    }
}
