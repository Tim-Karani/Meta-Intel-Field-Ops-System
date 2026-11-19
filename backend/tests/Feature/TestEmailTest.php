<?php

namespace Tests\Feature;

use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** v1.11.1: an administrator can send a test email; nobody else can; a log/array mailer says plainly that nothing is delivered. */
class TestEmailTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    public function test_a_log_mailer_says_nothing_was_delivered(): void
    {
        $this->s->actingAs($this->s->admin);
        $this->api('POST', 'system/test-email', [])->assertOk()->assertJsonPath('data.sent', false);
    }

    public function test_a_field_agent_cannot_send_a_test_email(): void
    {
        $this->s->actingAs($this->s->agent);
        $this->api('POST', 'system/test-email', [])->assertStatus(403);
    }

    public function test_the_address_must_be_valid(): void
    {
        $this->s->actingAs($this->s->admin);
        $this->api('POST', 'system/test-email', ['to' => 'not-an-email'])->assertStatus(422);
    }
}
