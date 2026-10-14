<?php

namespace Tests\Security;

use App\Enums\Role;
use Illuminate\Support\Facades\RateLimiter;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

class BruteForceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    public function test_login_attempts_are_rate_limited_per_account_and_ip(): void
    {
        $u = Scenario::user('Target', Role::CampaignManager);
        $statuses = [];
        for ($i = 0; $i < 8; $i++) {
            $statuses[] = $this->api('POST', 'auth/login', ['login' => $u->email, 'password' => 'guess-'.$i])->status();
        }
        $this->assertContains(429, $statuses, 'throttle must engage within a handful of attempts');
        $this->assertSame(429, $this->api('POST', 'auth/login', ['login' => $u->email, 'password' => Scenario::PASSWORD])->status(), 'even the correct password is throttled during the lockout window');
    }

    public function test_credential_stuffing_across_many_accounts_is_limited_per_ip(): void
    {
        $codes = [];
        for ($i = 0; $i < 40; $i++) {
            $codes[] = $this->api('POST', 'auth/login', ['login' => "user$i@example.test", 'password' => 'Passw0rd!'.$i])->status();
        }
        $this->assertContains(429, $codes);
    }

    public function test_password_reset_requests_are_throttled(): void
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = $this->api('POST', 'auth/forgot-password', ['email' => "x$i@example.test"])->status();
        }
        $this->assertContains(429, $codes);
    }

    public function test_reset_with_a_forged_token_fails(): void
    {
        $u = Scenario::user('Target', Role::CampaignManager);
        $this->api('POST', 'auth/reset-password', ['token' => str_repeat('a', 64), 'email' => $u->email, 'password' => 'N3w!Passw0rd#1', 'password_confirmation' => 'N3w!Passw0rd#1'])->assertStatus(422);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check(Scenario::PASSWORD, $u->refresh()->password));
    }
}
