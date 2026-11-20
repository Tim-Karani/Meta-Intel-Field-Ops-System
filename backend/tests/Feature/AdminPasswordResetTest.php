<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** The super administrator can set a new password for anyone and unlock accounts; nobody else can. */
class AdminPasswordResetTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    public function test_admin_resets_a_password_and_the_person_must_change_it(): void
    {
        $target = $this->s->ops;
        $target->forceFill(['failed_logins' => 3, 'locked_until' => now()->addHour()])->save();
        $target->createToken('t');

        $this->s->actingAs($this->s->admin);
        $pw = $this->api('POST', "users/{$target->id}/reset-password")->assertOk()->json('data.temporary_password');

        $this->assertGreaterThanOrEqual(10, strlen($pw));
        $fresh = User::query()->findOrFail($target->id);
        $this->assertTrue(Hash::check($pw, $fresh->password));
        $this->assertTrue($fresh->must_change_password);
        $this->assertNull($fresh->locked_until);
        $this->assertSame(0, $fresh->tokens()->count());
        $this->api('GET', 'audit-logs', ['module' => 'users', 'subject_id' => $target->id])->assertOk();
    }

    public function test_admin_can_type_a_password_but_it_must_be_long_enough(): void
    {
        $this->s->actingAs($this->s->admin);
        $this->api('POST', "users/{$this->s->ops->id}/reset-password", ['password' => 'short'])->assertStatus(422);
        $this->api('POST', "users/{$this->s->ops->id}/reset-password", ['password' => 'Chosen-Pass-2026'])->assertOk();
        $this->assertTrue(Hash::check('Chosen-Pass-2026', User::query()->findOrFail($this->s->ops->id)->password));
    }

    public function test_admin_cannot_reset_their_own_password_this_way(): void
    {
        $this->s->actingAs($this->s->admin);
        $this->api('POST', "users/{$this->s->admin->id}/reset-password")->assertStatus(422);
    }

    public function test_unlock_clears_a_lockout(): void
    {
        $this->s->ops->forceFill(['failed_logins' => 5, 'locked_until' => now()->addHour()])->save();
        $this->s->actingAs($this->s->admin);
        $this->api('POST', "users/{$this->s->ops->id}/unlock")->assertOk()->assertJsonPath('data.is_locked', false);
        $this->assertNull(User::query()->findOrFail($this->s->ops->id)->locked_until);
    }

    public function test_people_without_user_edit_cannot_reset_passwords(): void
    {
        $this->s->actingAs($this->s->ops);   // operations manager has users.view only
        $this->api('POST', "users/{$this->s->admin->id}/reset-password")->assertForbidden();
        $this->api('POST', "users/{$this->s->admin->id}/unlock")->assertForbidden();
    }
}
