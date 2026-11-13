<?php

namespace Tests\Feature;

use App\Domain\Assignments\JourneyPlanService;
use App\Models\JourneyPlan;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** v1.10: the phone's "ask for a plan change" screen reads the agent's own plans through jp-requests/mine. */
class JpRequestMineTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    public function test_an_agent_sees_only_their_own_active_plan_with_stops_and_project_outlets(): void
    {
        $plan = JourneyPlan::query()->create(['campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->agent->id, 'name' => 'Route', 'status' => 'active']);
        app(JourneyPlanService::class)->saveStops($plan, [['location_id' => $this->s->locationA->id, 'weekdays' => [1, 3]]], $this->s->ops);

        $this->s->actingAs($this->s->agent);
        $res = $this->api('GET', 'jp-requests/mine')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($plan->id, $res->json('data.0.id'));
        $this->assertCount(2, $res->json('data.0.stops'));
        $this->assertNotEmpty($res->json('data.0.outlets'));
    }

    public function test_someone_without_the_request_permission_is_refused(): void
    {
        $this->s->actingAs($this->s->clientAUser);
        $this->api('GET', 'jp-requests/mine')->assertForbidden();
    }
}
