<?php

namespace Tests\Feature;

use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** v1.10: a person can edit their own name, email, username and phone; nothing else about the account. */
class ProfileUpdateTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
        $this->s->actingAs($this->s->ops);
    }

    public function test_name_phone_and_username_can_be_changed_without_a_password(): void
    {
        $email = $this->s->ops->email;
        $this->api('PATCH', 'auth/profile', ['name' => 'Tim Karani', 'email' => $email, 'username' => 'tim.karani', 'phone' => '+254700000000'])
            ->assertOk()->assertJsonPath('data.name', 'Tim Karani')->assertJsonPath('data.username', 'tim.karani');
        $this->assertDatabaseHas('users', ['id' => $this->s->ops->id, 'name' => 'Tim Karani', 'phone' => '+254700000000']);
    }

    public function test_changing_the_email_needs_the_current_password(): void
    {
        $this->api('PATCH', 'auth/profile', ['name' => 'Ops', 'email' => 'New.Address@example.com'])->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->api('PATCH', 'auth/profile', ['name' => 'Ops', 'email' => 'New.Address@example.com', 'current_password' => 'wrong-password'])->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'new.address@example.com']);
    }

    public function test_an_email_held_by_someone_else_is_refused_in_any_capitalisation(): void
    {
        $other = strtoupper($this->s->agent->email);
        $this->api('PATCH', 'auth/profile', ['name' => 'Ops', 'email' => $other, 'current_password' => 'x'])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_role_and_client_cannot_be_changed_through_the_profile(): void
    {
        $roles = $this->s->ops->roleSlugs();
        $client = $this->s->ops->client_id;
        $this->api('PATCH', 'auth/profile', ['name' => 'Ops', 'email' => $this->s->ops->email, 'role' => 'system_admin', 'client_id' => $this->s->clientA->id, 'status' => 'disabled'])->assertOk();
        $fresh = $this->s->ops->fresh();
        $this->assertSame($roles, $fresh->roleSlugs());
        $this->assertSame($client, $fresh->client_id);
        $this->assertNotSame('disabled', (string) $fresh->status);
    }
}
