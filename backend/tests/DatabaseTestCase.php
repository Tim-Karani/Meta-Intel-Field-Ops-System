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
        $this->seed(RolePermissionSeeder::class);
        Storage::fake(config('field.uploads.disk'));
    }

    protected function api(string $method, string $uri, array $data = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->json($method, '/api/v1/'.ltrim($uri, '/'), $data, $headers);
    }
}
