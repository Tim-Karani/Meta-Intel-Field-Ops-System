<?php

namespace Tests\Security;

use App\Enums\Role;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

class PrivilegeEscalationTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    /** Every non-admin role is refused on every admin-only endpoint. */
    public function test_admin_endpoints_refuse_everyone_else(): void
    {
        $endpoints = [
            ['GET', 'system/health'], ['GET', 'system/settings'], ['PUT', 'system/settings'], ['PUT', 'system/gps-rules'], ['GET', 'security-events'],
            ['POST', 'users'], ['PATCH', 'users/'.$this->s->agent->id], ['POST', 'users/'.$this->s->agent->id.'/disable'],
        ];
        foreach ([$this->s->ops, $this->s->manager, $this->s->supervisor, $this->s->analyst, $this->s->agent, $this->s->clientAUser] as $user) {
            $this->s->actingAs($user);
            foreach ($endpoints as [$method, $uri]) {
                $this->api($method, $uri, ['name' => 'x'])->assertForbidden();
            }
        }
    }

    public function test_agents_cannot_review_approve_manage_or_report(): void
    {
        $this->s->actingAs($this->s->agent);
        $id = $this->s->assignmentA->id;
        $this->api('POST', 'visits/'.\Illuminate\Support\Str::uuid().'/approve')->assertForbidden();
        $this->api('POST', 'campaigns', ['client_id' => $this->s->clientA->id, 'code' => 'X', 'name' => 'x'])->assertForbidden();
        $this->api('POST', 'assignments', ['campaign_id' => $this->s->campaignA->id])->assertForbidden();
        $this->api('POST', "assignments/$id/cancel", ['reason' => 'x'])->assertForbidden();
        $this->api('POST', "assignments/$id/reassign", ['agent_id' => $this->s->otherAgent->id])->assertForbidden();
        $this->api('GET', 'reports/sales')->assertForbidden();
        $this->api('GET', 'users')->assertForbidden();
        $this->api('GET', 'audit-logs')->assertForbidden();
    }

    public function test_analysts_are_read_only(): void
    {
        $this->s->campaignA->users()->attach($this->s->analyst->id);
        $this->s->actingAs($this->s->analyst);
        $this->api('GET', 'campaigns')->assertOk();
        $this->api('GET', 'reports/campaign_performance')->assertOk();
        $this->api('PATCH', "campaigns/{$this->s->campaignA->id}", ['name' => 'x'])->assertForbidden();
        $this->api('POST', 'clients', ['name' => 'x'])->assertForbidden();
        $this->api('POST', 'assignments', ['campaign_id' => $this->s->campaignA->id])->assertForbidden();
        $this->api('POST', 'issues', ['title' => 'x'])->assertForbidden();
    }

    public function test_mass_assignment_cannot_change_protected_fields(): void
    {
        $this->s->actingAs($this->s->admin);
        $before = $this->s->agent->password;
        $this->api('PATCH', 'users/'.$this->s->agent->id, ['name' => 'Renamed', 'is_admin' => true, 'failed_logins' => 99, 'locked_until' => now()->addYear()->toIso8601String(), 'remember_token' => 'x'])->assertOk();
        $fresh = $this->s->agent->refresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame(0, (int) $fresh->failed_logins);
        $this->assertNull($fresh->locked_until);
        $this->assertSame($before, $fresh->password);

        // Campaign status and creator cannot be forced through create/update.
        $this->s->actingAs($this->s->ops);
        $c = $this->api('POST', 'campaigns', ['client_id' => $this->s->clientA->id, 'code' => 'MA-1', 'name' => 'n', 'status' => 'completed', 'created_by' => $this->s->admin->id, 'id' => (string) \Illuminate\Support\Str::uuid()])->assertCreated()->json('data');
        $this->assertSame('draft', $c['status']);
        $this->assertSame($this->s->ops->id, \App\Models\Campaign::query()->find($c['id'])->created_by);
        $this->api('PATCH', "campaigns/{$c['id']}", ['status' => 'archived'])->assertOk();
        $this->assertSame('draft', \App\Models\Campaign::query()->find($c['id'])->status->value);
    }

    public function test_users_cannot_change_their_own_role_or_disable_themselves(): void
    {
        $this->s->actingAs($this->s->admin);
        $this->api('PATCH', 'users/'.$this->s->admin->id, ['role' => Role::FieldAgent->value])->assertStatus(422)->assertJsonPath('code', 'self_role_change');
        $this->api('POST', 'users/'.$this->s->admin->id.'/disable')->assertStatus(422)->assertJsonPath('code', 'self_disable');
        $this->assertTrue($this->s->admin->refresh()->hasRole(Role::SystemAdmin));
    }

    public function test_users_are_never_deleted_and_roles_are_read_only(): void
    {
        $this->s->actingAs($this->s->admin);
        $this->api('DELETE', 'users/'.$this->s->agent->id)->assertStatus(405);
        $this->api('POST', 'roles', ['slug' => 'root'])->assertStatus(405);
        $this->api('PUT', 'roles/1', [])->assertStatus(404);
    }

    public function test_permissions_are_derived_from_the_database_not_the_token(): void
    {
        $this->s->actingAs($this->s->manager);
        $this->api('POST', 'activities', ['campaign_id' => $this->s->campaignA->id, 'name' => 'ok'])->assertCreated();

        \Illuminate\Support\Facades\DB::table('role_permissions')
            ->where('permission_id', \Illuminate\Support\Facades\DB::table('permissions')->where('slug', 'activities.create')->value('id'))
            ->where('role_id', \Illuminate\Support\Facades\DB::table('roles')->where('slug', 'campaign_manager')->value('id'))->delete();

        $fresh = \App\Models\User::query()->find($this->s->manager->id);   // new instance => no per-instance cache
        $this->s->actingAs($fresh);
        $this->api('POST', 'activities', ['campaign_id' => $this->s->campaignA->id, 'name' => 'blocked'])->assertForbidden();
    }

    public function test_denied_attempts_are_recorded_as_security_events(): void
    {
        $this->s->actingAs($this->s->agent);
        $this->api('GET', 'users')->assertForbidden();
        $this->assertDatabaseHas('security_events', ['type' => 'permission_denied', 'user_id' => $this->s->agent->id]);
    }

    public function test_every_registered_route_is_authenticated_except_the_public_ones(): void
    {
        $public = ['api/v1/health', 'api/v1/auth/login', 'api/v1/auth/forgot-password', 'api/v1/auth/reset-password'];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (!str_starts_with($uri, 'api/v1/') || in_array($uri, $public, true)) {
                continue;
            }
            $this->assertContains('auth:sanctum', $route->gatherMiddleware(), "$uri must require authentication");
            $this->assertContains('active', $route->gatherMiddleware(), "$uri must reject inactive users");
        }
    }

    public function test_every_non_trivial_route_declares_a_permission_gate(): void
    {
        $open = ['api/v1/auth/me', 'api/v1/auth/refresh', 'api/v1/auth/logout', 'api/v1/auth/change-password', 'api/v1/lookups/enums', 'api/v1/lookups/{entity}',
            'api/v1/health', 'api/v1/auth/login', 'api/v1/auth/forgot-password', 'api/v1/auth/reset-password'];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (!str_starts_with($uri, 'api/v1/') || in_array($uri, $open, true)) {
                continue;
            }
            $gates = array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));
            $this->assertNotEmpty($gates, "$uri has no permission middleware");
        }
    }
}
