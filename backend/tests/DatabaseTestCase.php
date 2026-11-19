<?php

namespace Tests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/** Real PostgreSQL, real migrations, real roles/permissions; evidence goes to a fake disk. */
abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Called directly, not via db:seed: that command silently refuses to run when APP_ENV is production.
        (new RolePermissionSeeder())->run();
        config(['field.uploads.disk' => 'evidence']);   // never a real bucket, whatever .env says
        Storage::fake('evidence');
    }

    protected function api(string $method, string $uri, array $data = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        $url = '/api/v1/'.ltrim($uri, '/');
        if (strtoupper($method) === 'GET' && $data !== []) {
            // GET parameters belong in the query string; json() would put them in the body, where $request->query() cannot see them.
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($data);
            $data = [];
        }

        return $this->json($method, $url, $data, $headers);
    }
}
