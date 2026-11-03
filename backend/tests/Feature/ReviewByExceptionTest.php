<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\StockMovement;
use App\Models\Visit;
use Illuminate\Support\Str;
use App\Domain\Projects\ProjectSettings;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** Review by exception, office-set positions, optional sales, stock accountability. */
class ReviewByExceptionTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    private function work(array $settings = [], ?Location $at = null): string
    {
        $this->s->campaignA->update(['settings' => $settings + ['visit_photos' => false, 'capture_geo' => 'first_visit', 'tasks' => ['sales' => true]]]);
        $assignmentId = $this->s->assignmentA->id;
        if ($at) {
            $this->s->actingAs($this->s->manager);
            $this->s->campaignA->locations()->syncWithoutDetaching([$at->id]);
            $assignmentId = $this->api('POST', 'assignments', ['campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->agent->id, 'location_id' => $at->id, 'scheduled_date' => now()->toDateString()])->assertCreated()->json('data.id');
        }
        $this->s->actingAs($this->s->agent);
        $id = $this->api('POST', 'visits', ['assignment_id' => $assignmentId, 'client_uuid' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->api('POST', "visits/$id/check-in", Scenario::gps())->assertOk();
        $this->api('PUT', "visits/$id/responses", ['answers' => [$this->s->questionIds()['Stocked?'] => true]])->assertOk();
        $this->api('POST', "visits/$id/check-out", Scenario::gps())->assertOk();

        return $id;
    }

    public function test_a_visit_at_a_known_outlet_with_clean_gps_is_approved_automatically(): void
    {
        $id = $this->work(['review' => 'exceptions']);
        $this->api('POST', "visits/$id/submit")->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.auto_approved', true);
        $this->assertDatabaseHas('visit_events', ['visit_id' => $id, 'type' => 'auto_approved', 'actor_id' => null]);

        // Nobody was asked to review it, and the client can already see it.
        $this->s->actingAs($this->s->supervisor);
        $this->api('GET', 'visits', ['needs_review' => 1])->assertOk()->assertJsonCount(0, 'data');
        $this->s->actingAs($this->s->clientAUser);
        $this->api('GET', "portal/visits/$id")->assertOk();
    }

    public function test_a_project_can_no_longer_ask_for_every_visit_to_be_reviewed(): void
    {
        $this->assertSame('exceptions', ProjectSettings::normalize(['review' => 'all'])['review']);
        $id = $this->work(['review' => 'all']);
        $this->api('POST', "visits/$id/submit")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_the_first_visit_to_an_unconfirmed_outlet_is_reviewed_and_approving_confirms_the_position(): void
    {
        $loc = Scenario::location('Fresh shop', Scenario::LAT, Scenario::LNG);
        $loc->forceFill(['needs_review' => true, 'geo_source' => 'first_visit'])->save();
        $id = $this->work(['review' => 'exceptions'], $loc);
        $this->api('POST', "visits/$id/submit")->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.review_reason', 'new_outlet');

        $this->s->actingAs($this->s->supervisor);
        $this->api('GET', 'visits', ['needs_review' => 1])->assertOk()->assertJsonCount(1, 'data');
        $this->api('POST', "visits/$id/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertFalse($loc->refresh()->needs_review, 'approving the visit confirms the outlet position');
    }

    public function test_a_flagged_gps_reading_is_reviewed_even_at_a_known_outlet(): void
    {
        $id = $this->work(['review' => 'exceptions']);
        Visit::query()->whereKey($id)->update(['gps_outcome' => 'warning']);
        $this->api('POST', "visits/$id/submit")->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.review_reason', 'gps_warning');
    }

    public function test_approval_accepts_the_visit_photos_so_the_client_sees_them(): void
    {
        $id = $this->work(['review' => 'exceptions']);
        Visit::query()->whereKey($id)->update(['gps_outcome' => 'warning']);   // a flagged reading is what sends a visit to a reviewer
        $photo = \App\Models\Photo::query()->forceCreate([
            'id' => (string) Str::uuid(), 'visit_id' => $id, 'taken_by' => $this->s->agent->id, 'kind' => 'evidence', 'disk' => 'local', 'path' => 'x/y.jpg',
            'mime' => 'image/jpeg', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'review_status' => 'pending',
        ]);
        $this->api('POST', "visits/$id/submit")->assertOk();
        $this->s->actingAs($this->s->supervisor);
        $this->api('POST', "visits/$id/approve")->assertOk();
        $this->assertSame('accepted', $photo->refresh()->review_status);
    }

    public function test_when_the_office_sets_positions_an_agent_cannot_map_an_outlet(): void
    {
        $this->s->campaignA->update(['settings' => ['visit_photos' => false, 'capture_geo' => 'office_only']]);
        $this->s->actingAs($this->s->manager);
        $loc = $this->api('POST', 'locations', ['name' => 'No pin', 'type' => 'kiosk'])->assertCreated()->json('data');
        $this->s->campaignA->locations()->attach($loc['id']);
        $a = $this->api('POST', 'assignments', ['campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->agent->id, 'location_id' => $loc['id'], 'scheduled_date' => now()->toDateString()])->assertCreated()->json('data');

        $this->s->actingAs($this->s->agent);
        $v = $this->api('POST', 'visits', ['assignment_id' => $a['id'], 'client_uuid' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->api('POST', "visits/$v/check-in", Scenario::gps())->assertStatus(422)->assertJsonPath('code', 'outlet_not_mapped');
        $this->assertNull(Location::query()->find($loc['id'])->latitude);

        // Once the office gives it a position, the same check-in works and is judged against that position.
        $this->s->actingAs($this->s->manager);
        $this->api('PATCH', "locations/{$loc['id']}", ['latitude' => Scenario::LAT, 'longitude' => Scenario::LNG])->assertOk();
        $this->s->actingAs($this->s->agent);
        $this->api('POST', "visits/$v/check-in", Scenario::gps())->assertOk();
    }

    public function test_sales_are_only_recorded_on_projects_that_sell(): void
    {
        $id = $this->work(['tasks' => ['sales' => false]]);
        $this->api('POST', "visits/$id/sales", ['items' => [['product_id' => $this->s->productA->id, 'quantity' => 1]]])->assertStatus(422)->assertJsonPath('code', 'sales_not_enabled');

        $this->s->campaignA->update(['settings' => ['visit_photos' => false, 'tasks' => ['sales' => true]]]);
        $this->api('POST', "visits/$id/sales", ['items' => [['product_id' => $this->s->productA->id, 'quantity' => 1]]])->assertCreated();
    }

    public function test_session_features_say_whether_any_project_sells(): void
    {
        $this->s->campaignA->update(['settings' => ['tasks' => ['sales' => false]]]);
        $this->s->campaignB->update(['settings' => ['tasks' => ['sales' => false]]]);
        $this->s->actingAs($this->s->admin);
        $this->api('GET', 'auth/me')->assertOk()->assertJsonPath('data.features.sales', false);
        $this->s->campaignB->update(['settings' => ['tasks' => ['sales' => true]]]);
        $this->api('GET', 'auth/me')->assertOk()->assertJsonPath('data.features.sales', true);
    }

    public function test_stock_is_issued_sold_and_returned_per_agent(): void
    {
        $this->s->campaignA->update(['settings' => ['visit_photos' => false, 'tasks' => ['sales' => true]]]);
        $this->s->actingAs($this->s->supervisor);
        $base = ['campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->agent->id, 'product_id' => $this->s->productA->id];
        $this->api('POST', 'stock', $base + ['kind' => 'issue', 'quantity' => 20])->assertCreated();

        // The agent sells 3 at a visit.
        $this->s->actingAs($this->s->agent);
        $v = $this->api('POST', 'visits', ['assignment_id' => $this->s->assignmentA->id, 'client_uuid' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->api('POST', "visits/$v/check-in", Scenario::gps())->assertOk();
        $this->api('POST', "visits/$v/sales", ['items' => [['product_id' => $this->s->productA->id, 'quantity' => 3]]])->assertCreated();

        $this->s->actingAs($this->s->supervisor);
        $this->api('POST', 'stock', $base + ['kind' => 'return', 'quantity' => 2])->assertCreated();
        $row = $this->api('GET', 'stock', ['campaign_id' => $this->s->campaignA->id])->assertOk()->json('data.rows.0');
        $this->assertSame([20, 3, 2, 15], [$row['issued'], $row['sold'], $row['returned'], $row['balance']]);

        // Corrections need a reason; the field agent cannot touch stock; a project that does not sell has none.
        $this->api('POST', 'stock', $base + ['kind' => 'adjust', 'quantity' => -1])->assertStatus(422);
        $this->s->actingAs($this->s->agent);
        $this->api('GET', 'stock', ['campaign_id' => $this->s->campaignA->id])->assertForbidden();
        $this->s->campaignA->update(['settings' => ['tasks' => ['sales' => false]]]);
        $this->s->actingAs($this->s->supervisor);
        $this->api('POST', 'stock', $base + ['kind' => 'issue', 'quantity' => 1])->assertStatus(422)->assertJsonPath('code', 'sales_not_enabled');
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_a_supervisor_cannot_issue_stock_to_someone_elses_agent(): void
    {
        $this->s->campaignA->update(['settings' => ['tasks' => ['sales' => true]]]);
        $this->s->actingAs($this->s->supervisor);
        $this->api('POST', 'stock', ['campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->otherAgent->id, 'product_id' => $this->s->productA->id, 'kind' => 'issue', 'quantity' => 5])->assertForbidden();
    }
}
