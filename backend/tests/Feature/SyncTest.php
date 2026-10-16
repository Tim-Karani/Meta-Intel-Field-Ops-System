<?php

namespace Tests\Feature;

use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** The mobile bootstrap/changes endpoints hand an agent only their own work, with the form and products they need. */
class SyncTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    public function test_bootstrap_contains_only_the_agents_own_assignments(): void
    {
        $this->s->actingAs($this->s->agent);
        $d = $this->api('GET', 'sync/bootstrap')->assertOk()->json('data');

        $this->assertTrue($d['full']);
        $this->assertSame([$this->s->assignmentA->id], $d['assignment_ids']);
        $this->assertCount(1, $d['assignments']);
        $this->assertSame($this->s->locationA->id, $d['assignments'][0]['location']['id']);
        $this->assertNotEmpty($d['server_time']);
    }

    public function test_bootstrap_includes_the_form_structure_and_campaign_products(): void
    {
        $this->s->actingAs($this->s->agent);
        $d = $this->api('GET', 'sync/bootstrap')->assertOk()->json('data');

        $ids = array_column($d['forms'], 'id');
        $this->assertContains($this->s->formA->id, $ids);
        $form = collect($d['forms'])->firstWhere('id', $this->s->formA->id);
        $this->assertNotEmpty($form['sections'][0]['questions']);
        $this->assertContains($this->s->productA->id, array_column($d['products'], 'id'));
    }

    public function test_changes_requires_since_and_returns_the_current_id_list(): void
    {
        $this->s->actingAs($this->s->agent);
        $this->api('GET', 'sync/changes')->assertStatus(422);

        $d = $this->api('GET', 'sync/changes', ['since' => now()->addMinute()->toIso8601String()])->assertOk()->json('data');
        $this->assertFalse($d['full']);
        $this->assertSame([], $d['assignments']);
        $this->assertSame([$this->s->assignmentA->id], $d['assignment_ids']);
    }

    public function test_a_client_user_cannot_use_sync(): void
    {
        $this->s->actingAs($this->s->clientAUser);
        $this->api('GET', 'sync/bootstrap')->assertForbidden();
    }
}
