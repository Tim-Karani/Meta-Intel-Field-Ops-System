<?php

namespace Tests\Feature;

use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** The client / project focus narrows working lists; it never widens what a user may see. */
class ProjectFocusTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    public function test_assignments_can_be_focused_on_a_client_or_a_project(): void
    {
        $this->s->actingAs($this->s->admin);
        $this->assertCount(2, $this->api('GET', 'assignments')->assertOk()->json('data'));

        $a = $this->api('GET', 'assignments', ['client_id' => $this->s->clientA->id])->assertOk()->json('data');
        $this->assertSame([$this->s->assignmentA->id], array_column($a, 'id'));

        $b = $this->api('GET', 'assignments', ['campaign_id' => $this->s->campaignB->id])->assertOk()->json('data');
        $this->assertSame([$this->s->assignmentB->id], array_column($b, 'id'));

        // a project that belongs to a different client than the one chosen matches nothing
        $this->assertSame([], $this->api('GET', 'assignments', ['client_id' => $this->s->clientA->id, 'campaign_id' => $this->s->campaignB->id])->json('data'));
    }

    public function test_journey_plans_issues_and_visits_accept_the_focus(): void
    {
        $this->s->actingAs($this->s->admin);
        foreach (['journey-plans', 'issues', 'visits', 'photos', 'sales', 'osa', 'merchandising', 'attendance'] as $path) {
            $this->api('GET', $path, ['client_id' => $this->s->clientA->id])->assertOk();
            $this->api('GET', $path, ['campaign_id' => $this->s->campaignA->id])->assertOk();
        }
        $this->api('GET', 'jp-requests', ['client_id' => $this->s->clientA->id])->assertOk();
        $this->api('GET', 'attendance/board', ['campaign_id' => $this->s->campaignA->id])->assertOk();
        $this->api('GET', 'agents/live', ['client_id' => $this->s->clientA->id])->assertOk();
    }

    public function test_focus_never_widens_access(): void
    {
        $this->s->actingAs($this->s->manager);      // linked to campaign A only
        $this->assertSame([], $this->api('GET', 'assignments', ['client_id' => $this->s->clientB->id])->assertOk()->json('data'));
        $this->assertSame([], $this->api('GET', 'assignments', ['campaign_id' => $this->s->campaignB->id])->assertOk()->json('data'));
        $this->assertCount(1, $this->api('GET', 'assignments', ['client_id' => $this->s->clientA->id])->json('data'));
    }

    public function test_agents_live_is_limited_to_people_on_the_focused_project(): void
    {
        $this->s->actingAs($this->s->admin);
        $names = collect($this->api('GET', 'agents/live', ['campaign_id' => $this->s->campaignA->id])->assertOk()->json('data.data'))->pluck('name')->all();
        $this->assertContains('Agent One', $names);
        $this->assertNotContains('Agent Two', $names);
    }
}
