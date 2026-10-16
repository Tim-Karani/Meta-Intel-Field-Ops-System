<?php

namespace Tests\Feature;

use App\Models\Issue;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** The dashboard can be focused on one client and/or campaign without mixing other clients' figures in. */
class DashboardFocusTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
        foreach ([[$this->s->campaignA, 'A'], [$this->s->campaignB, 'B']] as [$campaign, $tag]) {
            (new Issue)->forceFill(['campaign_id' => $campaign->id, 'title' => "Issue $tag", 'priority' => 'high', 'status' => 'open', 'reported_by' => $this->s->agent->id])->save();
        }
    }

    public function test_without_a_focus_the_administrator_sees_everything(): void
    {
        $this->s->actingAs($this->s->admin);
        $d = $this->api('GET', 'dashboard')->assertOk()->json('data');
        $this->assertSame(2, $d['cards']['active_campaigns']);
        $this->assertSame(2, $d['cards']['open_issues']);
    }

    public function test_focusing_on_a_client_excludes_other_clients(): void
    {
        $this->s->actingAs($this->s->admin);
        $d = $this->api('GET', 'dashboard', ['client_id' => $this->s->clientA->id])->assertOk()->json('data');
        $this->assertSame(1, $d['cards']['active_campaigns']);
        $this->assertSame(1, $d['cards']['open_issues']);
        $this->assertSame($this->s->clientA->id, $d['filters']['client_id']);
        $planned = array_sum(array_column($d['visit_trend'], 'planned'));
        $this->assertSame(1, $planned, 'Only client A\'s assignment is planned.');
    }

    public function test_focusing_on_a_campaign(): void
    {
        $this->s->actingAs($this->s->admin);
        $d = $this->api('GET', 'dashboard', ['campaign_id' => $this->s->campaignB->id])->assertOk()->json('data');
        $this->assertSame(1, $d['cards']['active_campaigns']);
        $this->assertSame(1, $d['cards']['open_issues']);
    }

    public function test_campaign_must_belong_to_the_selected_client(): void
    {
        $this->s->actingAs($this->s->admin);
        $this->api('GET', 'dashboard', ['client_id' => $this->s->clientA->id, 'campaign_id' => $this->s->campaignB->id])->assertStatus(422);
    }

    public function test_focus_values_are_validated(): void
    {
        $this->s->actingAs($this->s->admin);
        $this->api('GET', 'dashboard', ['client_id' => 'not-a-uuid'])->assertStatus(422);
    }

    public function test_a_client_or_campaign_outside_the_users_scope_is_not_found(): void
    {
        $this->s->actingAs($this->s->manager);   // linked to campaign A only
        $this->api('GET', 'dashboard', ['client_id' => $this->s->clientB->id])->assertNotFound();
        $this->api('GET', 'dashboard', ['campaign_id' => $this->s->campaignB->id])->assertNotFound();
        $this->api('GET', 'dashboard', ['client_id' => $this->s->clientA->id])->assertOk();
    }
}
