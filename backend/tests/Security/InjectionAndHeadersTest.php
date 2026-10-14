<?php

namespace Tests\Security;

use App\Models\Client;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

class InjectionAndHeadersTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
        $this->s->actingAs($this->s->admin);
    }

    public function test_sql_injection_payloads_in_search_filter_and_sort_are_inert(): void
    {
        $payloads = ["' OR '1'='1", "'; DROP TABLE clients; --", '1; SELECT pg_sleep(5)', '" OR ""="', "%' UNION SELECT password FROM users --", '\\'];
        foreach ($payloads as $p) {
            foreach (['clients', 'campaigns', 'users', 'locations', 'products', 'issues', 'audit-logs'] as $path) {
                $res = $this->api('GET', $path, ['search' => $p, 'status' => $p, 'sort' => $p, 'client_id' => $p, 'campaign_id' => $p]);
                $this->assertContains($res->status(), [200, 422], "$path with payload $p returned {$res->status()}");
                $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
            }
        }
        $this->assertSame(2, Client::query()->count(), 'no data was touched');
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('clients'));
    }

    public function test_stored_xss_is_returned_as_inert_json_with_hardening_headers(): void
    {
        $xss = '<img src=x onerror=alert(1)><script>alert(2)</script>';
        $id = $this->api('POST', 'clients', ['name' => $xss, 'notes' => $xss])->assertCreated()->json('data.id');
        $res = $this->api('GET', "clients/$id")->assertOk();

        $this->assertStringStartsWith('application/json', $res->headers->get('Content-Type'));
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($xss, $res->json('data.name'), 'data is stored verbatim; escaping is the renderer\'s job');
    }

    public function test_security_headers_are_present_on_every_response_including_errors(): void
    {
        foreach ([$this->api('GET', 'health'), $this->api('GET', 'clients'), $this->api('GET', 'clients/nope'), $this->api('POST', 'clients', [])] as $res) {
            $res->assertHeader('X-Content-Type-Options', 'nosniff');
            $res->assertHeader('X-Frame-Options', 'DENY');
            $res->assertHeader('Referrer-Policy', 'no-referrer');
            $res->assertHeader('Cache-Control', 'no-store');
            $this->assertNotEmpty($res->headers->get('X-Request-Id'));
            $this->assertStringContainsString("default-src 'none'", $res->headers->get('Content-Security-Policy'));
        }
    }

    public function test_health_endpoint_leaks_nothing(): void
    {
        $this->app['auth']->forgetGuards();
        $res = $this->getJson('/api/v1/health')->assertOk();
        $this->assertEqualsCanonicalizing(['status', 'time'], array_keys($res->json()));
    }

    public function test_client_supplied_request_ids_cannot_inject_into_logs(): void
    {
        $res = $this->withHeaders(['X-Request-Id' => "evil\r\nSet-Cookie: x=1"])->getJson('/api/v1/health');
        $this->assertStringNotContainsString('Set-Cookie', (string) $res->headers->get('X-Request-Id'));
    }

    public function test_sensitive_values_never_reach_the_audit_log(): void
    {
        $this->api('POST', 'users', ['name' => 'Audit Probe', 'email' => 'probe@example.test', 'role' => 'analyst', 'password' => 'Pr0be!Passw0rd#'])->assertCreated();
        $dump = json_encode(\App\Models\AuditLog::query()->get()->toArray());
        $this->assertStringNotContainsString('Pr0be!Passw0rd#', $dump);
        $this->assertStringNotContainsString('$2y$', $dump);
    }

    public function test_cors_does_not_reflect_arbitrary_origins(): void
    {
        $res = $this->withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'GET'])->call('OPTIONS', '/api/v1/clients');
        $this->assertNotSame('https://evil.example', $res->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $res->headers->get('Access-Control-Allow-Origin'));
    }
}
