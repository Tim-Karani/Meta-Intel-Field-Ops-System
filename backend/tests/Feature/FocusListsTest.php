<?php

namespace Tests\Feature;

use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** The client / project focus narrows planning and insight lists; it never widens access. */
class FocusListsTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
        $this->s->actingAs($this->s->admin);
    }

    private function names(string $uri, array $q): array
    {
        return collect($this->api('GET', $uri, $q)->assertOk()->json('data'))->pluck('name')->all();
    }

    public function test_projects_and_clients_follow_the_focus(): void
    {
        $this->assertSame(['CAMP-A'], $this->names('campaigns', ['campaign_id' => $this->s->campaignA->id]));
        $this->assertSame(['CAMP-B'], $this->names('campaigns', ['client_id' => $this->s->clientB->id]));
        $this->assertSame(['Client A'], $this->names('clients', ['client_id' => $this->s->clientA->id]));
        $this->assertSame(['Client B'], $this->names('clients', ['campaign_id' => $this->s->campaignB->id]));
    }

    public function test_outlets_and_products_follow_the_project(): void
    {
        $this->assertSame(['Store A'], $this->names('locations', ['campaign_id' => $this->s->campaignA->id]));
        $this->assertSame(['Store B'], $this->names('locations', ['client_id' => $this->s->clientB->id]));
        $this->assertSame(['Cola 500ml'], $this->names('products', ['campaign_id' => $this->s->campaignA->id]));
        $this->assertSame([], $this->names('products', ['campaign_id' => $this->s->campaignB->id]));
    }

    public function test_forms_and_activities_follow_the_project(): void
    {
        $this->assertSame(['Audit form'], $this->names('forms', ['campaign_id' => $this->s->campaignA->id]));
        $this->assertSame([], $this->names('forms', ['campaign_id' => $this->s->campaignB->id]));
        $this->assertSame(['Audit B'], $this->names('activities', ['campaign_id' => $this->s->campaignB->id]));
    }

    public function test_no_focus_still_shows_everything_and_bad_ids_are_ignored(): void
    {
        $this->assertCount(2, $this->names('campaigns', []));
        $this->assertCount(2, $this->names('campaigns', ['campaign_id' => 'not-a-uuid']));
    }

    public function test_focus_cannot_widen_a_managers_access(): void
    {
        $this->s->actingAs($this->s->manager);   // linked to campaign A only
        $this->assertSame([], $this->names('campaigns', ['campaign_id' => $this->s->campaignB->id]));
    }

    public function test_report_schedules_follow_the_focus(): void
    {
        $mk = fn (string $n, array $f) => $this->api('POST', 'report-schedules', ['name' => $n, 'report_type' => 'osa', 'period' => 'yesterday', 'frequency' => 'daily', 'send_time' => '07:00', 'recipients' => ['a@example.com'], 'filters' => $f])->assertCreated();
        $mk('For A', ['campaign_id' => $this->s->campaignA->id]);
        $mk('For B', ['campaign_id' => $this->s->campaignB->id]);
        $mk('For everyone', []);
        $names = $this->names('report-schedules', ['campaign_id' => $this->s->campaignA->id]);
        $this->assertContains('For A', $names);
        $this->assertContains('For everyone', $names);
        $this->assertNotContains('For B', $names);
    }
}
