<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

class AuthTest extends DatabaseTestCase
{
    private function login(string $email, string $password = Scenario::PASSWORD)
    {
        return $this->api('POST', 'auth/login', ['login' => $email, 'password' => $password, 'device_name' => 'phpunit']);
    }

    public function test_login_returns_token_user_and_permissions(): void
    {
        $u = Scenario::user('Mgr', Role::CampaignManager);
        $res = $this->login($u->email)->assertOk()->assertJsonPath('success', true);

        $this->assertNotEmpty($res->json('data.token'));
        $this->assertContains('campaigns.edit', $res->json('data.user.permissions'));
        $this->assertSame('campaigns', $res->json('data.user.scope_type'));
        $this->assertNotNull($res->headers->get('X-Request-Id'));
        $this->assertArrayNotHasKey('password', $res->json('data.user'));
    }

    public function test_bad_password_and_unknown_user_are_indistinguishable(): void
    {
        $u = Scenario::user('Mgr', Role::CampaignManager);
        $a = $this->login($u->email, 'wrong-password')->assertStatus(401);
        $b = $this->login('nobody@example.test', 'wrong-password')->assertStatus(401);
        $this->assertSame($a->json('message'), $b->json('message'));
        $this->assertSame($a->json('code'), $b->json('code'));
    }

    public function test_account_locks_after_repeated_failures_and_stays_locked_with_the_right_password(): void
    {
        $u = Scenario::user('Mgr', Role::CampaignManager);
        for ($i = 0; $i < config('field.auth.max_failed_logins'); $i++) {
            $this->login($u->email, 'wrong-password-'.$i);
        }
        $this->login($u->email)->assertStatus(423)->assertJsonPath('code', 'account_locked');
        $this->assertTrue(SecurityEvent::query()->where('type', 'account_locked')->exists());

        $this->travel(config('field.auth.lockout_minutes') + 1)->minutes();
        $this->login($u->email)->assertOk();
    }

    public function test_disabled_and_suspended_accounts_cannot_sign_in(): void
    {
        foreach (['disabled', 'suspended', 'pending'] as $status) {
            $u = Scenario::user("U $status", Role::Analyst, ['status' => $status]);
            $this->login($u->email)->assertStatus(403)->assertJsonPath('code', 'account_inactive');
        }
    }

    public function test_disabling_a_user_invalidates_existing_tokens_immediately(): void
    {
        $u = Scenario::user('Mgr', Role::CampaignManager);
        $token = $this->login($u->email)->json('data.token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $u->forceFill(['status' => 'disabled'])->save();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(403);
    }

    public function test_refresh_rotates_the_token_and_logout_revokes_it(): void
    {
        $u = Scenario::user('Mgr', Role::CampaignManager);
        $old = $this->login($u->email)->json('data.token');

        $new = $this->withToken($old)->postJson('/api/v1/auth/refresh')->assertOk()->json('data.token');
        $this->assertNotSame($old, $new);
        $this->app['auth']->forgetGuards();
        $this->withToken($old)->getJson('/api/v1/auth/me')->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->withToken($new)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($new)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_protected_routes_require_authentication(): void
    {
        foreach (['clients', 'campaigns', 'visits', 'users', 'audit-logs', 'reports', 'dashboard', 'notifications'] as $path) {
            $this->api('GET', $path)->assertStatus(401)->assertJsonPath('success', false);
        }
    }

    public function test_forgot_password_never_reveals_whether_an_address_exists(): void
    {
        Notification::fake();
        $u = Scenario::user('Mgr', Role::CampaignManager);
        $known = $this->api('POST', 'auth/forgot-password', ['email' => $u->email])->assertOk();
        $unknown = $this->api('POST', 'auth/forgot-password', ['email' => 'ghost@example.test'])->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    public function test_change_password_enforces_strength_and_revokes_other_sessions(): void
    {
        $u = Scenario::user('Mgr', Role::CampaignManager);
        $other = $this->login($u->email)->json('data.token');
        $this->app['auth']->forgetGuards();
        $current = $this->login($u->email)->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->withToken($current)->postJson('/api/v1/auth/change-password', ['current_password' => Scenario::PASSWORD, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonPath('success', false);

        $this->app['auth']->forgetGuards();
        $this->withToken($current)->postJson('/api/v1/auth/change-password', [
            'current_password' => Scenario::PASSWORD, 'password' => 'An0ther!Strong1', 'password_confirmation' => 'An0ther!Strong1',
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->login($u->email, 'An0ther!Strong1')->assertOk();
    }

    public function test_validation_errors_use_the_standard_envelope(): void
    {
        $res = $this->api('POST', 'auth/login', [])->assertStatus(422);
        $res->assertJsonStructure(['success', 'message', 'errors' => ['login', 'password'], 'request_id']);
        $this->assertFalse($res->json('success'));
    }

    public function test_the_stack_trace_is_never_exposed(): void
    {
        $res = $this->api('GET', 'does-not-exist')->assertStatus(404);
        $this->assertStringNotContainsString('vendor/', $res->getContent());
        $this->assertStringNotContainsString('Stack trace', $res->getContent());
    }
}
